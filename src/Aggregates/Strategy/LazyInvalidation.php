<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Strategy;

use Illuminate\Database\Connection;
use Vusys\NestedSet\Concerns\HasNestedSetAggregates;
use Vusys\NestedSet\NodeBounds;

/**
 * Issues one UPDATE (per inclusivity slice) that nulls the value
 * column and its `<column>_computed_at` stamp on every ancestor of
 * the changed node. The first read past the invalidation runs
 * {@see HasNestedSetAggregates::freshAggregate()}
 * and stamps the companion.
 *
 *     UPDATE areas
 *     SET revenue_total            = NULL,
 *         revenue_total_computed_at = NULL,
 *         comments_count           = NULL,
 *         comments_count_computed_at = NULL
 *     WHERE lft <= :node_lft AND rgt >= :node_rgt    /* inclusive *\/
 *       AND /* scope conditions *\/;
 *
 * Exclusive lazy columns get a stricter WHERE (`lft < ? AND rgt > ?`)
 * since the node itself doesn't contribute to its own exclusive
 * descendant rollup; only its ancestors do. Inclusive and exclusive
 * sets land in separate UPDATEs to keep the WHERE comparison shared
 * across all columns in each slice.
 *
 * Soft-delete semantics mirror {@see DeltaMaintenance}: trashed
 * ancestors stay frozen and are skipped by the invalidation; the
 * restore hook re-routes invalidation onto the restored chain.
 */
final class LazyInvalidation
{
    /**
     * @param  list<array{column: string, stampColumn: string, inclusive: bool}>  $columns
     * @param  array<string, mixed>  $scope
     * @param  bool  $excludeSelf  When true, every spec is forced to use
     *                             strict bounds (`<` / `>`) regardless of
     *                             per-spec inclusivity, so the row at
     *                             `$bounds` itself is skipped. Used by the
     *                             restore hook, where {@see
     *                             \Vusys\NestedSet\Concerns\HasNestedSetAggregates::applyAggregateOnRestore()}
     *                             already populated self's lazy columns
     *                             via `fixAggregates()` and only the
     *                             proper ancestors need invalidating.
     */
    public static function apply(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        NodeBounds $bounds,
        array $columns,
        array $scope = [],
        ?string $softDeletedColumn = null,
        bool $excludeSelf = false,
    ): int {
        if ($columns === []) {
            return 0;
        }

        if ($excludeSelf) {
            // Force every spec into the strict-bounds slice — proper
            // ancestors only. Per-spec inclusivity is irrelevant when
            // the caller knows self is already fresh.
            return self::issue(
                connection: $connection,
                table: $table,
                lftCol: $lftCol,
                rgtCol: $rgtCol,
                bounds: $bounds,
                columns: $columns,
                scope: $scope,
                softDeletedColumn: $softDeletedColumn,
                strict: true,
            );
        }

        $inclusive = [];
        $exclusive = [];

        foreach ($columns as $spec) {
            if ($spec['inclusive']) {
                $inclusive[] = $spec;
            } else {
                $exclusive[] = $spec;
            }
        }

        $touched = 0;

        if ($inclusive !== []) {
            $touched += self::issue(
                connection: $connection,
                table: $table,
                lftCol: $lftCol,
                rgtCol: $rgtCol,
                bounds: $bounds,
                columns: $inclusive,
                scope: $scope,
                softDeletedColumn: $softDeletedColumn,
                strict: false,
            );
        }

        if ($exclusive !== []) {
            $touched += self::issue(
                connection: $connection,
                table: $table,
                lftCol: $lftCol,
                rgtCol: $rgtCol,
                bounds: $bounds,
                columns: $exclusive,
                scope: $scope,
                softDeletedColumn: $softDeletedColumn,
                strict: true,
            );
        }

        return $touched;
    }

    /**
     * @param  list<array{column: string, stampColumn: string, inclusive: bool}>  $columns
     * @param  array<string, mixed>  $scope
     */
    private static function issue(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        NodeBounds $bounds,
        array $columns,
        array $scope,
        ?string $softDeletedColumn,
        bool $strict,
    ): int {
        $set = [];
        foreach ($columns as $spec) {
            $set[$spec['column']] = null;
            $set[$spec['stampColumn']] = null;
        }

        $query = $connection->table($table);

        if ($strict) {
            $query->where($lftCol, '<', $bounds->lft)
                ->where($rgtCol, '>', $bounds->rgt);
        } else {
            $query->where($lftCol, '<=', $bounds->lft)
                ->where($rgtCol, '>=', $bounds->rgt);
        }

        foreach ($scope as $column => $value) {
            $query->where($column, '=', $value);
        }

        if ($softDeletedColumn !== null) {
            $query->whereNull($softDeletedColumn);
        }

        return $query->update($set);
    }
}
