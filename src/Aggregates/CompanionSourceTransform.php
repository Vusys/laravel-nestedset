<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

/**
 * Transformation applied to a companion column's source value before it
 * feeds into the underlying delta function (Sum / Count).
 *
 * AVG's companions are plain `Sum(source)` and `Count(source)` — no
 * transformation. Weighted average, boolean rollups, and (in adjacent
 * milestones) variance / stddev introduce companions whose summed
 * expression is derived from one or two of the parent aggregate's
 * source columns; the transformation lives on the companion rather
 * than on the column itself so the column reference stays a plain
 * column name and the delta machinery can still derive a signed delta
 * from the (transformed) old and new source values.
 *
 * Both PHP-side (delta capture) and SQL-side (recompute / fresh
 * subqueries) carry the transformation, so the two sides stay
 * arithmetically equivalent for an arbitrary contributor row.
 */
enum CompanionSourceTransform
{
    case Identity;
    case TimesWeight;
    case AsInt;

    /**
     * Apply the transformation to a single contributor row's source
     * value (and weight value, where the transform consumes one).
     * Returns 0 when an input is missing so an unset weight or null
     * source row contributes nothing to the companion's sum — the
     * same effective semantics as `SUM(NULL) = 0` on every backend.
     *
     * Accepts `mixed` because raw `getAttribute()` reads on the
     * delta-capture path are untyped (the attribute could be int,
     * float, bool, the string from a decimal cast, or null). The
     * caller doesn't need to pre-narrow — this method does the
     * type-checking itself per transformation kind.
     */
    public function applyPhp(mixed $sourceValue, int|float|null $weightValue = null): int|float
    {
        return match ($this) {
            self::Identity => is_numeric($sourceValue) ? (float) $sourceValue : 0,
            self::TimesWeight => is_numeric($sourceValue) && is_numeric($weightValue)
                ? (float) $sourceValue * (float) $weightValue
                : 0,
            self::AsInt => self::truthy($sourceValue) ? 1 : 0,
        };
    }

    /**
     * Treat the value as a bool the same way the SQL `CASE WHEN c THEN
     * 1 ELSE 0 END` expression would. Bool true → true; numeric
     * non-zero → true; the canonical false markers (`'0'`, `'f'`,
     * `'false'`, empty string, null) → false; anything else → true.
     */
    private static function truthy(mixed $value): bool
    {
        if ($value === null || $value === false) {
            return false;
        }
        if ($value === true) {
            return true;
        }
        if (is_int($value) || is_float($value)) {
            return $value !== 0 && $value !== 0.0;
        }
        if (is_string($value)) {
            $trimmed = strtolower(trim($value));

            return ! in_array($trimmed, ['', '0', 'f', 'false'], true);
        }

        return false;
    }

    /**
     * SQL fragment that yields the transformed value of $sourceRef
     * (and $weightRef when {@see self::TimesWeight}). Wrapped in
     * parentheses so the result is safe to nest inside outer
     * `SUM(...)` / `SUM(CASE WHEN ...)` expressions without
     * operator-precedence surprises.
     */
    public function applySqlFragment(string $sourceRef, ?string $weightRef = null): string
    {
        return match ($this) {
            self::Identity => $sourceRef,
            self::TimesWeight => sprintf(
                '(%s * %s)',
                $weightRef ?? throw new \LogicException(
                    'CompanionSourceTransform::TimesWeight requires a weightRef.',
                ),
                $sourceRef,
            ),
            self::AsInt => sprintf('(CASE WHEN %s THEN 1 ELSE 0 END)', $sourceRef),
        };
    }

    /**
     * True when the transform reads the parent aggregate's weight
     * column in addition to (or instead of) its primary source. Used
     * by the delta-capture path to bail out cheaply when no weight has
     * been declared yet.
     */
    public function requiresWeight(): bool
    {
        return $this === self::TimesWeight;
    }
}
