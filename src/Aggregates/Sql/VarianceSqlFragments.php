<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Sql;

use Vusys\NestedSet\Aggregates\Filters\BoundFragment;

/**
 * Builds portable SQL fragments for variance and stddev display columns
 * from their delta-maintained companions (Sum, SumSq, Count).
 *
 * The package uses the textbook `E[X²] − E[X]²` form rather than the
 * numerically stable Welford recurrence: it composes cleanly into the
 * existing companion infrastructure, the formula is the same one
 * databases use for native `VAR_POP` / `VAR_SAMP`, and the precision
 * loss is only material when values are tightly clustered around a
 * large mean (sensor timestamps, large monetary values with tiny
 * variation). For those cases the docs point readers at
 * `withFreshAggregates()` which delegates to the database's native
 * function. See `docs/aggregates/maths.md`.
 *
 * The fragments are constructed from three subexpressions that the
 * caller supplies — typically `SUM(col)`, `SUM(col*col)`, `COUNT(col)`
 * for fresh recomputes, or stored-column references plus deltas for
 * the in-place UPDATE path. Keeping the construction parametric means
 * the same formula serves every shape (correlated subquery, derived
 * table, leaf-inline, in-UPDATE SET clause) without duplicating the
 * arithmetic logic.
 *
 * Coalesced shape:
 *  - population variance: `(n * SS − S²) / NULLIF(n², 0)`
 *  - sample variance:     `(n * SS − S²) / NULLIF(n * (n − 1), 0)`
 *  - stddev: `SQRT(CASE WHEN var < 0 THEN 0 ELSE var END)`
 *
 * The `1.0 *` factor forces decimal arithmetic on SQLite and Postgres,
 * which otherwise truncate integer / integer division to integer (so
 * variance over `[2, 4]` would yield 0 instead of 1.0).
 *
 * The CASE-zero clamp on the stddev radicand absorbs the rare case
 * where `n * SS − S²` evaluates to a small negative number due to
 * floating-point cancellation (the failure mode the numerical-
 * stability caveat warns about). Without the clamp PG would error
 * with "cannot take square root of a negative number"; MySQL /
 * MariaDB / SQLite return NULL instead. Clamping yields 0 across
 * all four — the same answer Welford would compute for a constant
 * sequence.
 */
final class VarianceSqlFragments
{
    /**
     * Build the variance SQL fragment from the three companion
     * subexpressions. The result evaluates to NULL for empty subtrees
     * (denominator → 0) and, in sample mode, for n=1 subtrees too.
     *
     * Each companion may carry its own bindings (when wrapped in a
     * filter CASE). The result's bindings are concatenated in textual
     * occurrence order — count appears 3× (population) or 3× (sample),
     * sum 2×, sumSq 1× — so positional `?` alignment is preserved.
     */
    public static function variance(
        BoundFragment $sumExpr,
        BoundFragment $sumSqExpr,
        BoundFragment $countExpr,
        bool $sample,
    ): BoundFragment {
        $sum = "({$sumExpr->sql})";
        $sumSq = "({$sumSqExpr->sql})";
        $count = "({$countExpr->sql})";

        $numerator = "(1.0 * ({$count} * {$sumSq} - {$sum} * {$sum}))";
        $denominator = $sample
            ? "NULLIF({$count} * ({$count} - 1), 0)"
            : "NULLIF({$count} * {$count}, 0)";

        $sql = "({$numerator} / {$denominator})";

        // Textual occurrence order: count, sumSq, sum, sum, count, count.
        $bindings = [
            ...$countExpr->bindings,
            ...$sumSqExpr->bindings,
            ...$sumExpr->bindings,
            ...$sumExpr->bindings,
            ...$countExpr->bindings,
            ...$countExpr->bindings,
        ];

        return new BoundFragment($sql, $bindings);
    }

    /**
     * Build the stddev SQL fragment. Wraps {@see variance()} in a
     * CASE-zero clamp so a tiny-negative variance from floating-point
     * cancellation doesn't blow up SQRT on Postgres.
     */
    public static function stddev(
        BoundFragment $sumExpr,
        BoundFragment $sumSqExpr,
        BoundFragment $countExpr,
        bool $sample,
    ): BoundFragment {
        $varianceFragment = self::variance($sumExpr, $sumSqExpr, $countExpr, $sample);

        $sql = "SQRT(CASE WHEN {$varianceFragment->sql} < 0 THEN 0 ELSE {$varianceFragment->sql} END)";

        // variance is spliced twice (CASE-condition + ELSE branch).
        $bindings = [
            ...$varianceFragment->bindings,
            ...$varianceFragment->bindings,
        ];

        return new BoundFragment($sql, $bindings);
    }
}
