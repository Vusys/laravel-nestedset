<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
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

        foreach ($resolved as $alias => $definition) {
            $sql = self::buildCorrelatedSubquery($table, $lftCol, $rgtCol, $scopeCols, $definition);

            $builder->addSelect(['*', new TreeExpression("({$sql}) as {$alias}")]);
        }
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
            if ($definition->column === $column) {
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
     * @param  list<AggregateDefinition>  $definitions
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
    ): array {
        $userFacing = array_values(array_filter(
            $definitions,
            static fn (AggregateDefinition $d): bool => ! $d->isInternal(),
        ));

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
     * @param  list<AggregateDefinition>  $definitions
     */
    public static function fixAggregates(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scope,
        array $definitions,
        ?int $rootId = null,
    ): AggregateFixResult {
        if ($definitions === []) {
            return new AggregateFixResult(totalRowsUpdated: 0, perColumn: []);
        }

        $rows = self::selectStoredAndComputed(
            connection: $connection,
            table: $table,
            lftCol: $lftCol,
            rgtCol: $rgtCol,
            scope: $scope,
            definitions: $definitions,
            rootId: $rootId,
        );

        $perColumn = [];
        foreach ($definitions as $definition) {
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

            foreach ($definitions as $definition) {
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
    ): array {
        if ($definitions === []) {
            return [];
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

        $sql = 'SELECT '.implode(', ', $outerSelects)
            ." FROM {$table} AS outer_a"
            .' LEFT JOIN ('
            .'SELECT '.implode(', ', $aggSelects)
            ." FROM {$table} AS o"
            ." INNER JOIN {$table} AS i ON {$joinClause}{$scopeJoinExtra}"
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
    private static function aggregatesEqual(mixed $a, mixed $b): bool
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
    private static function isMariaDb(Connection $connection): bool
    {
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
}
