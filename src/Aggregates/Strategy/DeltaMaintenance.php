<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Strategy;

use Illuminate\Database\Connection;
use Vusys\NestedSet\Aggregates\AggregateFunction;
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
     * When `$avgs` is non-empty, additional SET clauses are appended
     * that recompute each AVG display column as
     * `(sum_col + Δ_sum) / NULLIF(count_col + Δ_count, 0)` using OLD
     * companion values + the same deltas being applied — the formula
     * therefore stays correct regardless of whether the database
     * evaluates SET clauses left-to-right (MySQL/MariaDB) or against
     * pre-statement values (PostgreSQL).
     *
     * When `$extremes` is non-empty, additional SET clauses extend
     * MIN/MAX columns via `CASE WHEN col IS NULL OR candidate {</|>} col
     * THEN candidate ELSE col END`. Phase F's cheap-delta path uses
     * this when the change can only extend the extremum (insert, or
     * source-update where new is more extreme than old).
     *
     * @param  array<string, int|float>  $deltas  column => signed delta. Listener
     *                                            aggregates may pass floats; SQL aggregates
     *                                            over integer source columns pass ints.
     * @param  array<string, array{sum: string, count: string}>  $avgs
     *                                                                  avg_display_col => {sum companion, count companion}
     * @param  array<string, array{function: AggregateFunction, value: int|float}>  $extremes
     *                                                                                         cheap-delta candidates: aggregate column =>
     *                                                                                         {function: Min|Max, value: candidate}.
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
        array $extremes = [],
    ): int {
        // Order matters on MySQL / MariaDB: SET clauses are evaluated
        // left-to-right with each prior assignment visible to later
        // ones. The AVG formula references the SUM and COUNT
        // companions, which are themselves being delta-updated in this
        // same statement — so we must emit AVG FIRST while those
        // columns still hold their pre-update values. Adding the delta
        // inside the AVG expression then produces the correct new
        // value. PostgreSQL and SQLite evaluate all SET clauses
        // against pre-update values regardless of order, so the same
        // ordering is correct for them.
        $setExpressions = array_merge(
            self::buildAvgSetClauses($deltas, $avgs),
            self::buildDeltaSetClauses($deltas),
            self::buildExtremeSetClauses($extremes),
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
     * @param  array<string, int|float>  $deltas
     * @return array<string, TreeExpression>
     */
    private static function buildDeltaSetClauses(array $deltas): array
    {
        $clauses = [];

        foreach ($deltas as $column => $delta) {
            if ($delta == 0) {
                continue;
            }

            $sign = $delta >= 0 ? '+' : '-';
            $abs = self::formatNumeric(abs($delta));

            $clauses[$column] = new TreeExpression("{$column} {$sign} {$abs}");
        }

        return $clauses;
    }

    /**
     * @param  array<string, int|float>  $deltas
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

    private static function columnPlusDelta(string $column, int|float $delta): string
    {
        if ($delta == 0) {
            return $column;
        }

        $sign = $delta > 0 ? '+' : '-';
        $abs = self::formatNumeric(abs($delta));

        return "{$column} {$sign} {$abs}";
    }

    /**
     * Builds cheap-delta MIN/MAX SET clauses. Each clause expresses
     * "if the candidate is more extreme than the stored value (or the
     * stored value is NULL), use the candidate; otherwise keep stored".
     * Portable across SQLite / MySQL / MariaDB / PostgreSQL — no LEAST
     * / GREATEST dependency.
     *
     * @param  array<string, array{function: AggregateFunction, value: int|float}>  $extremes
     * @return array<string, TreeExpression>
     */
    private static function buildExtremeSetClauses(array $extremes): array
    {
        $clauses = [];

        foreach ($extremes as $column => $spec) {
            $value = self::formatNumeric($spec['value']);
            $operator = $spec['function'] === AggregateFunction::Max ? '>' : '<';

            $clauses[$column] = new TreeExpression(
                "CASE WHEN {$column} IS NULL OR {$value} {$operator} {$column} THEN {$value} ELSE {$column} END",
            );
        }

        return $clauses;
    }

    /**
     * Format a numeric for direct interpolation into SQL. PHP's default
     * float-to-string conversion respects the runtime `precision` ini
     * setting and can emit scientific notation (`1.0E-5`) on extreme
     * values; using a locale-independent decimal form keeps the SQL
     * portable across MySQL/MariaDB/PostgreSQL/SQLite.
     */
    private static function formatNumeric(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        // 14 significant digits matches PHP's default serialize precision
        // without crossing into scientific notation for typical magnitudes.
        $formatted = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
