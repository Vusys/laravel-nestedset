<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Strategy;

use Illuminate\Database\Connection;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\NodeBounds;

/**
 * Phase F: recomputes MIN / MAX for each ancestor whose stored
 * extremum may have been invalidated by the change.
 *
 * Strategy: two-step SELECT-then-UPDATE rather than a single
 * correlated-subquery UPDATE. The latter is the cleanest expression
 * (AGGREGATES.md §5.2) but trips MySQL's "you can't update a table
 * and select from it in a subquery" rule. SELECT + UPDATE works on
 * every supported backend; the SELECT is one round-trip that returns
 * every affected ancestor's recomputed value per declared column.
 *
 * Cheap-skip: callers pass `$filterEquals` — pairs of
 * `(aggregate_column, previous_value)` ORed together. Ancestors whose
 * stored extremum demonstrably did not match the changing/deleted
 * value are not selected and therefore not touched. This is what
 * makes "delete a non-extremum value → no recompute" possible.
 */
final class RecomputeMaintenance
{
    /**
     * @param  list<array{column: string, function: AggregateFunction, source: string, inclusive: bool}>  $columns
     * @param  array<string, mixed>  $scope
     * @param  array<string, int|float|string>  $filterEquals
     *                                                         column => previous_value pairs ORed into the WHERE.
     *                                                         Empty → no extra filter (every ancestor recomputes).
     * @param  'always'|'auto'|'never'  $locking
     *                                            Controls whether the recompute SELECT is issued with
     *                                            FOR UPDATE. 'always' and 'auto' both lock here; 'never'
     *                                            skips.
     */
    public static function apply(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        NodeBounds $bounds,
        array $columns,
        array $scope = [],
        array $filterEquals = [],
        string $locking = 'auto',
    ): int {
        if ($columns === []) {
            return 0;
        }

        $candidates = self::candidatesForRecompute(
            connection: $connection,
            table: $table,
            lftCol: $lftCol,
            rgtCol: $rgtCol,
            bounds: $bounds,
            columns: $columns,
            scope: $scope,
            filterEquals: $filterEquals,
            locking: $locking,
        );

        if ($candidates === []) {
            return 0;
        }

        return self::writeRecomputedValues(
            connection: $connection,
            table: $table,
            columns: $columns,
            candidates: $candidates,
        );
    }

    /**
     * @param  list<array{column: string, function: AggregateFunction, source: string, inclusive: bool}>  $columns
     * @param  array<string, mixed>  $scope
     * @param  array<string, int|float|string>  $filterEquals
     * @param  'always'|'auto'|'never'  $locking
     * @return list<array<string, mixed>>
     */
    private static function candidatesForRecompute(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        NodeBounds $bounds,
        array $columns,
        array $scope,
        array $filterEquals,
        string $locking,
    ): array {
        $selects = ['outer_a.id'];

        foreach ($columns as $i => $spec) {
            $alias = self::recomputeAlias($i);
            $boundsClause = $spec['inclusive']
                ? "inner_a.{$lftCol} >= outer_a.{$lftCol} AND inner_a.{$rgtCol} <= outer_a.{$rgtCol}"
                : "inner_a.{$lftCol} > outer_a.{$lftCol} AND inner_a.{$rgtCol} < outer_a.{$rgtCol}";

            $scopeJoin = '';
            foreach (array_keys($scope) as $col) {
                $scopeJoin .= " AND inner_a.{$col} = outer_a.{$col}";
            }

            $func = $spec['function'] === AggregateFunction::Max ? 'MAX' : 'MIN';
            $source = $spec['source'];

            $selects[] = "(SELECT {$func}(inner_a.{$source}) FROM {$table} AS inner_a "
                ."WHERE {$boundsClause}{$scopeJoin}) AS {$alias}";
        }

        $where = "outer_a.{$lftCol} <= ? AND outer_a.{$rgtCol} >= ?";
        $bindings = [$bounds->lft, $bounds->rgt];

        foreach ($scope as $col => $value) {
            $where .= " AND outer_a.{$col} = ?";
            $bindings[] = $value;
        }

        if ($filterEquals !== []) {
            $parts = [];
            foreach ($filterEquals as $col => $value) {
                $parts[] = "outer_a.{$col} = ?";
                $bindings[] = $value;
            }
            $where .= ' AND ('.implode(' OR ', $parts).')';
        }

        $sql = 'SELECT '.implode(', ', $selects)
            ." FROM {$table} AS outer_a WHERE {$where}";

        if ($locking !== 'never') {
            $sql .= self::forUpdateClause($connection);
        }

        $rows = $connection->select($sql, $bindings);

        $result = [];
        foreach ($rows as $row) {
            $result[] = (array) $row;
        }

        return $result;
    }

    /**
     * SQLite has no row-level locking — `FOR UPDATE` is a no-op there
     * and produces a syntax error. Detect SQLite and skip; on the
     * other three backends, append the standard SQL clause.
     */
    private static function forUpdateClause(Connection $connection): string
    {
        if ($connection->getDriverName() === 'sqlite') {
            return '';
        }

        return ' FOR UPDATE';
    }

    /**
     * @param  list<array{column: string, function: AggregateFunction, source: string, inclusive: bool}>  $columns
     * @param  list<array<string, mixed>>  $candidates
     */
    private static function writeRecomputedValues(
        Connection $connection,
        string $table,
        array $columns,
        array $candidates,
    ): int {
        $touched = 0;

        foreach ($candidates as $row) {
            $updates = [];
            foreach ($columns as $i => $spec) {
                $alias = self::recomputeAlias($i);
                $updates[$spec['column']] = $row[$alias] ?? null;
            }

            $id = $row['id'] ?? null;
            if ($id === null) {
                continue;
            }

            $touched += $connection->table($table)
                ->where('id', '=', $id)
                ->update($updates);
        }

        return $touched;
    }

    private static function recomputeAlias(int $index): string
    {
        return 'recompute_'.$index;
    }
}
