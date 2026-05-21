<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Strategy;

use Illuminate\Database\Connection;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\VarianceSqlFragments;
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
     * @param  array<string, array{sum: string, sum_sq: string, count: string, function: AggregateFunction, sample: bool}>  $variances
     *                                                                                                                                  variance/stddev_display_col => {companion columns, function: Variance|Stddev, sample}
     * @param  array<string, array{sum_wx: string, sum_w: string}>  $weightedAvgs
     *                                                                             weighted-avg display column => companion column names
     * @param  array<string, array{sum: string, count: string, function: AggregateFunction}>  $bools
     *                                                                                                boolOr/boolAnd display column => companion columns + which function
     * @param  array<string, array{sum_companion: string, count: string, function: AggregateFunction, allowNonPositive: bool}>  $means
     *                                                                                                                                  geometricMean/harmonicMean display column => companion columns + which function
     * @param  array<string, array{function: AggregateFunction, value: int|float}>  $extremes
     *                                                                                         cheap-delta candidates: aggregate column =>
     *                                                                                         {function: Min|Max, value: candidate}.
     * @param  array<string, array{function: AggregateFunction, value: int|float}>  $bitwise
     *                                                                                        bitwise SET clauses: aggregate column => {function:
     *                                                                                        BitOr|BitXor, value: int|float}. BitOr emits `col = COALESCE(col, 0) | v`
     *                                                                                        (NULL-safe — empty subtree starts at 0 once a row contributes);
     *                                                                                        BitXor emits `col = COALESCE(col, 0) ^ v` with the same NULL handling.
     *                                                                                        Values are coerced to integer at SET-clause emission — bitwise
     *                                                                                        operators only have well-defined semantics on integers.
     *                                                                                        BitAnd never appears here — it always routes through recompute.
     * @param  array<string, mixed>  $scope  column => value, applied as equality WHEREs
     * @param  string|null  $softDeletedColumn  when set, restricts the UPDATE to rows where this
     *                                          column is NULL. Snapshot semantics for soft-deleted
     *                                          trees: per-mutation deltas don't touch trashed
     *                                          ancestors so their stored aggregates stay frozen
     *                                          at trash time. Restore-time recompute re-syncs the
     *                                          restored subtree from live descendants.
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
        array $bitwise = [],
        ?string $softDeletedColumn = null,
        array $variances = [],
        array $weightedAvgs = [],
        array $bools = [],
        array $means = [],
    ): int {
        // Order matters on MySQL / MariaDB: SET clauses are evaluated
        // left-to-right with each prior assignment visible to later
        // ones. The derived display columns (AVG, Variance, Stddev,
        // WeightedAvg, Bool, GeometricMean, HarmonicMean) reference the
        // companion columns, which are themselves being delta-updated in
        // this same statement — so we must emit derived display columns
        // FIRST while the companions still hold their pre-update values.
        // Adding the delta inside each derived expression then produces
        // the correct new value. PostgreSQL and SQLite evaluate all SET
        // clauses against pre-update values regardless of order, so the
        // same ordering is correct for them.
        $setExpressions = array_merge(
            self::buildAvgSetClauses($deltas, $avgs),
            self::buildVarianceSetClauses($deltas, $variances),
            self::buildWeightedAvgSetClauses($deltas, $weightedAvgs),
            self::buildBoolSetClauses($deltas, $bools),
            self::buildMeanSetClauses($deltas, $means),
            self::buildDeltaSetClauses($deltas),
            self::buildExtremeSetClauses($extremes),
            self::buildBitwiseSetClauses($bitwise),
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

        if ($softDeletedColumn !== null) {
            $query->whereNull($softDeletedColumn);
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

    /**
     * Build SET clauses for variance / stddev display columns. Each
     * clause references the column's three companions ({sum}, {sum_sq},
     * {count}), substituting `column + Δ` for any companion that's
     * being delta-updated in the same statement. The portable
     * companion-based formula from {@see VarianceSqlFragments} then
     * yields the new variance / stddev value.
     *
     * @param  array<string, int|float>  $deltas
     * @param  array<string, array{sum: string, sum_sq: string, count: string, function: AggregateFunction, sample: bool}>  $variances
     * @return array<string, TreeExpression>
     */
    private static function buildVarianceSetClauses(array $deltas, array $variances): array
    {
        $clauses = [];

        foreach ($variances as $displayCol => $spec) {
            $sumExpression = self::columnPlusDelta($spec['sum'], $deltas[$spec['sum']] ?? 0);
            $sumSqExpression = self::columnPlusDelta($spec['sum_sq'], $deltas[$spec['sum_sq']] ?? 0);
            $countExpression = self::columnPlusDelta($spec['count'], $deltas[$spec['count']] ?? 0);

            $clauses[$displayCol] = new TreeExpression(
                $spec['function'] === AggregateFunction::Stddev
                    ? VarianceSqlFragments::stddev($sumExpression, $sumSqExpression, $countExpression, $spec['sample'])
                    : VarianceSqlFragments::variance($sumExpression, $sumSqExpression, $countExpression, $spec['sample']),
            );
        }

        return $clauses;
    }

    /**
     * Build SET clauses for weighted-average display columns. Each
     * clause expresses `(sum_wx + Δ_sum_wx) / NULLIF(sum_w + Δ_sum_w, 0)`
     * — NULL when no contributing row carries any weight (matches
     * SQL's `0 / 0 = NULL` convention).
     *
     * @param  array<string, int|float>  $deltas
     * @param  array<string, array{sum_wx: string, sum_w: string}>  $weightedAvgs
     * @return array<string, TreeExpression>
     */
    private static function buildWeightedAvgSetClauses(array $deltas, array $weightedAvgs): array
    {
        $clauses = [];

        foreach ($weightedAvgs as $displayCol => $companions) {
            $sumWxExpression = self::columnPlusDelta($companions['sum_wx'], $deltas[$companions['sum_wx']] ?? 0);
            $sumWExpression = self::columnPlusDelta($companions['sum_w'], $deltas[$companions['sum_w']] ?? 0);

            $clauses[$displayCol] = new TreeExpression(
                "(1.0 * ({$sumWxExpression})) / NULLIF(({$sumWExpression}), 0)",
            );
        }

        return $clauses;
    }

    /**
     * Build SET clauses for boolOr / boolAnd display columns. Each
     * clause is `CASE WHEN (count + Δcount) = 0 THEN NULL
     *                WHEN (sum + Δsum) > 0   THEN TRUE  ELSE FALSE END`
     * for BoolOr, and
     *               `CASE WHEN (count + Δcount) = 0 THEN NULL
     *                WHEN (sum + Δsum) = (count + Δcount) THEN TRUE  ELSE FALSE END`
     * for BoolAnd. TRUE / FALSE are portable across the four supported
     * backends (PG: native; MySQL / MariaDB / SQLite: aliases for 1 / 0).
     *
     * @param  array<string, int|float>  $deltas
     * @param  array<string, array{sum: string, count: string, function: AggregateFunction}>  $bools
     * @return array<string, TreeExpression>
     */
    private static function buildBoolSetClauses(array $deltas, array $bools): array
    {
        $clauses = [];

        foreach ($bools as $displayCol => $spec) {
            $sumExpression = self::columnPlusDelta($spec['sum'], $deltas[$spec['sum']] ?? 0);
            $countExpression = self::columnPlusDelta($spec['count'], $deltas[$spec['count']] ?? 0);

            $resultExpression = $spec['function'] === AggregateFunction::BoolAnd
                ? "({$sumExpression}) = ({$countExpression})"
                : "({$sumExpression}) > 0";

            $clauses[$displayCol] = new TreeExpression(
                "CASE WHEN ({$countExpression}) = 0 THEN NULL "
                ."WHEN {$resultExpression} THEN TRUE ELSE FALSE END",
            );
        }

        return $clauses;
    }

    /**
     * Build SET clauses for geometric / harmonic mean display columns.
     *
     * GeometricMean: `EXP((sum_log + Δ) / NULLIF(count + Δ, 0))`
     * HarmonicMean:  `NULLIF(count + Δ, 0) / NULLIF((sum_recip + Δ), 0)`
     *
     * Both formulas reference the pre-update companion values augmented
     * by the in-flight deltas — the same "emit display first" ordering
     * that AVG uses.
     *
     * @param  array<string, int|float>  $deltas
     * @param  array<string, array{sum_companion: string, count: string, function: AggregateFunction, allowNonPositive: bool}>  $means
     * @return array<string, TreeExpression>
     */
    private static function buildMeanSetClauses(array $deltas, array $means): array
    {
        $clauses = [];

        foreach ($means as $displayCol => $spec) {
            $sumExpr = self::columnPlusDelta($spec['sum_companion'], $deltas[$spec['sum_companion']] ?? 0);
            $countExpr = self::columnPlusDelta($spec['count'], $deltas[$spec['count']] ?? 0);

            $clause = $spec['function'] === AggregateFunction::GeometricMean
                ? "EXP(({$sumExpr}) / NULLIF(({$countExpr}), 0))"
                : "NULLIF(({$countExpr}), 0) / NULLIF(({$sumExpr}), 0)";

            $clauses[$displayCol] = new TreeExpression($clause);
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
     * Builds SET clauses for bitwise BitOr and BitXor delta paths. The
     * column is COALESCE'd to 0 because bitwise display columns are
     * nullable — an empty subtree reads NULL, and the first row's
     * contribution promotes it to a concrete integer.
     *
     * BitXor is the unusual one: it's emitted by both the insert and
     * delete capture paths because XOR is self-inverse (`(x ^ a) ^ a = x`).
     * BitOr emits only on insert (`col |= new`); deletes route through
     * RecomputeMaintenance because a lost bit can't be derived from
     * the rolled-up value alone.
     *
     * @param  array<string, array{function: AggregateFunction, value: int|float}>  $bitwise
     * @return array<string, TreeExpression>
     */
    private static function buildBitwiseSetClauses(array $bitwise): array
    {
        $clauses = [];

        foreach ($bitwise as $column => $spec) {
            $value = (int) $spec['value'];
            $current = "COALESCE({$column}, 0)";

            // BitOr is straightforward — every backend has `|`. BitXor
            // uses the identity `a XOR b = (a | b) - (a & b)` so the
            // delta works on SQLite (no XOR operator) and PostgreSQL
            // (where `^` is exponentiation, not XOR). MySQL/MariaDB
            // would happily emit `a ^ b` but the portable form costs
            // one extra arithmetic op per row — negligible alongside
            // the index-driven ancestor scan.
            $expression = match ($spec['function']) {
                AggregateFunction::BitOr => "{$current} | {$value}",
                AggregateFunction::BitXor => "({$current} | {$value}) - ({$current} & {$value})",
                default => throw new \LogicException(sprintf(
                    'buildBitwiseSetClauses: unsupported bitwise function %s for delta path '
                    .'(BitAnd always routes through recompute).',
                    $spec['function']->value,
                )),
            };

            $clauses[$column] = new TreeExpression($expression);
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
