<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Sql\AggregateSqlEmitter;
use Vusys\NestedSet\Aggregates\Sql\SqliteBitwiseAggregates;
use Vusys\NestedSet\Contracts\AggregateDefinitionContract;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Query\Aggregates\Maintenance\AggregateValueComparator;
use Vusys\NestedSet\Query\Aggregates\Read\AggregateSqlFragments;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Builds aggregate-related SQL for the fresh read path.
 *
 * Two entry points:
 *  - {@see applyFreshSelects()} — adds correlated-subquery SELECT
 *    columns to an existing {@see TreeQueryBuilder} so an entire result
 *    set returns freshly-computed aggregates alongside stored ones.
 *  - {@see scalar()} — runs a single-row subquery for one node's value,
 *    used by `$model->freshAggregate('col')`.
 *
 * Phase B: read paths only. Maintenance writes land in later phases via
 * different methods on this class.
 */
final class TreeAggregateBuilder
{
    /**
     * Adds one correlated-subquery SELECT per requested aggregate to
     * the underlying query builder.
     *
     * `$request` shapes:
     *  - null / empty array → every user-facing declared aggregate.
     *  - list<string>       → declared aggregate column names to fetch.
     *  - array<string,Aggregate> → ad-hoc aggregates keyed by alias.
     *  - mixed forms of the above are accepted in one call.
     *
     * @param  TreeQueryBuilder<Model>  $builder
     * @param  array<int|string, string|Aggregate>|null  $request
     */
    public static function applyFreshSelects(TreeQueryBuilder $builder, ?array $request = null): void
    {
        $model = $builder->getModel();

        if (! $model instanceof HasNestedSet) {
            throw new AggregateConfigurationException(sprintf(
                '%s does not implement HasNestedSet — withFreshAggregates() is not applicable.',
                $model::class,
            ));
        }

        $resolved = self::resolveRequest($model, $request);

        // Median / Percentile are read-only via withFreshAggregates() only.
        // They require backend-specific SQL (native PERCENTILE_CONT on PG,
        // window-function subquery on MySQL / MariaDB / SQLite) that cannot
        // be embedded inside a LATERAL or derived-table GROUP BY. Pre-extract
        // them so the normal three routing paths see only the maintainable
        // aggregate kinds, and handle quantiles as individual correlated
        // subqueries regardless of which backend we're on.
        ['quantiles' => $quantiles, 'rest' => $resolved] = self::splitQuantiles($resolved);

        if ($resolved === [] && $quantiles === []) {
            return;
        }

        SqliteBitwiseAggregates::ensureInstalled($model->getConnection());

        $table = $model->getTable();
        $lftCol = $builder->lftColumn();
        $rgtCol = $builder->rgtColumn();
        $scopeCols = NestedSetScopeResolver::columns($model::class);
        $softDeletedColumn = self::softDeletedColumnFor($model);

        // Honour the outer builder's soft-delete intent. If the caller
        // pulled the SoftDeletingScope (withTrashed / onlyTrashed), the
        // descendant subquery includes trashed rows too — otherwise the
        // fresh recompute would silently disagree with the rowset the
        // outer query is actually returning.
        if ($softDeletedColumn !== null && self::outerIncludesTrashed($builder)) {
            $softDeletedColumn = null;
        }

        $connection = $model->getConnection();

        // Route the non-quantile definitions through the optimal shape for
        // each backend. Quantile definitions skip all three paths — see below.
        if ($resolved !== []) {
            if (self::supportsLateral($connection)) {
                self::applyLateralFreshSelects($connection, $builder, $resolved, $table, $lftCol, $rgtCol, $scopeCols, $softDeletedColumn);
            } elseif (AggregateSqlFragments::isMariaDb($connection)) {
                self::applyMariaDbDerivedFreshSelects($connection, $builder, $resolved, $table, $lftCol, $rgtCol, $scopeCols, $softDeletedColumn);
            } else {
                // Fallback: K correlated sub-queries (one per declared aggregate).
                // SQLite stays on this path — it parses LATERAL but the planner
                // doesn't pick a meaningfully different plan, and the correlated
                // shape is already fast there.
                //
                // Leaf fast-path applies here too — and pays off most clearly on
                // this backend because every correlated subquery is a per-row
                // evaluation. Wrapping in CASE makes the subquery dead code on
                // leaf rows; SQLite (and the others) skip dead branches in CASE,
                // so on a wideShallow shape only the root pays the subquery cost.
                foreach ($resolved as $alias => $definition) {
                    $sql = self::buildCorrelatedSubquery($connection, $table, $lftCol, $rgtCol, $scopeCols, $definition, $softDeletedColumn);
                    $cased = AggregateSqlFragments::wrapLeafFastPath(
                        $definition,
                        "{$table}.",
                        $lftCol,
                        $rgtCol,
                        "({$sql})",
                        $softDeletedColumn,
                        $connection,
                    );

                    $builder->addSelect(['*', new TreeExpression("{$cased} as {$alias}")]);
                }
            }
        }

        // Quantile definitions always use individual correlated subqueries,
        // regardless of backend. On PG they emit PERCENTILE_CONT; on all
        // other backends they emit the window-function interpolation subquery.
        // The leaf fast-path still applies: a single-element subtree's quantile
        // is just the source value itself.
        foreach ($quantiles as $alias => $definition) {
            $sql = self::buildQuantileSubquery($connection, $table, $lftCol, $rgtCol, $scopeCols, $definition, $softDeletedColumn);
            $cased = AggregateSqlFragments::wrapLeafFastPath(
                $definition,
                "{$table}.",
                $lftCol,
                $rgtCol,
                "({$sql})",
                $softDeletedColumn,
                $connection,
            );

            $builder->addSelect(['*', new TreeExpression("{$cased} as {$alias}")]);
        }
    }

