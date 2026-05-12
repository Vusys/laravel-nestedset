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
     * @param  array<string, int>  $deltas  column => signed integer delta
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
    ): int {
        if ($deltas === []) {
            return 0;
        }

        $setExpressions = [];

        foreach ($deltas as $column => $delta) {
            if ($delta === 0) {
                continue;
            }

            $sign = $delta >= 0 ? '+' : '-';
            $abs = abs($delta);

            $setExpressions[$column] = new TreeExpression("{$column} {$sign} {$abs}");
        }

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
}
