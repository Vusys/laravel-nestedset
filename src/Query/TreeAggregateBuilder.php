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

        $totalRowsUpdated = 0;

        foreach ($rows as $row) {
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

            if ($updates === []) {
                continue;
            }

            $id = $row['id'] ?? null;
            if ($id === null) {
                continue;
            }

            $connection->table($table)
                ->where('id', '=', $id)
                ->update($updates);

            $totalRowsUpdated++;
        }

        return new AggregateFixResult(
            totalRowsUpdated: $totalRowsUpdated,
            perColumn: $perColumn,
        );
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
     * One self-JOIN + GROUP BY that computes every aggregate in
     * `$definitions` for every outer row in scope, in a single
     * statement. All definitions must share the same inclusivity.
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
        $selects = ['outer_a.id AS id'];
        $groupBys = ['outer_a.id'];

        foreach ($definitions as $definition) {
            $innerExpr = self::aggregateExpression($definition, qualifier: 'inner_a.');
            $selects[] = "{$innerExpr} AS ".self::computedAlias($definition->column);
            $selects[] = "outer_a.{$definition->column} AS ".self::storedAlias($definition->column);

            // Add stored columns to GROUP BY so MySQL ONLY_FULL_GROUP_BY
            // and PostgreSQL's strict grouping accept the SELECT.
            $groupBys[] = "outer_a.{$definition->column}";
        }

        $joinClause = $inclusive
            ? "inner_a.{$lftCol} >= outer_a.{$lftCol} AND inner_a.{$rgtCol} <= outer_a.{$rgtCol}"
            : "inner_a.{$lftCol} > outer_a.{$lftCol} AND inner_a.{$rgtCol} < outer_a.{$rgtCol}";

        $scopeJoinExtra = '';
        foreach (array_keys($scope) as $col) {
            $scopeJoinExtra .= " AND inner_a.{$col} = outer_a.{$col}";
        }

        $where = '1 = 1';
        $bindings = [];

        foreach ($scope as $col => $value) {
            $where .= " AND outer_a.{$col} = ?";
            $bindings[] = $value;
        }

        if ($rootId !== null) {
            $where .= " AND outer_a.{$lftCol} >= "
                ."(SELECT {$lftCol} FROM {$table} WHERE id = ?)";
            $where .= " AND outer_a.{$rgtCol} <= "
                ."(SELECT {$rgtCol} FROM {$table} WHERE id = ?)";
            $bindings[] = $rootId;
            $bindings[] = $rootId;
        }

        $sql = 'SELECT '.implode(', ', $selects)
            ." FROM {$table} AS outer_a"
            ." LEFT JOIN {$table} AS inner_a"
            ." ON {$joinClause}{$scopeJoinExtra}"
            ." WHERE {$where}"
            .' GROUP BY '.implode(', ', $groupBys);

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
}
