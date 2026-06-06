<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query\Aggregates\Maintenance;

use Illuminate\Database\Connection;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Sql\SqliteBitwiseAggregates;
use Vusys\NestedSet\Contracts\AggregateDefinitionContract;
use Vusys\NestedSet\Query\Aggregates\Read\AggregateSqlFragments;
use Vusys\NestedSet\Query\ChainFoldAccumulator;

/**
 * Integrity tooling for stored aggregate columns.
 *
 * Two public entry points the repair surface calls:
 *  - {@see aggregateErrors()} — reports per-column drift counts over a
 *    scope (or rooted subtree) without touching the data.
 *  - {@see fixAggregates()} — writes the freshly-computed value back to
 *    every drifted row.
 *
 * Both sit on top of {@see selectStoredAndComputed()}, which picks
 * between two implementation strategies per call:
 *  - {@see selectStoredAndComputedViaChainFold()} for trees with at
 *    most one child per parent (folds in PHP, O(N) total).
 *  - {@see groupedAggregateQuery()} for everything else (one
 *    self-JOIN + GROUP BY per inclusivity group).
 *
 * Drift detection delegates to {@see AggregateValueComparator}; SQL
 * fragments for the grouped path delegate to {@see AggregateSqlFragments}.
 */
final class AggregateDiffer
{
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
        $aggBindings = [];

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
            $aggSelects[] = "{$innerExpr->sql} AS {$computedAlias}";
            foreach ($innerExpr->bindings as $b) {
                $aggBindings[] = $b;
            }

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

        // Aggregate-predicate bindings appear FIRST textually (in the
        // inner SELECT's CASE WHEN, before the inner WHERE) so they
        // lead the positional stream.
        $bindings = $aggBindings;

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
