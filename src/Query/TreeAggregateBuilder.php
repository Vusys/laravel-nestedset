<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateDefinitionContract;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Aggregates\FilterPredicate;
use Vusys\NestedSet\Aggregates\FilterPredicateKind;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
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

        if ($resolved === []) {
            return;
        }

        $table = $model->getTable();
        $lftCol = $builder->lftColumn();
        $rgtCol = $builder->rgtColumn();
        $scopeCols = NestedSetScopeResolver::columns($model::class);

        $connection = $builder->getQuery()->getConnection();

        if (self::supportsLateral($connection)) {
            self::applyLateralFreshSelects($builder, $resolved, $table, $lftCol, $rgtCol, $scopeCols);

            return;
        }

        if (self::isMariaDb($connection)) {
            self::applyMariaDbDerivedFreshSelects($builder, $resolved, $table, $lftCol, $rgtCol, $scopeCols);

            return;
        }

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
            $sql = self::buildCorrelatedSubquery($table, $lftCol, $rgtCol, $scopeCols, $definition);
            $cased = self::wrapLeafFastPath(
                $definition,
                "{$table}.",
                $lftCol,
                $rgtCol,
                "({$sql})",
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
        TreeQueryBuilder $builder,
        array $resolved,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scopeCols,
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

            $aggSelects = ["o.{$modelKey} AS outer_id"];
            foreach ($group as $colAlias => $definition) {
                $expr = self::aggregateExpression($definition, qualifier: 'd.');
                $aggSelects[] = "{$expr} AS {$colAlias}";
            }

            $boundsClause = $mode === 'inclusive'
                ? "d.{$lftCol} >= o.{$lftCol} AND d.{$lftCol} <= o.{$rgtCol}"
                : "d.{$lftCol} > o.{$lftCol} AND d.{$lftCol} < o.{$rgtCol}";

            $scopeClause = '';
            foreach ($scopeCols as $col) {
                $scopeClause .= " AND d.{$col} = o.{$col}";
            }

            // Leaf fast-path: exclude leaves from the materialised derived
            // entirely — the outer SELECT picks an inline value for them
            // via {@see wrapLeafFastPath()}. On a wideShallow shape this
            // cuts the inner aggregation's input from N to 1 (only the
            // root has descendants).
            $innerSql = 'SELECT '.implode(', ', $aggSelects)
                ." FROM {$table} o"
                ." INNER JOIN {$table} d ON {$boundsClause}{$scopeClause}"
                ." WHERE o.{$modelKey} IN ({$userIdsSql})"
                ." AND o.{$rgtCol} > o.{$lftCol} + 1"
                ." GROUP BY o.{$modelKey}";

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
                $cased = self::wrapLeafFastPath($definition, "{$table}.", $lftCol, $rgtCol, $joinExpr);
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
        TreeQueryBuilder $builder,
        array $resolved,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scopeCols,
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
                $expr = self::aggregateExpression($definition, qualifier: 'd.');
                $aggSelects[] = "{$expr} AS {$colAlias}";
            }

            $boundsClause = $mode === 'inclusive'
                ? "d.{$lftCol} >= {$table}.{$lftCol} AND d.{$rgtCol} <= {$table}.{$rgtCol}"
                : "d.{$lftCol} > {$table}.{$lftCol} AND d.{$rgtCol} < {$table}.{$rgtCol}";

            $scopeClause = '';
            foreach ($scopeCols as $col) {
                $scopeClause .= " AND d.{$col} = {$table}.{$col}";
            }

            $innerSql = 'SELECT '.implode(', ', $aggSelects)
                ." FROM {$table} d"
                ." WHERE {$boundsClause}{$scopeClause}";

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
                $cased = self::wrapLeafFastPath($definition, "{$table}.", $lftCol, $rgtCol, $joinExpr);
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
    public static function scalar(Model&HasNestedSet $node, AggregateDefinition $definition): mixed
    {
        $bounds = $node->getBounds();
        $table = $node->getTable();
        $lftCol = $node->getLftName();
        $rgtCol = $node->getRgtName();
        $aggregateExpr = self::aggregateExpression($definition, qualifier: '');
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

        $sql = "SELECT {$aggregateExpr} AS aggregate FROM {$table} WHERE {$boundsClause}{$scopeClause}";

        return $node->getConnection()->scalar($sql, $bindings);
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
     * @param  list<string>  $scopeCols
     */
    private static function buildCorrelatedSubquery(
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scopeCols,
        AggregateDefinition $definition,
    ): string {
        $aggregateExpr = self::aggregateExpression($definition, qualifier: 'd.');

        $boundsClause = $definition->inclusive
            ? "d.{$lftCol} >= {$table}.{$lftCol} AND d.{$rgtCol} <= {$table}.{$rgtCol}"
            : "d.{$lftCol} > {$table}.{$lftCol} AND d.{$rgtCol} < {$table}.{$rgtCol}";

        $scopeClause = '';
        foreach ($scopeCols as $col) {
            $scopeClause .= " AND d.{$col} = {$table}.{$col}";
        }

        return "SELECT {$aggregateExpr} FROM {$table} d WHERE {$boundsClause}{$scopeClause}";
    }

    /**
     * Returns the SQL aggregate expression for a definition. `$qualifier`
     * prefixes the source column (e.g. `d.` inside a correlated subquery,
     * empty for the single-node scalar form which does not need an alias).
     */
    private static function aggregateExpression(AggregateDefinition $definition, string $qualifier): string
    {
        if ($definition->filter instanceof FilterPredicate) {
            return self::filteredAggregateExpression($definition, $qualifier, $definition->filter);
        }

        return match ($definition->function) {
            AggregateFunction::Sum => sprintf(
                'COALESCE(SUM(%s%s), 0)',
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Count => $definition->source === null
                ? 'COUNT(*)'
                : sprintf('COUNT(%s%s)', $qualifier, $definition->source),
            AggregateFunction::Avg => sprintf(
                'AVG(%s%s)',
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Min => sprintf(
                'MIN(%s%s)',
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Max => sprintf(
                'MAX(%s%s)',
                $qualifier,
                self::requireSource($definition),
            ),
        };
    }

    private static function filteredAggregateExpression(
        AggregateDefinition $definition,
        string $qualifier,
        FilterPredicate $filter,
    ): string {
        $pred = self::filterPredicateSql($filter, $qualifier);

        return match ($definition->function) {
            AggregateFunction::Sum => sprintf(
                'COALESCE(SUM(CASE WHEN %s THEN %s%s ELSE 0 END), 0)',
                $pred,
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Count => $definition->source === null
                ? sprintf('COUNT(CASE WHEN %s THEN 1 ELSE NULL END)', $pred)
                : sprintf(
                    'COUNT(CASE WHEN %s THEN %s%s ELSE NULL END)',
                    $pred,
                    $qualifier,
                    $definition->source,
                ),
            AggregateFunction::Avg => sprintf(
                'AVG(CASE WHEN %s THEN %s%s ELSE NULL END)',
                $pred,
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Min => sprintf(
                'MIN(CASE WHEN %s THEN %s%s ELSE NULL END)',
                $pred,
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Max => sprintf(
                'MAX(CASE WHEN %s THEN %s%s ELSE NULL END)',
                $pred,
                $qualifier,
                self::requireSource($definition),
            ),
        };
    }

    private static function filterPredicateSql(FilterPredicate $filter, string $qualifier): string
    {
        return match ($filter->getKind()) {
            FilterPredicateKind::Equality => implode(' AND ', array_map(
                static function (string $col, mixed $value) use ($qualifier): string {
                    if ($value === null) {
                        return "{$qualifier}{$col} IS NULL";
                    }

                    return "{$qualifier}{$col} = ".self::quoteFilterValue($value);
                },
                array_keys($filter->getConditions()),
                array_values($filter->getConditions()),
            )),
            FilterPredicateKind::NotNull => sprintf(
                '%s%s IS NOT NULL',
                $qualifier,
                (string) $filter->getNotNullColumn(),
            ),
            FilterPredicateKind::Raw => str_replace(
                '{q}',
                $qualifier,
                $filter->getRawSql() ?? throw new AggregateConfigurationException(
                    'FilterPredicate of kind Raw has a null rawSql — this should never happen.',
                ),
            ),
        };
    }

    private static function quoteFilterValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            throw new AggregateConfigurationException(sprintf(
                'FilterPredicate equality condition value must be scalar; got %s.',
                get_debug_type($value),
            ));
        }

        return "'".str_replace("'", "''", $value)."'";
    }

    private static function requireSource(AggregateDefinition $definition): string
    {
        if ($definition->source === null) {
            throw new AggregateConfigurationException(sprintf(
                'AggregateDefinition for column "%s" (%s) requires a source column.',
                $definition->column,
                $definition->function->value,
            ));
        }

        return $definition->source;
    }

    /**
     * Inline expression that produces the aggregate value for a leaf
     * row (`rgt = lft + 1`) without going through the LATERAL / derived
     * join. For inclusive aggregates the subtree is exactly the leaf
     * itself, so SUM/AVG/MIN/MAX collapse to the source column and
     * COUNT(*) is 1; for exclusive aggregates the subtree is empty so
     * SUM/COUNT are 0 and AVG/MIN/MAX are NULL.
     *
     * Used by the leaf fast-path in {@see applyFreshSelects()}, wrapped
     * in a `CASE WHEN rgt = lft + 1 THEN <inline> ELSE <join-derived>
     * END` so each row pays JOIN cost only when it has descendants.
     */
    private static function leafInlineExpression(AggregateDefinition $definition, string $tableQualifier): string
    {
        if (! $definition->inclusive) {
            return match ($definition->function) {
                AggregateFunction::Sum, AggregateFunction::Count => '0',
                AggregateFunction::Avg, AggregateFunction::Min, AggregateFunction::Max => 'NULL',
            };
        }

        if ($definition->filter instanceof FilterPredicate) {
            return self::filteredLeafInlineExpression($definition, $tableQualifier, $definition->filter);
        }

        return match ($definition->function) {
            AggregateFunction::Sum => sprintf(
                'COALESCE(%s%s, 0)',
                $tableQualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Count => $definition->source === null
                ? '1'
                : sprintf(
                    'CASE WHEN %s%s IS NULL THEN 0 ELSE 1 END',
                    $tableQualifier,
                    $definition->source,
                ),
            AggregateFunction::Avg,
            AggregateFunction::Min,
            AggregateFunction::Max => sprintf(
                '%s%s',
                $tableQualifier,
                self::requireSource($definition),
            ),
        };
    }

    private static function filteredLeafInlineExpression(
        AggregateDefinition $definition,
        string $tableQualifier,
        FilterPredicate $filter,
    ): string {
        $pred = self::filterPredicateSql($filter, $tableQualifier);

        return match ($definition->function) {
            AggregateFunction::Sum => sprintf(
                'COALESCE(CASE WHEN %s THEN %s%s ELSE 0 END, 0)',
                $pred,
                $tableQualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Count => $definition->source === null
                ? sprintf('CASE WHEN %s THEN 1 ELSE 0 END', $pred)
                : sprintf(
                    'CASE WHEN %s AND %s%s IS NOT NULL THEN 1 ELSE 0 END',
                    $pred,
                    $tableQualifier,
                    $definition->source,
                ),
            AggregateFunction::Avg,
            AggregateFunction::Min,
            AggregateFunction::Max => sprintf(
                'CASE WHEN %s THEN %s%s ELSE NULL END',
                $pred,
                $tableQualifier,
                self::requireSource($definition),
            ),
        };
    }

    /**
     * Wraps a join-derived aggregate expression in the leaf fast-path
     * CASE: returns the leaf-inline value when `rgt = lft + 1`, the
     * join expression otherwise. On every supported backend the inner
     * branch is only evaluated when the outer CASE picks it; on
     * LATERAL/derived backends the JOIN may still fire (because the
     * planner evaluates the JOIN before the SELECT) so callers that
     * can short-circuit the JOIN itself should do so via the inner
     * WHERE clause as well — this CASE is the SELECT-side half of
     * the fast-path.
     */
    private static function wrapLeafFastPath(
        AggregateDefinition $definition,
        string $tableQualifier,
        string $lftCol,
        string $rgtCol,
        string $joinExpr,
    ): string {
        $inline = self::leafInlineExpression($definition, $tableQualifier);

        return sprintf(
            'CASE WHEN %s%s = %s%s + 1 THEN %s ELSE %s END',
            $tableQualifier,
            $rgtCol,
            $tableQualifier,
            $lftCol,
            $inline,
            $joinExpr,
        );
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
        ?int $rootId = null,
        ?string $parentIdCol = null,
        ?string $depthCol = null,
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
        );

        $errors = [];

        foreach ($userFacing as $definition) {
            $errors[$definition->column] = 0;
        }

        foreach ($rows as $row) {
            foreach ($userFacing as $definition) {
                $stored = $row[self::storedAlias($definition->column)] ?? null;
                $computed = $row[self::computedAlias($definition->column)] ?? null;

                if (! self::aggregatesEqual($stored, $computed)) {
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
     * @param  list<int>|null  $outerIds  When non-null, restricts the
     *                                    repair to this subset of outer
     *                                    rows. Used by the chunked /
     *                                    self-redispatching queue job.
     */
    public static function fixAggregates(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scope,
        array $definitions,
        ?int $rootId = null,
        ?array $outerIds = null,
        ?string $parentIdCol = null,
        ?string $depthCol = null,
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

                if (! self::aggregatesEqual($stored, $computed)) {
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
                $caseSql = 'CASE id';
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
                ." WHERE id IN ({$idPlaceholders})";

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
     * @param  list<int>|null  $outerIds
     * @return list<array<string, mixed>>
     */
    private static function selectStoredAndComputed(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scope,
        array $definitions,
        ?int $rootId,
        ?array $outerIds = null,
        ?string $parentIdCol = null,
        ?string $depthCol = null,
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
        if (
            $outerIds === null
            && $parentIdCol !== null
            && $depthCol !== null
            && self::isChainShape($connection, $table, $parentIdCol, $lftCol, $rgtCol, $scope, $rootId)
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
        ?int $rootId,
    ): bool {
        $sql = "SELECT 1 FROM {$table} WHERE 1 = 1";
        $bindings = [];

        foreach ($scope as $col => $value) {
            $sql .= " AND {$col} = ?";
            $bindings[] = $value;
        }

        if ($rootId !== null) {
            $sql .= " AND {$lftCol} >= (SELECT {$lftCol} FROM {$table} WHERE id = ?)";
            $sql .= " AND {$rgtCol} <= (SELECT {$rgtCol} FROM {$table} WHERE id = ?)";
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
        ?int $rootId,
    ): array {
        // Collect every column we need to fetch: source columns (for
        // the fold input), stored aggregate columns (for the diff
        // output), and the structural columns to walk the chain.
        $needed = ['id', $parentIdCol, $lftCol, $rgtCol, $depthCol];
        foreach ($definitions as $definition) {
            if ($definition->source !== null) {
                $needed[] = $definition->source;
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

        if ($rootId !== null) {
            $sql .= " AND {$lftCol} >= (SELECT {$lftCol} FROM {$table} WHERE id = ?)";
            $sql .= " AND {$rgtCol} <= (SELECT {$rgtCol} FROM {$table} WHERE id = ?)";
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
            // Two accumulators per definition. `prev` is the immediately-
            // descendant row's inclusive value. `acc` is what we just
            // computed for the current row's inclusive subtree — it
            // becomes `prev` for the next iteration. Empty subtree
            // (a leaf's exclusive aggregate) reads from `prev` at the
            // first iteration, where `prev` is still the empty value.
            $prevInclusive = self::chainFoldEmpty($definition);

            // For AVG: we need parallel SUM/COUNT accumulators because
            // AVG is a derived ratio. SUM accumulates non-null sources,
            // COUNT counts non-null sources. Combined at emit time.
            $prevSumAvg = 0;
            $prevCountAvg = 0;

            foreach ($rows as $row) {
                $id = $row['id'];
                if (! is_int($id) && ! is_string($id)) {
                    continue;
                }

                $sourceValue = $definition->source !== null
                    ? ($row[$definition->source] ?? null)
                    : null;

                // Combine source with previous-row's inclusive to get
                // current row's inclusive.
                if ($definition->function === AggregateFunction::Avg) {
                    $sumDelta = is_numeric($sourceValue) ? (float) $sourceValue : 0.0;
                    $countDelta = is_numeric($sourceValue) ? 1 : 0;
                    $currentSum = $prevSumAvg + $sumDelta;
                    $currentCount = $prevCountAvg + $countDelta;
                    $currentInclusive = $currentCount > 0 ? $currentSum / $currentCount : null;
                    $previousInclusive = $prevCountAvg > 0 ? $prevSumAvg / $prevCountAvg : null;
                } else {
                    $currentInclusive = self::chainFoldStep($definition, $sourceValue, $prevInclusive);
                    $previousInclusive = $prevInclusive;
                }

                $computedValue = $definition->inclusive ? $currentInclusive : $previousInclusive;

                if (! isset($output[$id])) {
                    $output[$id] = ['id' => $id];
                }
                $output[$id][self::storedAlias($definition->column)] = $row[$definition->column] ?? null;
                $output[$id][self::computedAlias($definition->column)] = $computedValue;

                if ($definition->function === AggregateFunction::Avg) {
                    $prevSumAvg = $currentSum;
                    $prevCountAvg = $currentCount;
                }
                $prevInclusive = $currentInclusive;
            }
        }

        return array_values($output);
    }

    /**
     * Initial accumulator value for the chain fold — represents the
     * inclusive aggregate of an empty subtree (i.e. what an exclusive
     * aggregate reports on a leaf). Mirrors the SQL the slow path
     * would emit for the same empty subtree.
     */
    private static function chainFoldEmpty(AggregateDefinition $definition): ?int
    {
        return match ($definition->function) {
            AggregateFunction::Sum,
            AggregateFunction::Count => 0,
            AggregateFunction::Avg,
            AggregateFunction::Min,
            AggregateFunction::Max => null,
        };
    }

    /**
     * One step of the chain fold. Takes the current row's source value
     * and the previous (already-folded) inclusive subtree value, and
     * returns the current row's inclusive subtree value.
     */
    private static function chainFoldStep(
        AggregateDefinition $definition,
        mixed $sourceValue,
        int|float|null $previousInclusive,
    ): int|float|null {
        switch ($definition->function) {
            case AggregateFunction::Sum:
                $sourceNumeric = is_numeric($sourceValue) ? (float) $sourceValue : 0.0;
                $prev = $previousInclusive ?? 0;
                $sum = $sourceNumeric + (float) $prev;

                return self::isWhole($sum) ? (int) $sum : $sum;

            case AggregateFunction::Count:
                $contribution = $definition->source === null
                    ? 1
                    : ($sourceValue !== null ? 1 : 0);

                return ((int) ($previousInclusive ?? 0)) + $contribution;

            case AggregateFunction::Min:
                if (! is_numeric($sourceValue)) {
                    return $previousInclusive;
                }
                $sourceNumeric = (float) $sourceValue;
                if ($previousInclusive === null) {
                    return self::isWhole($sourceNumeric) ? (int) $sourceNumeric : $sourceNumeric;
                }

                $minValue = min($sourceNumeric, (float) $previousInclusive);

                return self::isWhole($minValue) ? (int) $minValue : $minValue;

            case AggregateFunction::Max:
                if (! is_numeric($sourceValue)) {
                    return $previousInclusive;
                }
                $sourceNumeric = (float) $sourceValue;
                if ($previousInclusive === null) {
                    return self::isWhole($sourceNumeric) ? (int) $sourceNumeric : $sourceNumeric;
                }

                $maxValue = max($sourceNumeric, (float) $previousInclusive);

                return self::isWhole($maxValue) ? (int) $maxValue : $maxValue;

            case AggregateFunction::Avg:
                // AVG is handled inline by the caller because it needs
                // two parallel accumulators (SUM and COUNT-of-non-null).
                throw new \LogicException('AVG must be handled inline in the chain fold.');
        }
    }

    /**
     * True when a float value has no fractional part — used so the
     * chain fold can return integer-valued results as `int` to match
     * what the SQL path emits (where SUM/MIN/MAX over an integer
     * column come back as int, not float).
     */
    private static function isWhole(float $value): bool
    {
        return $value === floor($value) && abs($value) < PHP_INT_MAX;
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
     * @param  list<int>|null  $outerIds
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
        ?int $rootId,
        ?array $outerIds = null,
    ): array {
        $outerSelects = ['outer_a.id AS id'];
        $aggSelects = ['o.id AS outer_id'];

        foreach ($definitions as $definition) {
            $innerExpr = self::aggregateExpression($definition, qualifier: 'i.');
            $computedAlias = self::computedAlias($definition->column);
            $aggSelects[] = "{$innerExpr} AS {$computedAlias}";

            $outerComputed = match ($definition->function) {
                AggregateFunction::Sum,
                AggregateFunction::Count => "COALESCE(agg.{$computedAlias}, 0)",
                default => "agg.{$computedAlias}",
            };
            $outerSelects[] = "{$outerComputed} AS {$computedAlias}";
            $outerSelects[] = "outer_a.{$definition->column} AS ".self::storedAlias($definition->column);
        }

        $joinClause = $inclusive
            ? "i.{$lftCol} >= o.{$lftCol} AND i.{$lftCol} <= o.{$rgtCol}"
            : "i.{$lftCol} > o.{$lftCol} AND i.{$lftCol} < o.{$rgtCol}";

        $scopeJoinExtra = '';
        foreach (array_keys($scope) as $col) {
            $scopeJoinExtra .= " AND i.{$col} = o.{$col}";
        }

        $bindings = [];

        $innerWhere = '1 = 1';
        foreach ($scope as $col => $value) {
            $innerWhere .= " AND o.{$col} = ?";
            $bindings[] = $value;
        }
        if ($rootId !== null) {
            $innerWhere .= " AND o.{$lftCol} >= (SELECT {$lftCol} FROM {$table} WHERE id = ?)";
            $innerWhere .= " AND o.{$rgtCol} <= (SELECT {$rgtCol} FROM {$table} WHERE id = ?)";
            $bindings[] = $rootId;
            $bindings[] = $rootId;
        }

        // Chunked-job path: limit both the inner aggregation and the
        // outer SELECT to the supplied id set so we only do work for
        // rows in this chunk.
        if ($outerIds !== null) {
            $placeholders = implode(', ', array_fill(0, count($outerIds), '?'));
            $innerWhere .= " AND o.id IN ({$placeholders})";
            foreach ($outerIds as $id) {
                $bindings[] = $id;
            }
        }

        $outerWhere = '1 = 1';
        foreach ($scope as $col => $value) {
            $outerWhere .= " AND outer_a.{$col} = ?";
            $bindings[] = $value;
        }
        if ($rootId !== null) {
            $outerWhere .= " AND outer_a.{$lftCol} >= (SELECT {$lftCol} FROM {$table} WHERE id = ?)";
            $outerWhere .= " AND outer_a.{$rgtCol} <= (SELECT {$rgtCol} FROM {$table} WHERE id = ?)";
            $bindings[] = $rootId;
            $bindings[] = $rootId;
        }
        if ($outerIds !== null) {
            $placeholders = implode(', ', array_fill(0, count($outerIds), '?'));
            $outerWhere .= " AND outer_a.id IN ({$placeholders})";
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
        $innerJoinKeyword = self::isMySql($connection) ? 'STRAIGHT_JOIN' : 'INNER JOIN';

        $sql = 'SELECT '.implode(', ', $outerSelects)
            ." FROM {$table} AS outer_a"
            .' LEFT JOIN ('
            .'SELECT '.implode(', ', $aggSelects)
            ." FROM {$table} AS o"
            ." {$innerJoinKeyword} {$table} AS i ON {$joinClause}{$scopeJoinExtra}"
            ." WHERE {$innerWhere}"
            .' GROUP BY o.id'
            .') AS agg ON agg.outer_id = outer_a.id'
            ." WHERE {$outerWhere}";

        // MariaDB's planner converts our derived table into a LATERAL
        // DERIVED via the `split_materialized` optimization — it
        // re-executes the sub-query once per outer row instead of
        // materializing it once, making the derived shape ~3× slower than
        // the non-derived form. Disable that optimization for this single
        // statement so the derived gets materialized once. MySQL has no
        // `split_materialized` flag (it already materializes once for
        // free), so this only fires on MariaDB.
        if (self::isMariaDb($connection)) {
            $sql = "SET STATEMENT optimizer_switch='split_materialized=off' FOR ".$sql;
        }

        $rows = $connection->select($sql, $bindings);

        $result = [];
        foreach ($rows as $row) {
            $result[] = (array) $row;
        }

        return $result;
    }

    /**
     * Tolerant numeric equality. Both sides may arrive as int, float,
     * decimal-string (PostgreSQL), or null. Sub-cent precision drift on
     * AVG is considered equal so a recomputed 56.2500 doesn't disagree
     * with a stored 56.25.
     */
    public static function aggregatesEqual(mixed $a, mixed $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        if (! is_numeric($a) || ! is_numeric($b)) {
            return $a === $b;
        }

        return abs((float) $a - (float) $b) < 0.0001;
    }

    private static function computedAlias(string $column): string
    {
        return 'computed_'.$column;
    }

    private static function storedAlias(string $column): string
    {
        return 'stored_'.$column;
    }

    /**
     * Laravel reports both MariaDB and MySQL under the `mysql` driver
     * name; we need to distinguish them because their planners pick
     * different execution strategies for the same SQL. PDO's
     * `ATTR_SERVER_VERSION` returns the server's `@@version` string
     * verbatim — MariaDB's includes "MariaDB", MySQL's does not.
     */
    private static function isMariaDb(ConnectionInterface $connection): bool
    {
        if (! $connection instanceof Connection) {
            return false;
        }

        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        try {
            $version = $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable) {
            return false;
        }

        return is_string($version) && stripos($version, 'mariadb') !== false;
    }

    /**
     * Returns true on real MySQL (Oracle MySQL), false on MariaDB or
     * any non-mysql driver. Distinguishes MySQL from MariaDB so we can
     * apply MySQL-specific planner hints (e.g. STRAIGHT_JOIN inside
     * the fixAggregates derived table) without affecting MariaDB,
     * which has its own SET STATEMENT path for the same query.
     */
    private static function isMySql(ConnectionInterface $connection): bool
    {
        return $connection instanceof Connection
            && $connection->getDriverName() === 'mysql'
            && ! self::isMariaDb($connection);
    }
}
