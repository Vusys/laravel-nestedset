<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query\Aggregates\Read;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\BoundFragment;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Sql\AggregateSqlEmitter;
use Vusys\NestedSet\Aggregates\Sql\SqliteBitwiseAggregates;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Query\Aggregates\Maintenance\AggregateDiffer;
use Vusys\NestedSet\Query\TreeBaseQueryBuilder;
use Vusys\NestedSet\Query\TreeExpression;
use Vusys\NestedSet\Query\TreeQueryBuilder;
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
 * Read paths only. SQL fragment construction lives in
 * {@see AggregateSqlFragments}; maintenance writes live in
 * {@see AggregateDiffer}.
 */
final class FreshAggregateProjector
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
        ['topks' => $topks, 'rest' => $resolved] = self::splitTopKs($resolved);

        if ($resolved === [] && $quantiles === [] && $topks === []) {
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
                    $sub = self::buildCorrelatedSubquery($connection, $table, $lftCol, $rgtCol, $scopeCols, $definition, $softDeletedColumn);
                    $cased = AggregateSqlFragments::wrapLeafFastPath(
                        $definition,
                        "{$table}.",
                        $lftCol,
                        $rgtCol,
                        new BoundFragment("({$sub->sql})", $sub->bindings),
                        $softDeletedColumn,
                        $connection,
                    );

                    $builder->addSelect(['*', new TreeExpression("{$cased->sql} as {$alias}")]);
                    if ($cased->bindings !== []) {
                        $builder->getQuery()->addBinding($cased->bindings, 'select');
                    }
                }
            }
        }

        // Quantile definitions always use individual correlated subqueries,
        // regardless of backend. On PG they emit PERCENTILE_CONT; on all
        // other backends they emit the window-function interpolation subquery.
        // The leaf fast-path still applies: a single-element subtree's quantile
        // is just the source value itself.
        foreach ($quantiles as $alias => $definition) {
            $sub = self::buildQuantileSubquery($connection, $table, $lftCol, $rgtCol, $scopeCols, $definition, $softDeletedColumn);
            $cased = AggregateSqlFragments::wrapLeafFastPath(
                $definition,
                "{$table}.",
                $lftCol,
                $rgtCol,
                new BoundFragment("({$sub->sql})", $sub->bindings),
                $softDeletedColumn,
                $connection,
            );

            $builder->addSelect(['*', new TreeExpression("{$cased->sql} as {$alias}")]);
            if ($cased->bindings !== []) {
                $builder->getQuery()->addBinding($cased->bindings, 'select');
            }
        }

        // TopK also needs a dedicated subquery shape — the JSON aggregator
        // can't apply LIMIT directly, so we wrap an ORDER BY + LIMIT
        // derived table inside the correlated subquery. Same per-backend
        // dispatch as JsonAgg, but with the top-K winnowing baked in.
        foreach ($topks as $alias => $definition) {
            $sub = self::buildTopKSubquery($connection, $table, $lftCol, $rgtCol, $scopeCols, $definition, $softDeletedColumn);
            $cased = AggregateSqlFragments::wrapLeafFastPath(
                $definition,
                "{$table}.",
                $lftCol,
                $rgtCol,
                $sub,
                $softDeletedColumn,
                $connection,
            );

            $builder->addSelect(['*', new TreeExpression("{$cased->sql} as {$alias}")]);
            if ($cased->bindings !== []) {
                $builder->getQuery()->addBinding($cased->bindings, 'select');
            }
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
            $aggBindings = [];
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
                $aggSelects[] = "{$expr->sql} AS {$colAlias}";
                foreach ($expr->bindings as $b) {
                    $aggBindings[] = $b;
                }
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
            // via {@see AggregateSqlFragments::wrapLeafFastPath()}. On a
            // wideShallow shape this cuts the inner aggregation's input
            // from N to 1 (only the root has descendants).
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

            // Bindings ride inside the JOIN clause: predicate bindings
            // from filter()/filterRaw() come first in textual order
            // (embedded in the aggregate SELECT list), then the user-ids
            // sub-query placeholders in the WHERE.
            if ($aggBindings !== []) {
                $builder->getQuery()->addBinding($aggBindings, 'join');
            }
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
            $casedBindings = [];
            foreach ($group as $colAlias => $definition) {
                $joinExpr = match ($definition->function) {
                    AggregateFunction::Sum,
                    AggregateFunction::Count => "COALESCE({$derivedAlias}.{$colAlias}, 0)",
                    default => "{$derivedAlias}.{$colAlias}",
                };
                $cased = AggregateSqlFragments::wrapLeafFastPath($definition, "{$table}.", $lftCol, $rgtCol, $joinExpr, $softDeletedColumn, $connection);
                $columns[] = new TreeExpression("{$cased->sql} as {$colAlias}");
                foreach ($cased->bindings as $b) {
                    $casedBindings[] = $b;
                }
            }
            $builder->addSelect($columns);
            if ($casedBindings !== []) {
                $builder->getQuery()->addBinding($casedBindings, 'select');
            }
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
            $aggBindings = [];
            foreach ($group as $colAlias => $definition) {
                $expr = AggregateSqlFragments::aggregateExpression($definition, qualifier: 'd.', connection: $connection);
                $aggSelects[] = "{$expr->sql} AS {$colAlias}";
                foreach ($expr->bindings as $b) {
                    $aggBindings[] = $b;
                }
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
            // {@see AggregateSqlFragments}.
            $lateralExpr = new TreeExpression("LATERAL ({$innerSql}) as {$lateralAlias}");
            $onClause = "{$table}.{$rgtCol} > {$table}.{$lftCol} + 1";
            $query->leftJoin($lateralExpr, static function ($join) use ($onClause): void {
                $join->whereRaw($onClause);
            });
            if ($aggBindings !== []) {
                $query->addBinding($aggBindings, 'join');
            }

            $columns = ['*'];
            $casedBindings = [];
            foreach ($group as $colAlias => $definition) {
                $joinExpr = "{$lateralAlias}.{$colAlias}";
                $cased = AggregateSqlFragments::wrapLeafFastPath($definition, "{$table}.", $lftCol, $rgtCol, $joinExpr, $softDeletedColumn, $connection);
                $columns[] = new TreeExpression("{$cased->sql} as {$colAlias}");
                foreach ($cased->bindings as $b) {
                    $casedBindings[] = $b;
                }
            }
            $builder->addSelect($columns);
            if ($casedBindings !== []) {
                $query->addBinding($casedBindings, 'select');
            }
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

        return self::mysqlVersionSupportsLateral(is_string($version) ? $version : null);
    }

    /**
     * Pure version-string check for whether a MySQL server reports a
     * version that supports the SQL `LATERAL` keyword. Split out from
     * {@see supportsLateral()} so the entire decision can be unit-tested
     * with seeded version strings — the wider helper depends on a live
     * Connection / PDO that's awkward to fake at the version level.
     *
     * Returns false for null, for non-version-shaped strings, and for
     * the MariaDB family (which advertises a MySQL-shaped version but
     * rejects `LATERAL` as a syntax error). MySQL adopted LATERAL in
     * 8.0.14.
     */
    private static function mysqlVersionSupportsLateral(?string $version): bool
    {
        if ($version === null) {
            return false;
        }

        if (stripos($version, 'mariadb') !== false) {
            return false;
        }

        if (! preg_match('/(\d+)\.(\d+)(?:\.(\d+))?/', $version, $m)) {
            return false;
        }

        $major = (int) $m[1];
        $minor = (int) $m[2];
        $patch = (int) ($m[3] ?? 0);

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
            $filterFragment = $definition->filter instanceof FilterPredicate
                ? $definition->filter->toFragment('')
                : null;
            $innerFromClause = "FROM {$table} WHERE {$boundsClause}{$scopeClause}{$softClause}";
            $fragment = AggregateSqlEmitter::emitQuantileWindowSubquery($definition, $innerFromClause, '', $filterFragment);

            return $connection->scalar($fragment->sql, [...$fragment->bindings, ...$bindings]);
        }

        // TopK needs an ORDER BY + LIMIT subquery shape that doesn't fit
        // into the scalar aggregateExpression() pattern. The emitter
        // assumes the inner alias `inner_a`, so rebuild the bounds /
        // scope predicate against `inner_a` using `?` placeholders that
        // share the same bindings list as the rest of the scalar path.
        if ($definition->function === AggregateFunction::TopK) {
            $innerBoundsClause = $definition->inclusive
                ? "inner_a.{$lftCol} >= ? AND inner_a.{$rgtCol} <= ?"
                : "inner_a.{$lftCol} > ? AND inner_a.{$rgtCol} < ?";

            $innerBindings = [$bounds->lft, $bounds->rgt];

            $innerScopeClause = '';
            foreach ($scopeValues as $column => $value) {
                $innerScopeClause .= " AND inner_a.{$column} = ?";
                $innerBindings[] = $value;
            }

            $innerSoftClause = $softDeletedColumn === null
                ? ''
                : " AND inner_a.{$softDeletedColumn} IS NULL";

            $filterFragment = $definition->filter instanceof FilterPredicate
                ? $definition->filter->toFragment('inner_a.')
                : null;

            $topKFragment = AggregateSqlEmitter::emitTopKCorrelatedSubquery(
                $connection,
                $definition,
                $table,
                $innerBoundsClause.$innerScopeClause.$innerSoftClause,
                $filterFragment,
            );

            $sql = 'SELECT '.$topKFragment->sql.' AS aggregate';

            return $connection->scalar($sql, [...$topKFragment->bindings, ...$innerBindings]);
        }

        $aggregateExpr = AggregateSqlFragments::aggregateExpression($definition, qualifier: '', connection: $connection);
        $sql = "SELECT {$aggregateExpr->sql} AS aggregate FROM {$table} WHERE {$boundsClause}{$scopeClause}{$softClause}";

        return $connection->scalar($sql, [...$aggregateExpr->bindings, ...$bindings]);
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
     * Same shape as {@see splitQuantiles()} — pulls TopK definitions
     * out so they can be projected as standalone correlated subqueries
     * instead of routed through the LATERAL / derived shapes that
     * assume a scalar aggregator.
     *
     * @param  array<string, AggregateDefinition>  $resolved
     * @return array{topks: array<string, AggregateDefinition>, rest: array<string, AggregateDefinition>}
     */
    private static function splitTopKs(array $resolved): array
    {
        $topks = [];
        $rest = [];

        foreach ($resolved as $alias => $definition) {
            if ($definition->function === AggregateFunction::TopK) {
                $topks[$alias] = $definition;
            } else {
                $rest[$alias] = $definition;
            }
        }

        return ['topks' => $topks, 'rest' => $rest];
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
    ): BoundFragment {
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

        $filterFragment = $definition->filter instanceof FilterPredicate
            ? $definition->filter->toFragment('d.')
            : null;

        if ($connection instanceof Connection && $connection->getDriverName() === 'pgsql') {
            $aggregateExpr = AggregateSqlEmitter::emitQuantileNativeExpression($definition, 'd.', $filterFragment);

            return new BoundFragment(
                "SELECT {$aggregateExpr->sql} FROM {$table} d WHERE {$boundsClause}{$scopeClause}{$softClause}",
                $aggregateExpr->bindings,
            );
        }

        $innerFromClause = "FROM {$table} d WHERE {$boundsClause}{$scopeClause}{$softClause}";

        if (AggregateSqlFragments::isMariaDb($connection)) {
            return AggregateSqlEmitter::emitQuantileJsonExpression($definition, $innerFromClause, 'd.', $filterFragment);
        }

        return AggregateSqlEmitter::emitQuantileWindowSubquery($definition, $innerFromClause, 'd.', $filterFragment);
    }

    /**
     * Correlated subquery for a TopK aggregate. Reuses
     * {@see AggregateSqlEmitter::emitTopKCorrelatedSubquery()} so the
     * SQL shape matches what the maintenance path writes — drift between
     * fresh-read and maintained values is identical to a same-named
     * function call.
     *
     * The emitter assumes the inner alias `inner_a`, so we build the
     * bounds clause against `inner_a` correlated to the outer `{$table}.`
     * prefix used by the fresh-read projector.
     *
     * @param  list<string>  $scopeCols
     */
    private static function buildTopKSubquery(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scopeCols,
        AggregateDefinition $definition,
        ?string $softDeletedColumn,
    ): BoundFragment {
        $boundsClause = $definition->inclusive
            ? "inner_a.{$lftCol} >= {$table}.{$lftCol} AND inner_a.{$rgtCol} <= {$table}.{$rgtCol}"
            : "inner_a.{$lftCol} > {$table}.{$lftCol} AND inner_a.{$rgtCol} < {$table}.{$rgtCol}";

        $scopeClause = '';
        foreach ($scopeCols as $col) {
            $scopeClause .= " AND inner_a.{$col} = {$table}.{$col}";
        }

        $softClause = $softDeletedColumn === null
            ? ''
            : " AND inner_a.{$softDeletedColumn} IS NULL";

        $filterFragment = $definition->filter instanceof FilterPredicate
            ? $definition->filter->toFragment('inner_a.')
            : null;

        return AggregateSqlEmitter::emitTopKCorrelatedSubquery(
            $connection,
            $definition,
            $table,
            $boundsClause.$scopeClause.$softClause,
            $filterFragment,
        );
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
    ): BoundFragment {
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

        return new BoundFragment(
            "SELECT {$aggregateExpr->sql} FROM {$table} d WHERE {$boundsClause}{$scopeClause}{$softClause}",
            $aggregateExpr->bindings,
        );
    }
}