    /**
     * MariaDB-only fresh-read shape. MariaDB rejects the SQL `LATERAL`
     * keyword so it can't use {@see applyLateralFreshSelects()}; the
     * correlated-subquery fallback scales with N×K and becomes the
     * dominant cost on larger result sets (~45 s for 5 aggregates ×
     * N=10K rows on CI runners).
     *
     * Solution: a derived JOIN+GROUP-BY whose inner `o` is filtered to
     * the user's outer id-set via a cloned copy of their WHERE state.
     * The derived computes every aggregate in one inner-subtree scan
     * per outer row that the user will actually fetch — small filtered
     * queries pay only the cost of their selected rows; large queries
     * pay one full-table inner pass instead of K of them.
     *
     * The derived shape on its own is ~4× faster than the correlated
     * fallback. MariaDB's planner then converts the derived into a
     * LATERAL DERIVED via its `split_materialized` optimisation — that
     * gives back most of the win because the derived gets re-executed
     * per outer row instead of materialised once. We disable
     * `split_materialized` for this single statement by routing the
     * compiled SQL through {@see TreeBaseQueryBuilder::runSelect()},
     * which prepends `SET STATEMENT optimizer_switch=…` when the flag
     * below is set. (No session state is touched.)
     *
     * Measured at N=10K on a balancedFanout fanout=10 tree:
     *   correlated (previous default):                 44,269 ms
     *   derived + o-filter, no SET STATEMENT:          10,316 ms  (4.3× faster)
     *   derived + o-filter + split_materialized=off:    3,762 ms  (11.8× faster)
     *
     * @param  TreeQueryBuilder<Model>  $builder
     * @param  array<string, AggregateDefinition>  $resolved
     * @param  list<string>  $scopeCols
     */
    private static function applyMariaDbDerivedFreshSelects(
        Connection $connection,
        TreeQueryBuilder $builder,
        array $resolved,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scopeCols,
        ?string $softDeletedColumn,
    ): void {
        // Snapshot the user's current WHERE state into a clone, project
        // it down to just the primary key so it can be embedded as
        // `o.id IN (cloned)`. Doing this *before* we add joins/selects
        // of our own keeps the clone faithful to the user's intent.
        $modelKey = $builder->getModel()->getKeyName();
        $userIdsQuery = clone $builder->getQuery();
        $userIdsQuery->columns = ["{$table}.{$modelKey}"];
        $userIdsSql = $userIdsQuery->toSql();
        $userIdsBindings = $userIdsQuery->getBindings();

        // Coax MariaDB's planner away from re-lateralising the derived
        // table we are about to build. See this method's docblock and
        // {@see TreeBaseQueryBuilder::runSelect()}.
        $queryBuilder = $builder->getQuery();
        if ($queryBuilder instanceof TreeBaseQueryBuilder) {
            $queryBuilder->withMariaDbSplitMaterializedOff();
        }

        $byInclusivity = ['inclusive' => [], 'exclusive' => []];
        foreach ($resolved as $alias => $definition) {
            $byInclusivity[$definition->inclusive ? 'inclusive' : 'exclusive'][$alias] = $definition;
        }

        $derivedIndex = 0;

        foreach ($byInclusivity as $mode => $group) {
            if ($group === []) {
                continue;
            }

            $derivedIndex++;
            $derivedAlias = "vusys_fresh_derived_{$derivedIndex}";

            $rawFilterContext = AggregateSqlFragments::hasRawFilter($group);
            $outer = AggregateSqlFragments::outerFromFragment(
                table: $table,
                lftCol: $lftCol,
                rgtCol: $rgtCol,
                scopeCols: $scopeCols,
                rawFilterPresent: $rawFilterContext,
                outerAlias: 'o',
                idCol: $modelKey,
            );

            $aggSelects = ["{$outer['outerId']} AS outer_id"];
            foreach ($group as $colAlias => $definition) {
                $expr = AggregateSqlFragments::aggregateExpressionInJoinedContext(
                    $definition,
                    innerQualifier: 'd.',
                    outerAlias: 'o',
                    table: $table,
                    lftCol: $lftCol,
                    rgtCol: $rgtCol,
                    scopeCols: $scopeCols,
                    rawFilterContext: $rawFilterContext,
                    connection: $connection,
                );
                $aggSelects[] = "{$expr} AS {$colAlias}";
            }

            $boundsClause = $mode === 'inclusive'
                ? "d.{$lftCol} >= {$outer['outerLft']} AND d.{$lftCol} <= {$outer['outerRgt']}"
                : "d.{$lftCol} > {$outer['outerLft']} AND d.{$lftCol} < {$outer['outerRgt']}";

            $scopeClause = '';
            foreach ($outer['outerScope'] as $col => $outerRef) {
                $scopeClause .= " AND d.{$col} = {$outerRef}";
            }

            $softClause = $softDeletedColumn === null
                ? ''
                : " AND d.{$softDeletedColumn} IS NULL";

            // Leaf fast-path: exclude leaves from the materialised derived
            // entirely — the outer SELECT picks an inline value for them
            // via {@see wrapLeafFastPath()}. On a wideShallow shape this
            // cuts the inner aggregation's input from N to 1 (only the
            // root has descendants).
            $innerSql = 'SELECT '.implode(', ', $aggSelects)
                ." FROM {$outer['from']}"
                ." INNER JOIN {$table} d ON {$boundsClause}{$scopeClause}{$softClause}"
                ." WHERE {$outer['outerId']} IN ({$userIdsSql})"
                ." AND {$outer['outerRgt']} > {$outer['outerLft']} + 1"
                ." GROUP BY {$outer['outerId']}";

            $derivedExpr = new TreeExpression("({$innerSql}) as {$derivedAlias}");
            $builder->getQuery()->leftJoin(
                $derivedExpr,
                static function ($join) use ($derivedAlias, $table, $modelKey): void {
                    $join->on("{$derivedAlias}.outer_id", '=', "{$table}.{$modelKey}");
                },
            );

            // The inner SQL contains `?` placeholders from the user-ids
            // sub-query. Those bindings sit inside the JOIN clause, so
            // register them on the 'join' position to stay in compile order.
            $builder->getQuery()->addBinding($userIdsBindings, 'join');

            // Two layers of fast-path. (1) The inner derived now excludes
            // leaves, so for leaf outer rows the LEFT JOIN finds no match
            // and would otherwise yield NULL — the outer CASE returns the
            // inline source value for them instead. (2) Non-leaf rows
            // whose inner JOIN matched nothing (exclusive aggregates with
            // an empty subtree, though that can't happen for an
            // *exclusive* aggregate on a non-leaf in a well-formed tree)
            // still get the COALESCE-around-NULL the original path used.
            // We thread both via wrapLeafFastPath() — the existing
            // COALESCE shim becomes the JOIN-side of the CASE.
            $columns = ['*'];
            foreach ($group as $colAlias => $definition) {
                $joinExpr = match ($definition->function) {
                    AggregateFunction::Sum,
                    AggregateFunction::Count => "COALESCE({$derivedAlias}.{$colAlias}, 0)",
                    default => "{$derivedAlias}.{$colAlias}",
                };
                $cased = AggregateSqlFragments::wrapLeafFastPath($definition, "{$table}.", $lftCol, $rgtCol, $joinExpr, $softDeletedColumn, $connection);
                $columns[] = new TreeExpression("{$cased} as {$colAlias}");
            }
            $builder->addSelect($columns);
        }
    }

