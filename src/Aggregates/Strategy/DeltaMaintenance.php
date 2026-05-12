<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Strategy;

use Illuminate\Database\Connection;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Query\TreeExpression;

/**
 * Issues delta-based UPDATE statements for SUM and COUNT aggregate
 * maintenance. Phase D's hot path: one extra `UPDATE` per mutation,
 * scoped to a node's ancestor chain (and optionally self).
 *
 * SQL shape matches AGGREGATES.md §5.1:
 *
 *     UPDATE areas
 *     SET tickets_total = tickets_total + :delta_sum,
 *         tickets_count = tickets_count + :delta_count
 *     WHERE lft <= :node_lft AND rgt >= :node_rgt
 *       AND /* optionally exclude self *\/
 *       AND /* scope conditions *\/;
 *
 * Self is included for source-column updates and inserts (where self's
 * own stored aggregate also needs to move) and excluded for deletes
 * (where the row is gone for hard delete or stale for soft delete).
 */
final class DeltaMaintenance
{
    /**
     * Applies a set of deltas to the ancestor chain (and optionally
     * self) of $bounds inside $scope. Negative deltas are supported.
     *
     * When `$avgs` is non-empty, additional SET clauses are appended that
     * recompute each AVG display column as
     * `(sum_col + Δ_sum) / NULLIF(count_col + Δ_count, 0)` using OLD
     * companion values + the same deltas being applied — the formula
     * therefore stays correct regardless of whether the database
     * evaluates SET clauses left-to-right (MySQL/MariaDB) or against
     * pre-statement values (PostgreSQL).
     *
     * @param  array<string, int>  $deltas  column => signed integer delta
     * @param  array<string, array{sum: string, count: string}>  $avgs
     *                                                                  avg_display_col => {sum companion, count companion}
     * @param  array<string, mixed>  $scope  column => value, applied as equality WHEREs
     */
    public static function apply(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        NodeBounds $bounds,
        array $deltas,
        bool $includeSelf,
        array $scope = [],
        array $avgs = [],
    ): int {
        $setExpressions = self::buildDeltaSetClauses($deltas);
        $setExpressions = array_merge(
            $setExpressions,
            self::buildAvgSetClauses($deltas, $avgs),
        );

        if ($setExpressions === []) {
            return 0;
        }

        $query = $connection->table($table)
            ->where($lftCol, '<=', $bounds->lft)
            ->where($rgtCol, '>=', $bounds->rgt);

        if (! $includeSelf) {
            // Exclude exactly the row matching this node's bounds. Within
            // a scope, the (lft, rgt) pair uniquely identifies one row.
            $query->where(function ($q) use ($lftCol, $rgtCol, $bounds): void {
                $q->where($lftCol, '!=', $bounds->lft)
                    ->orWhere($rgtCol, '!=', $bounds->rgt);
            });
        }

        foreach ($scope as $column => $value) {
            $query->where($column, '=', $value);
        }

        return $query->update($setExpressions);
    }

    /**
     * @param  array<string, int>  $deltas
     * @return array<string, TreeExpression>
     */
    private static function buildDeltaSetClauses(array $deltas): array
    {
        $clauses = [];

        foreach ($deltas as $column => $delta) {
            if ($delta === 0) {
                continue;
            }

            $sign = $delta >= 0 ? '+' : '-';
            $abs = abs($delta);

            $clauses[$column] = new TreeExpression("{$column} {$sign} {$abs}");
        }

        return $clauses;
    }

    /**
     * @param  array<string, int>  $deltas
     * @param  array<string, array{sum: string, count: string}>  $avgs
     * @return array<string, TreeExpression>
     */
    private static function buildAvgSetClauses(array $deltas, array $avgs): array
    {
        $clauses = [];

        foreach ($avgs as $avgCol => $companions) {
            $sumCol = $companions['sum'];
            $countCol = $companions['count'];

            $sumExpression = self::columnPlusDelta($sumCol, $deltas[$sumCol] ?? 0);
            $countExpression = self::columnPlusDelta($countCol, $deltas[$countCol] ?? 0);

            // `1.0 *` forces decimal arithmetic. Without it SQLite and
            // PostgreSQL truncate integer/integer division to integer
            // (so SUM=175 / COUNT=3 yields 58 instead of 58.333…).
            // MySQL/MariaDB already widen but the multiplier is harmless.
            $clauses[$avgCol] = new TreeExpression(
                "(1.0 * ({$sumExpression})) / NULLIF(({$countExpression}), 0)",
            );
        }

        return $clauses;
    }

    private static function columnPlusDelta(string $column, int $delta): string
    {
        if ($delta === 0) {
            return $column;
        }

        $sign = $delta > 0 ? '+' : '-';
        $abs = abs($delta);

        return "{$column} {$sign} {$abs}";
    }
}
