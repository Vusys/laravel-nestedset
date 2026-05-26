<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

/**
 * Transformation applied to a companion's source value before it
 * feeds into the underlying delta function (Sum / Count).
 *
 * AVG's companions are plain `Sum(source)` and `Count(source)` — no
 * transformation. Variance / Stddev introduce a `Sum(source * source)`
 * companion ("sum of squares"); the transformation lives here rather
 * than on the companion column itself so the column reference stays
 * a plain column name and the delta machinery can still derive a
 * signed delta from the (transformed) old and new source values.
 *
 * Both PHP-side (delta capture) and SQL-side (recompute / fresh
 * subqueries) carry the transformation, so the two sides stay
 * arithmetically equivalent for an arbitrary contributor row.
 */
enum CompanionSourceTransform
{
    case Identity;
    case Square;

    /**
     * Apply the transformation to a numeric source value in PHP. Used by
     * the delta-capture path when computing the contribution of a single
     * row to the companion column.
     */
    public function applyPhp(int|float $value): int|float
    {
        return match ($this) {
            self::Identity => $value,
            self::Square => $value * $value,
        };
    }

    /**
     * SQL fragment that yields the transformed value of $columnRef.
     * Wrapped in parentheses so the result is safe to nest inside
     * outer SUM(...) / SUM(CASE WHEN ...) expressions without
     * operator-precedence surprises.
     */
    public function applySqlFragment(string $columnRef): string
    {
        return match ($this) {
            self::Identity => $columnRef,
            self::Square => "({$columnRef} * {$columnRef})",
        };
    }
}