    /**
     * LATERAL-JOIN shape: one `LEFT JOIN LATERAL (… GROUP-less SELECT …) ON TRUE`
     * per inclusivity group. The single sub-query computes every aggregate
     * for that inclusivity in one inner-subtree scan, instead of K separate
     * correlated sub-queries that each rescan.
     *
     * For N result rows × K aggregates this drops inner scans from N×K to N
     * — pays off most on backends where each scan is heavy (MySQL: ~80s for
     * 5 aggregates at N=10K with the correlated shape).
     *
     * @param  TreeQueryBuilder<Model>  $builder
     * @param  array<string, AggregateDefinition>  $resolved
     * @param  list<string>  $scopeCols
     */
    private static function applyLateralFreshSelects(
        Connection $connection,
        TreeQueryBuilder $builder,
        array $resolved,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scopeCols,
        ?string $softDeletedColumn,
    ): void {
        $byInclusivity = ['inclusive' => [], 'exclusive' => []];
        foreach ($resolved as $alias => $definition) {
            $byInclusivity[$definition->inclusive ? 'inclusive' : 'exclusive'][$alias] = $definition;
        }

        $query = $builder->getQuery();
        $lateralIndex = 0;

        foreach ($byInclusivity as $mode => $group) {
            if ($group === []) {
                continue;
            }

            $lateralIndex++;
            $lateralAlias = "vusys_fresh_lat_{$lateralIndex}";

            $aggSelects = [];
            foreach ($group as $colAlias => $definition) {
                $expr = AggregateSqlFragments::aggregateExpression($definition, qualifier: 'd.', connection: $connection);
                $aggSelects[] = "{$expr} AS {$colAlias}";
            }

            $boundsClause = $mode === 'inclusive'
                ? "d.{$lftCol} >= {$table}.{$lftCol} AND d.{$rgtCol} <= {$table}.{$rgtCol}"
                : "d.{$lftCol} > {$table}.{$lftCol} AND d.{$rgtCol} < {$table}.{$rgtCol}";

            $scopeClause = '';
            foreach ($scopeCols as $col) {
                $scopeClause .= " AND d.{$col} = {$table}.{$col}";
            }

            $softClause = $softDeletedColumn === null
                ? ''
                : " AND d.{$softDeletedColumn} IS NULL";

            $innerSql = 'SELECT '.implode(', ', $aggSelects)
                ." FROM {$table} d"
                ." WHERE {$boundsClause}{$scopeClause}{$softClause}";

            // Leaf fast-path: move the leaf check onto the LATERAL's ON
            // clause so backends that respect "ON FALSE skip the LATERAL"
            // can prune the inner aggregation entirely for leaf outer
            // rows. PG and MySQL 8 both honour this — they evaluate the
            // ON predicate before the LATERAL body. Inside the LATERAL's
            // own WHERE the same predicate didn't prune on MySQL (the
            // optimiser kept running the empty aggregation per outer row).
            //
            // For leaves the outer SELECT picks an inline value computed
            // from the source column directly — see
            // {@see leafInlineExpression()}.
            $lateralExpr = new TreeExpression("LATERAL ({$innerSql}) as {$lateralAlias}");
            $onClause = "{$table}.{$rgtCol} > {$table}.{$lftCol} + 1";
            $query->leftJoin($lateralExpr, static function ($join) use ($onClause): void {
                $join->whereRaw($onClause);
            });

            $columns = ['*'];
            foreach ($group as $colAlias => $definition) {
                $joinExpr = "{$lateralAlias}.{$colAlias}";
                $cased = AggregateSqlFragments::wrapLeafFastPath($definition, "{$table}.", $lftCol, $rgtCol, $joinExpr, $softDeletedColumn, $connection);
                $columns[] = new TreeExpression("{$cased} as {$colAlias}");
            }
            $builder->addSelect($columns);
        }
    }

    /**
     * Returns true on backends that support the SQL `LEFT JOIN LATERAL`
     * keyword:
     *   - PostgreSQL: all supported versions
     *   - MySQL 8.0.14+
     *
     * MariaDB shows "LATERAL DERIVED" in EXPLAIN output but that's the
     * planner's internal split_materialized optimisation — the SQL
     * keyword `LATERAL` is rejected as a syntax error. MariaDB stays on
     * the correlated-subquery fallback.
     *
     * SQLite parses but doesn't optimise LATERAL meaningfully, and the
     * correlated shape is already fast on its in-memory engine; it stays
     * on the fallback path too.
     */
    private static function supportsLateral(ConnectionInterface $connection): bool
    {
        if (! $connection instanceof Connection) {
            return false;
        }

        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            return true;
        }

        if ($driver !== 'mysql') {
            return false;
        }

        try {
            $version = $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable) {
            return false;
        }

        if (! is_string($version)) {
            return false;
        }

        // MariaDB returns its version string with "MariaDB" in it; the
        // SQL `LATERAL` keyword is not supported there.
        if (stripos($version, 'mariadb') !== false) {
            return false;
        }

        if (! preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $version, $m)) {
            return false;
        }

        $major = (int) $m[1];
        $minor = (int) $m[2];
        $patch = (int) ($m[3] ?? 0);

        // MySQL 8.0.14+ added LATERAL.
        return ($major > 8) || ($major === 8 && ($minor > 0 || $patch >= 14));
    }

    /**
     * Computes the fresh value of one declared aggregate for a single
     * node, by running a non-correlated subquery scoped to the node's
     * bounds.
     */
    public static function scalar(Model&HasNestedSet $node, AggregateDefinition $definition, bool $withTrashed = false): mixed
    {
        SqliteBitwiseAggregates::ensureInstalled($node->getConnection());

        $bounds = $node->getBounds();
        $table = $node->getTable();
        $lftCol = $node->getLftName();
        $rgtCol = $node->getRgtName();
        $connection = $node->getConnection();

        $boundsClause = $definition->inclusive
            ? "{$lftCol} >= ? AND {$rgtCol} <= ?"
            : "{$lftCol} > ? AND {$rgtCol} < ?";

        $bindings = [$bounds->lft, $bounds->rgt];

        $scopeValues = NestedSetScopeResolver::valuesFor($node);
        $scopeClause = '';
        foreach ($scopeValues as $column => $value) {
            $scopeClause .= " AND {$column} = ?";
            $bindings[] = $value;
        }

        $softClause = '';
        $softDeletedColumn = $withTrashed ? null : self::softDeletedColumnFor($node);
        if ($softDeletedColumn !== null) {
            $softClause = " AND {$softDeletedColumn} IS NULL";
        }

        // Quantile kinds on non-PostgreSQL backends need the window-function
        // subquery shape. PostgreSQL uses its native ordered-set aggregate
        // (PERCENTILE_CONT) via the standard aggregateExpression() path below.
        if (($definition->function === AggregateFunction::Median
            || $definition->function === AggregateFunction::Percentile)
            && $connection->getDriverName() !== 'pgsql'
        ) {
            $filterSql = $definition->filter instanceof FilterPredicate
                ? AggregateSqlFragments::filterPredicateSql($connection, $definition->filter, '')
                : null;
            $innerFromClause = "FROM {$table} WHERE {$boundsClause}{$scopeClause}{$softClause}";
            $sql = AggregateSqlEmitter::emitQuantileWindowSubquery($definition, $innerFromClause, '', $filterSql);

            return $connection->scalar($sql, $bindings);
        }

        $aggregateExpr = AggregateSqlFragments::aggregateExpression($definition, qualifier: '', connection: $connection);
        $sql = "SELECT {$aggregateExpr} AS aggregate FROM {$table} WHERE {$boundsClause}{$scopeClause}{$softClause}";

        return $connection->scalar($sql, $bindings);
    }

    /**
     * True when the outer builder has had the SoftDeletingScope removed
     * (via `withTrashed()` or `onlyTrashed()`). In that case the fresh
     * recompute should include trashed descendants so the result
     * matches the rowset the outer query yields.
     *
     * @param  TreeQueryBuilder<Model>  $builder
     */
    private static function outerIncludesTrashed(TreeQueryBuilder $builder): bool
    {
        return in_array(
            SoftDeletingScope::class,
            $builder->removedScopes(),
            true,
        );
    }

    /**
     * Returns the soft-delete column name for models that use Eloquent's
     * SoftDeletes trait, or null otherwise. Used to filter trashed rows
     * out of fresh-aggregate reads so the result reflects the live set.
     */
    private static function softDeletedColumnFor(Model $node): ?string
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($node), true)) {
            return null;
        }

        $column = (new \ReflectionMethod($node, 'getDeletedAtColumn'))->invoke($node);

        return is_string($column) ? $column : null;
    }

    /**
     * Resolves a heterogeneous request array into [alias => definition]
     * pairs. Declared-column-name entries (string values) are looked up
     * in the registry; {@see Aggregate}-instance entries are accepted
     * as ad-hoc declarations, materialised with the array key as the
     * column alias.
     *
     * @param  array<int|string, string|Aggregate>|null  $request
     * @return array<string, AggregateDefinition>
     */
    private static function resolveRequest(Model&HasNestedSet $model, ?array $request): array
    {
        if ($request === null || $request === []) {
            return self::userFacingDeclared($model);
        }

        $resolved = [];

        foreach ($request as $key => $value) {
            if (is_string($value)) {
                $definition = self::findDeclared($model, $value);
                $resolved[$value] = $definition;

                continue;
            }

            if (! is_string($key)) {
                throw new AggregateConfigurationException(
                    'withFreshAggregates(): ad-hoc Aggregate entries must be keyed by a string column alias '
                    .'(e.g. ["subtree_tickets" => Aggregate::sum("tickets")]).',
                );
            }

            $resolved[$key] = $value->into($key);
        }

        return $resolved;
    }

    /**
     * @return array<string, AggregateDefinition>
     */
    private static function userFacingDeclared(Model&HasNestedSet $model): array
    {
        $resolved = [];

        foreach (AggregateRegistry::for($model::class) as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }
            if ($definition->isInternal()) {
                continue;
            }
            $resolved[$definition->column] = $definition;
        }

        return $resolved;
    }

    private static function findDeclared(Model&HasNestedSet $model, string $column): AggregateDefinition
    {
        foreach (AggregateRegistry::for($model::class) as $definition) {
            if ($definition instanceof AggregateDefinition && $definition->column === $column) {
                return $definition;
            }
        }

        throw new AggregateConfigurationException(sprintf(
            '%s has no aggregate column "%s". Declare it via #[NestedSetAggregate(...)] or nestedSetAggregates(), '
            .'or pass an Aggregate instance for an ad-hoc fresh read.',
            $model::class,
            $column,
        ));
    }

    /**
     * Splits a resolved-definitions map into quantile kinds (Median /
     * Percentile) and everything else. Quantile definitions are routed
     * through individual correlated subqueries in every backend; the
     * remaining definitions go through the normal LATERAL / MariaDB
     * derived / correlated routing.
     *
     * @param  array<string, AggregateDefinition>  $resolved
     * @return array{quantiles: array<string, AggregateDefinition>, rest: array<string, AggregateDefinition>}
     */
    private static function splitQuantiles(array $resolved): array
    {
        $quantiles = [];
        $rest = [];

        foreach ($resolved as $alias => $definition) {
            if ($definition->function === AggregateFunction::Median
                || $definition->function === AggregateFunction::Percentile
            ) {
                $quantiles[$alias] = $definition;
            } else {
                $rest[$alias] = $definition;
            }
        }

        return ['quantiles' => $quantiles, 'rest' => $rest];
    }

    /**
     * Correlated subquery that computes one quantile value for the outer
     * row's subtree. On PostgreSQL, emits the native ordered-set aggregate
     * `PERCENTILE_CONT(p) WITHIN GROUP (ORDER BY col)`. On MySQL / MariaDB /
     * SQLite, emits the window-function linear-interpolation subquery.
     *
     * The returned SQL is meant to be wrapped in parentheses and embedded
     * as a scalar expression in the outer SELECT.
     *
     * @param  list<string>  $scopeCols
     */
    private static function buildQuantileSubquery(
        ConnectionInterface $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scopeCols,
        AggregateDefinition $definition,
        ?string $softDeletedColumn,
    ): string {
        $boundsClause = $definition->inclusive
            ? "d.{$lftCol} >= {$table}.{$lftCol} AND d.{$rgtCol} <= {$table}.{$rgtCol}"
            : "d.{$lftCol} > {$table}.{$lftCol} AND d.{$rgtCol} < {$table}.{$rgtCol}";

        $scopeClause = '';
        foreach ($scopeCols as $col) {
            $scopeClause .= " AND d.{$col} = {$table}.{$col}";
        }

        $softClause = $softDeletedColumn === null
            ? ''
            : " AND d.{$softDeletedColumn} IS NULL";

        $filterSql = null;
        if ($definition->filter instanceof FilterPredicate
            && $connection instanceof Connection
        ) {
            $filterSql = AggregateSqlFragments::filterPredicateSql($connection, $definition->filter, 'd.');
        }

        if ($connection instanceof Connection && $connection->getDriverName() === 'pgsql') {
            $aggregateExpr = AggregateSqlEmitter::emitQuantileNativeExpression($definition, 'd.', $filterSql);

            return "SELECT {$aggregateExpr} FROM {$table} d WHERE {$boundsClause}{$scopeClause}{$softClause}";
        }

        $innerFromClause = "FROM {$table} d WHERE {$boundsClause}{$scopeClause}{$softClause}";

        if (AggregateSqlFragments::isMariaDb($connection)) {
            return AggregateSqlEmitter::emitQuantileJsonExpression($definition, $innerFromClause, 'd.', $filterSql);
        }

        return AggregateSqlEmitter::emitQuantileWindowSubquery($definition, $innerFromClause, 'd.', $filterSql);
    }

    /**
     * @param  list<string>  $scopeCols
     */
    private static function buildCorrelatedSubquery(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scopeCols,
        AggregateDefinition $definition,
        ?string $softDeletedColumn,
    ): string {
        $aggregateExpr = AggregateSqlFragments::aggregateExpression($definition, qualifier: 'd.', connection: $connection);

        $boundsClause = $definition->inclusive
            ? "d.{$lftCol} >= {$table}.{$lftCol} AND d.{$rgtCol} <= {$table}.{$rgtCol}"
            : "d.{$lftCol} > {$table}.{$lftCol} AND d.{$rgtCol} < {$table}.{$rgtCol}";

        $scopeClause = '';
        foreach ($scopeCols as $col) {
            $scopeClause .= " AND d.{$col} = {$table}.{$col}";
        }

        $softClause = $softDeletedColumn === null
            ? ''
            : " AND d.{$softDeletedColumn} IS NULL";

        return "SELECT {$aggregateExpr} FROM {$table} d WHERE {$boundsClause}{$scopeClause}{$softClause}";
    }

    // ----------------------------------------------------------------
    // Phase H: integrity tooling
    // ----------------------------------------------------------------

    /**
     * Returns per-column counts of rows where the stored aggregate
     * value disagrees with the freshly-computed value over the source.
     * Internal AVG companions are excluded from the report — they're a
     * maintenance implementation detail, not part of the user-facing
     * surface — but `fixAggregates()` still repairs them when present.
     *
     * @param  array<string, mixed>  $scope
     * @param  list<AggregateDefinitionContract>  $definitions
     * @return array<string, int>
     */
    public static function aggregateErrors(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scope,
        array $definitions,
        int|string|null $rootId = null,
        ?string $parentIdCol = null,
        ?string $depthCol = null,
        ?string $softDeletedColumn = null,
        string $idCol = 'id',
    ): array {
        $userFacing = [];
        foreach ($definitions as $def) {
            if ($def instanceof AggregateDefinition && ! $def->isInternal()) {
                $userFacing[] = $def;
            }
        }

        if ($userFacing === []) {
            return [];
        }

        SqliteBitwiseAggregates::ensureInstalled($connection);

        $rows = self::selectStoredAndComputed(
            connection: $connection,
            table: $table,
            lftCol: $lftCol,
            rgtCol: $rgtCol,
            scope: $scope,
            definitions: $userFacing,
            rootId: $rootId,
            parentIdCol: $parentIdCol,
            depthCol: $depthCol,
            softDeletedColumn: $softDeletedColumn,
            idCol: $idCol,
        );

        $errors = [];

        foreach ($userFacing as $definition) {
            $errors[$definition->column] = 0;
        }

        foreach ($rows as $row) {
            foreach ($userFacing as $definition) {
                $stored = $row[self::storedAlias($definition->column)] ?? null;
                $computed = $row[self::computedAlias($definition->column)] ?? null;

                if (! AggregateValueComparator::aggregateValuesEqual($definition, $stored, $computed)) {
                    $errors[$definition->column]++;
                }
            }
        }

        return $errors;
    }

    /**
     * Repairs every aggregate column over the scope (or rooted subtree)
     * by overwriting stored values with the freshly-computed value from
     * the source column. Operates on all definitions including internal
     * AVG companions — drift in either user-facing or internal columns
     * gets corrected.
     *
     * @param  array<string, mixed>  $scope
     * @param  list<AggregateDefinitionContract>  $definitions
     * @param  list<int|string>|null  $outerIds  When non-null, restricts the
     *                                           repair to this subset of outer
     *                                           rows. Used by the chunked /
     *                                           self-redispatching queue job.
     */
    public static function fixAggregates(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scope,
        array $definitions,
        int|string|null $rootId = null,
        ?array $outerIds = null,
        ?string $parentIdCol = null,
        ?string $depthCol = null,
        ?string $softDeletedColumn = null,
        string $idCol = 'id',
    ): AggregateFixResult {
        $sqlDefinitions = [];
        foreach ($definitions as $def) {
            if ($def instanceof AggregateDefinition) {
                $sqlDefinitions[] = $def;
            }
        }

        if ($sqlDefinitions === []) {
            return new AggregateFixResult(totalRowsUpdated: 0, perColumn: []);
        }

        SqliteBitwiseAggregates::ensureInstalled($connection);

        // An empty (but non-null) outerIds list means "this chunk has
        // no rows" — short-circuit, otherwise the SQL becomes `id IN ()`
        // which is a syntax error.
        if ($outerIds !== null && $outerIds === []) {
            $perColumn = [];
            foreach ($sqlDefinitions as $definition) {
                if (! $definition->isInternal()) {
                    $perColumn[$definition->column] = 0;
                }
            }

            return new AggregateFixResult(totalRowsUpdated: 0, perColumn: $perColumn);
        }

        $rows = self::selectStoredAndComputed(
            connection: $connection,
            table: $table,
            lftCol: $lftCol,
            rgtCol: $rgtCol,
            scope: $scope,
            definitions: $sqlDefinitions,
            rootId: $rootId,
            outerIds: $outerIds,
            parentIdCol: $parentIdCol,
            depthCol: $depthCol,
            softDeletedColumn: $softDeletedColumn,
            idCol: $idCol,
        );

        $perColumn = [];
        foreach ($sqlDefinitions as $definition) {
            if (! $definition->isInternal()) {
                $perColumn[$definition->column] = 0;
            }
        }

        $toUpdate = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (! is_int($id) && ! is_string($id)) {
                continue;
            }

            $updates = [];

            foreach ($sqlDefinitions as $definition) {
                $stored = $row[self::storedAlias($definition->column)] ?? null;
                $computed = $row[self::computedAlias($definition->column)] ?? null;

                if (! AggregateValueComparator::aggregateValuesEqual($definition, $stored, $computed)) {
                    $updates[$definition->column] = $computed;
                    if (! $definition->isInternal()) {
                        $perColumn[$definition->column] = ($perColumn[$definition->column] ?? 0) + 1;
                    }
                }
            }

            if ($updates !== []) {
                $toUpdate[] = ['id' => $id, 'updates' => $updates];
            }
        }

        $totalRowsUpdated = self::bulkWriteRecomputedValues(
            connection: $connection,
            table: $table,
            toUpdate: $toUpdate,
            idCol: $idCol,
        );

        return new AggregateFixResult(
            totalRowsUpdated: $totalRowsUpdated,
            perColumn: $perColumn,
        );
    }

    /**
     * Writes a set of per-row column updates as chunked bulk
     * `UPDATE … SET col = CASE id WHEN … END WHERE id IN (…)`
     * statements. One statement per chunk, one CASE expression per
     * column being updated in the chunk. Replaces what used to be
     * N per-row UPDATE round-trips — for a fully-drifted 10K-row
     * tree, that's 10K → ~20 round-trips.
     *
     * NULL values are emitted as literal SQL NULL in the WHEN-THEN
     * branch (CASE values are positional, not parameterised, since
     * Laravel's `update()` doesn't accept CASE syntax in the SET).
     *
     * @param  list<array{id: int|string, updates: array<string, mixed>}>  $toUpdate
     * @param  int<1, max>  $chunkSize
     */
    private static function bulkWriteRecomputedValues(
        Connection $connection,
        string $table,
        array $toUpdate,
        int $chunkSize = 500,
        string $idCol = 'id',
    ): int {
        if ($toUpdate === []) {
            return 0;
        }

        $touched = 0;

        foreach (array_chunk($toUpdate, $chunkSize) as $chunk) {
            // Collect every column that appears in at least one row's
            // updates — the SET clause needs a CASE expression per
            // such column.
            $columnsInChunk = [];
            foreach ($chunk as $row) {
                foreach (array_keys($row['updates']) as $col) {
                    $columnsInChunk[$col] = true;
                }
            }
            $columnsInChunk = array_keys($columnsInChunk);

            if ($columnsInChunk === []) {
                continue;
            }

            $sets = [];
            $bindings = [];

            foreach ($columnsInChunk as $col) {
                $caseSql = "CASE {$idCol}";
                foreach ($chunk as $row) {
                    if (! array_key_exists($col, $row['updates'])) {
                        continue;
                    }

                    $caseSql .= ' WHEN ? THEN ?';
                    $bindings[] = $row['id'];
                    $bindings[] = $row['updates'][$col];
                }
                $caseSql .= " ELSE {$col} END";
                $sets[] = "{$col} = ({$caseSql})";
            }

            $ids = array_column($chunk, 'id');
            $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
            foreach ($ids as $id) {
                $bindings[] = $id;
            }

            $sql = "UPDATE {$table} SET ".implode(', ', $sets)
                ." WHERE {$idCol} IN ({$idPlaceholders})";

            $touched += $connection->update($sql, $bindings);
        }

        return $touched;
    }

    /**
     * Returns each row's id, every aggregate column's stored value,
     * and the freshly-computed value. Powers both
     * {@see aggregateErrors()} and {@see fixAggregates()}.
     *
     * Implementation: groups definitions by inclusivity and issues
     * one self-JOIN + GROUP BY per inclusivity group, then merges
     * results by id. For N=10K with five inclusive aggregates this
     * is a single JOIN producing O(N log N) rows on a balanced
     * tree — orders of magnitude faster than the N × K correlated
     * subqueries the earlier implementation used (which MySQL /
     * MariaDB planners couldn't optimise efficiently).
     *
     * @param  array<string, mixed>  $scope
     * @param  list<AggregateDefinition>  $definitions
     * @param  list<int|string>|null  $outerIds
     * @return list<array<string, mixed>>
     */
    private static function selectStoredAndComputed(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scope,
        array $definitions,
        int|string|null $rootId,
        ?array $outerIds = null,
        ?string $parentIdCol = null,
        ?string $depthCol = null,
        ?string $softDeletedColumn = null,
        string $idCol = 'id',
    ): array {
        if ($definitions === []) {
            return [];
        }

        // Chain-shape fast-path: deep chains hit the slow path's O(N²)
        // per-row subtree aggregation (every ancestor's "subtree" spans
        // the rest of the chain). When the in-scope tree has at most one
        // child per parent we can fold source values from leaf to root
        // in PHP in O(N), skipping the expensive aggregation SQL
        // entirely. The fast-path is opt-out only — chunked paths
        // (`outerIds !== null`) keep the slow path because the chunk's
        // shape is a subset and chain detection there would be unsafe.
        // Filtered definitions also skip — the fold doesn't evaluate
        // filter predicates, so it would silently produce the unfiltered
        // value for every filtered column (regression spotted by the
        // all-no-match parametric provider).
        $anyFiltered = false;
        foreach ($definitions as $definition) {
            if ($definition->filter instanceof FilterPredicate) {
                $anyFiltered = true;
                break;
            }
        }

        $anyRecomputeOnly = false;
        foreach ($definitions as $definition) {
            if (! $definition->function->supportsDelta()
                && $definition->function !== AggregateFunction::Avg
                && $definition->function !== AggregateFunction::Min
                && $definition->function !== AggregateFunction::Max
            ) {
                // The four collection-aggregate kinds (DistinctCount / StringAgg / JsonAgg /
                // JsonObjectAgg) can't fold via the linear chain pass —
                // each ancestor's value depends on a *set* of descendant
                // values, not a per-row delta. Skip the chain-fold fast
                // path entirely for these.
                $anyRecomputeOnly = true;
                break;
            }
        }

        if (
            ! $anyFiltered
            && ! $anyRecomputeOnly
            && $outerIds === null
            && $parentIdCol !== null
            && $depthCol !== null
            && self::isChainShape($connection, $table, $parentIdCol, $lftCol, $rgtCol, $scope, $rootId, $softDeletedColumn, $idCol)
        ) {
            return self::selectStoredAndComputedViaChainFold(
                connection: $connection,
                table: $table,
                parentIdCol: $parentIdCol,
                lftCol: $lftCol,
                rgtCol: $rgtCol,
                depthCol: $depthCol,
                scope: $scope,
                definitions: $definitions,
                rootId: $rootId,
                softDeletedColumn: $softDeletedColumn,
                idCol: $idCol,
            );
        }

        $byInclusivity = ['inclusive' => [], 'exclusive' => []];
        foreach ($definitions as $definition) {
            $byInclusivity[$definition->inclusive ? 'inclusive' : 'exclusive'][] = $definition;
        }

        $combined = [];

        foreach ($byInclusivity as $mode => $group) {
            if ($group === []) {
                continue;
            }

            $rows = self::groupedAggregateQuery(
                connection: $connection,
                table: $table,
                lftCol: $lftCol,
                rgtCol: $rgtCol,
                scope: $scope,
                definitions: $group,
                inclusive: $mode === 'inclusive',
                rootId: $rootId,
                outerIds: $outerIds,
                softDeletedColumn: $softDeletedColumn,
                idCol: $idCol,
            );

            foreach ($rows as $row) {
                $id = $row['id'] ?? null;
                if (! is_int($id) && ! is_string($id)) {
                    continue;
                }
                $combined[$id] = isset($combined[$id])
                    ? [...$combined[$id], ...$row]
                    : $row;
            }
        }

        return array_values($combined);
    }

    /**
     * True when every parent in the in-scope tree has at most one child
     * — i.e. the tree is a pure chain (or empty). Detected via
     * `GROUP BY parent_id HAVING COUNT(*) > 1`, which is cheap: one
     * index scan, returns zero rows iff chain.
     *
     * Multiple roots in the same scope (NULL parent_id repeated) are
     * caught by the same GROUP BY — a forest of roots isn't a chain.
     *
     * @param  array<string, mixed>  $scope
     */
    private static function isChainShape(
        Connection $connection,
        string $table,
        string $parentIdCol,
        string $lftCol,
        string $rgtCol,
        array $scope,
        int|string|null $rootId,
        ?string $softDeletedColumn = null,
        string $idCol = 'id',
    ): bool {
        $sql = "SELECT 1 FROM {$table} WHERE 1 = 1";
        $bindings = [];

        foreach ($scope as $col => $value) {
            $sql .= " AND {$col} = ?";
            $bindings[] = $value;
        }

        if ($softDeletedColumn !== null) {
            $sql .= " AND {$softDeletedColumn} IS NULL";
        }

        if ($rootId !== null) {
            $sql .= " AND {$lftCol} >= (SELECT {$lftCol} FROM {$table} WHERE {$idCol} = ?)";
            $sql .= " AND {$rgtCol} <= (SELECT {$rgtCol} FROM {$table} WHERE {$idCol} = ?)";
            $bindings[] = $rootId;
            $bindings[] = $rootId;
        }

        $sql .= " GROUP BY {$parentIdCol} HAVING COUNT(*) > 1 LIMIT 1";

        return $connection->select($sql, $bindings) === [];
    }

    /**
     * Computes per-row (stored, computed) aggregate values for a tree
     * known to be a chain. Issues one SELECT to fetch each row's
     * source / stored / structural columns, then folds bottom-up in
     * PHP — O(N) total instead of the slow path's O(N²).
     *
     * Returns the same row shape as {@see selectStoredAndComputed()}:
     * `{id, stored_<col>, computed_<col>, ...}` for every declared
     * column, including internal AVG companions.
     *
     * @param  array<string, mixed>  $scope
     * @param  list<AggregateDefinition>  $definitions
     * @return list<array<string, mixed>>
     */
    private static function selectStoredAndComputedViaChainFold(
        Connection $connection,
        string $table,
        string $parentIdCol,
        string $lftCol,
        string $rgtCol,
        string $depthCol,
        array $scope,
        array $definitions,
        int|string|null $rootId,
        ?string $softDeletedColumn = null,
        string $idCol = 'id',
    ): array {
        // Collect every column we need to fetch: source columns (for
        // the fold input), stored aggregate columns (for the diff
        // output), and the structural columns to walk the chain.
        $needed = [$idCol, $parentIdCol, $lftCol, $rgtCol, $depthCol];
        foreach ($definitions as $definition) {
            if ($definition->source !== null) {
                $needed[] = $definition->source;
            }
            if ($definition->weight !== null && $definition->weight !== '') {
                $needed[] = $definition->weight;
            }
            $needed[] = $definition->column;
        }
        $columns = array_values(array_unique($needed));

        $sql = 'SELECT '.implode(', ', $columns)." FROM {$table} WHERE 1 = 1";
        $bindings = [];

        foreach ($scope as $col => $value) {
            $sql .= " AND {$col} = ?";
            $bindings[] = $value;
        }

        if ($softDeletedColumn !== null) {
            $sql .= " AND {$softDeletedColumn} IS NULL";
        }

        if ($rootId !== null) {
            $sql .= " AND {$lftCol} >= (SELECT {$lftCol} FROM {$table} WHERE {$idCol} = ?)";
            $sql .= " AND {$rgtCol} <= (SELECT {$rgtCol} FROM {$table} WHERE {$idCol} = ?)";
            $bindings[] = $rootId;
            $bindings[] = $rootId;
        }

        // Order leaf-first. In a pure chain every depth level has exactly
        // one node, so depth DESC orders the rows along the chain — each
        // row's child is the row processed immediately before it.
        $sql .= " ORDER BY {$depthCol} DESC, {$lftCol} ASC";

        $rawRows = $connection->select($sql, $bindings);

        /** @var list<array<string, mixed>> $rows */
        $rows = array_map(static fn ($r): array => (array) $r, $rawRows);

        $output = [];

        foreach ($definitions as $definition) {
            $accumulator = new ChainFoldAccumulator($definition);

            foreach ($rows as $row) {
                $id = $row[$idCol] ?? null;
                if (! is_int($id) && ! is_string($id)) {
                    continue;
                }

                $sourceValue = $definition->source !== null
                    ? ($row[$definition->source] ?? null)
                    : null;
                $weightValue = $definition->weight !== null && $definition->weight !== ''
                    ? ($row[$definition->weight] ?? null)
                    : null;

                ['previous' => $previousInclusive, 'current' => $currentInclusive]
                    = $accumulator->apply($sourceValue, $weightValue);

                $computedValue = $definition->inclusive ? $currentInclusive : $previousInclusive;

                if (! isset($output[$id])) {
                    $output[$id] = ['id' => $id];
                }
                $output[$id][self::storedAlias($definition->column)] = $row[$definition->column] ?? null;
                $output[$id][self::computedAlias($definition->column)] = $computedValue;
            }
        }

        return array_values($output);
    }

    /**
     * Single SELECT that returns one row per outer node with that node's
     * stored aggregate values alongside the freshly-computed ones.
     *
     * The query is shaped in two stages:
     *
     *   1. A derived sub-query computes the aggregates per outer-id only
     *      — no stored columns are dragged into the GROUP BY. The join
     *      predicate keys on `lft` alone (`i.lft BETWEEN o.lft AND o.rgt`
     *      for inclusive; the strict open interval for exclusive) which
     *      is equivalent to the standard two-column descendant predicate
     *      for any well-formed nested-set tree but lets the planner use
     *      a single-column range scan.
     *
     *   2. The outer query joins the derived table back to the source
     *      table to fetch each row's stored value for comparison.
     *
     * The derived shape exists primarily to coax MySQL / MariaDB's
     * planner into a hash-join + filter + aggregate plan, which it picks
     * for the wrapped sub-query but not for the same logical SQL written
     * as one statement (it instead picks a nested-loop with
     * temporary-table aggregate, ~6× slower at N=10K). PostgreSQL and
     * SQLite are unaffected — their planners pick the same physical plan
     * either way.
     *
     * For exclusive aggregates, leaves have no descendants so the inner
     * INNER JOIN drops them entirely. The outer LEFT JOIN brings them
     * back with all-NULL aggregate columns; SUM and COUNT are wrapped at
     * the outer in `COALESCE(.., 0)` so empty-subtree contributions
     * report 0 — matching the semantics the original single-statement
     * shape produced via SUM(NULL) inside COALESCE.
     *
     * @param  array<string, mixed>  $scope
     * @param  list<AggregateDefinition>  $definitions
     * @param  list<int|string>|null  $outerIds
     * @return list<array<string, mixed>>
     */
    private static function groupedAggregateQuery(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scope,
        array $definitions,
        bool $inclusive,
        int|string|null $rootId,
        ?array $outerIds = null,
        ?string $softDeletedColumn = null,
        string $idCol = 'id',
    ): array {
        $rawFilterContext = AggregateSqlFragments::hasRawFilter($definitions);
        $outer = AggregateSqlFragments::outerFromFragment(
            table: $table,
            lftCol: $lftCol,
            rgtCol: $rgtCol,
            scopeCols: array_keys($scope),
            rawFilterPresent: $rawFilterContext,
            outerAlias: 'o',
            idCol: $idCol,
            softDeletedColumn: $softDeletedColumn,
        );

        $outerSelects = ["outer_a.{$idCol} AS id"];
        $aggSelects = ["{$outer['outerId']} AS outer_id"];

        foreach ($definitions as $definition) {
            $innerExpr = AggregateSqlFragments::aggregateExpressionInJoinedContext(
                $definition,
                innerQualifier: 'i.',
                outerAlias: 'o',
                table: $table,
                lftCol: $lftCol,
                rgtCol: $rgtCol,
                scopeCols: array_keys($scope),
                rawFilterContext: $rawFilterContext,
                connection: $connection,
            );
            $computedAlias = self::computedAlias($definition->column);
            $aggSelects[] = "{$innerExpr} AS {$computedAlias}";

            $outerComputed = match ($definition->function) {
                AggregateFunction::Sum,
                AggregateFunction::Count,
                AggregateFunction::DistinctCount => "COALESCE(agg.{$computedAlias}, 0)",
                default => "agg.{$computedAlias}",
            };
            $outerSelects[] = "{$outerComputed} AS {$computedAlias}";
            $outerSelects[] = "outer_a.{$definition->column} AS ".self::storedAlias($definition->column);
        }

        $joinClause = $inclusive
            ? "i.{$lftCol} >= {$outer['outerLft']} AND i.{$lftCol} <= {$outer['outerRgt']}"
            : "i.{$lftCol} > {$outer['outerLft']} AND i.{$lftCol} < {$outer['outerRgt']}";

        $scopeJoinExtra = '';
        foreach ($outer['outerScope'] as $col => $outerRef) {
            $scopeJoinExtra .= " AND i.{$col} = {$outerRef}";
        }

        $bindings = [];

        $innerWhere = '1 = 1';
        foreach ($scope as $col => $value) {
            $innerWhere .= " AND {$outer['outerScope'][$col]} = ?";
            $bindings[] = $value;
        }
        if ($softDeletedColumn !== null) {
            // Inner descendant rows must skip trashed so the computed
            // aggregate reflects the live set.
            $innerWhere .= " AND i.{$softDeletedColumn} IS NULL";
            // The inner-side outer (alias `o` inside the derived agg
            // table) must skip trashed too, otherwise we'd compute
            // values that will never be JOINed to a live outer_a.
            $innerWhere .= " AND {$outer['outerSoftDeleted']} IS NULL";
        }
        if ($rootId !== null) {
            $innerWhere .= " AND {$outer['outerLft']} >= (SELECT {$lftCol} FROM {$table} WHERE {$idCol} = ?)";
            $innerWhere .= " AND {$outer['outerRgt']} <= (SELECT {$rgtCol} FROM {$table} WHERE {$idCol} = ?)";
            $bindings[] = $rootId;
            $bindings[] = $rootId;
        }

        // Chunked-job path: limit both the inner aggregation and the
        // outer SELECT to the supplied id set so we only do work for
        // rows in this chunk.
        if ($outerIds !== null) {
            $placeholders = implode(', ', array_fill(0, count($outerIds), '?'));
            $innerWhere .= " AND {$outer['outerId']} IN ({$placeholders})";
            foreach ($outerIds as $id) {
                $bindings[] = $id;
            }
        }

        $outerWhere = '1 = 1';
        foreach ($scope as $col => $value) {
            $outerWhere .= " AND outer_a.{$col} = ?";
            $bindings[] = $value;
        }
        if ($softDeletedColumn !== null) {
            // Snapshot semantics: trashed ancestors don't appear in
            // the stored-vs-computed pairing — their stored values are
            // frozen by design and shouldn't be flagged as drift.
            $outerWhere .= " AND outer_a.{$softDeletedColumn} IS NULL";
        }
        if ($rootId !== null) {
            $outerWhere .= " AND outer_a.{$lftCol} >= (SELECT {$lftCol} FROM {$table} WHERE {$idCol} = ?)";
            $outerWhere .= " AND outer_a.{$rgtCol} <= (SELECT {$rgtCol} FROM {$table} WHERE {$idCol} = ?)";
            $bindings[] = $rootId;
            $bindings[] = $rootId;
        }
        if ($outerIds !== null) {
            $placeholders = implode(', ', array_fill(0, count($outerIds), '?'));
            $outerWhere .= " AND outer_a.{$idCol} IN ({$placeholders})";
            foreach ($outerIds as $id) {
                $bindings[] = $id;
            }
        }

        // MySQL's planner picks `Inner hash join (no condition)` for the
        // inner aggregation, which produces a full N×N cartesian product
        // (~100M rows at N=10K) then filters by `lft BETWEEN`. The right
        // plan is a nested-loop with an indexed range scan on i.lft —
        // ~50K rows total. STRAIGHT_JOIN forces o-then-i ordering AND
        // bans the hash-join, both of which the planner needs to reach
        // the index-scan plan. Drops the inner step from 2.4s to 65ms
        // at N=10K balancedFanout (37× faster).
        //
        // MariaDB is unaffected — its derived-table issue is the
        // split_materialized optimisation, handled below. SQLite and PG
        // pick the right plan without help.
        $innerJoinKeyword = AggregateSqlFragments::isMySql($connection) ? 'STRAIGHT_JOIN' : 'INNER JOIN';

        $sql = 'SELECT '.implode(', ', $outerSelects)
            ." FROM {$table} AS outer_a"
            .' LEFT JOIN ('
            .'SELECT '.implode(', ', $aggSelects)
            ." FROM {$outer['from']}"
            ." {$innerJoinKeyword} {$table} AS i ON {$joinClause}{$scopeJoinExtra}"
            ." WHERE {$innerWhere}"
            ." GROUP BY {$outer['outerId']}"
            .") AS agg ON agg.outer_id = outer_a.{$idCol}"
            ." WHERE {$outerWhere}";

        // MariaDB's planner converts our derived table into a LATERAL
        // DERIVED via the `split_materialized` optimization — it
        // re-executes the sub-query once per outer row instead of
        // materializing it once, making the derived shape ~3× slower than
        // the non-derived form. Disable that optimization for this single
        // statement so the derived gets materialized once. MySQL has no
        // `split_materialized` flag (it already materializes once for
        // free), so this only fires on MariaDB.
        if (AggregateSqlFragments::isMariaDb($connection)) {
            $sql = "SET STATEMENT optimizer_switch='split_materialized=off' FOR ".$sql;
        }

        $rows = $connection->select($sql, $bindings);

        $result = [];
        foreach ($rows as $row) {
            $result[] = (array) $row;
        }

        return $result;
    }

    private static function computedAlias(string $column): string
    {
        return 'computed_'.$column;
    }

    private static function storedAlias(string $column): string
    {
        return 'stored_'.$column;
    }
}
