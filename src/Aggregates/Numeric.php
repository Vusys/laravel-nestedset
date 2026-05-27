<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

/**
 * Numeric coercion helpers for the aggregate maintenance path.
 *
 * The trait reads raw column values via `getAttribute()` /
 * `getOriginal()`, which can return any of int, float, decimal-cast
 * string, integer-typed string ("10"), null, or other types depending
 * on the model's casts and the underlying driver. The maintenance
 * code needs precise type contracts:
 *
 *  - Delta arithmetic uses int when the underlying column is integer,
 *    float when it isn't — mixing the two silently truncates.
 *  - Stored aggregate reads must preserve fractional values (a Sum
 *    over `decimal(10,2)` columns is itself fractional).
 *  - Weight-bearing companions must distinguish "no weight recorded"
 *    (null → skip) from "weight = 0" (0 → still skip but explicit).
 *
 * Each helper encodes one of those contracts. The names are chosen to
 * read at the call site without needing to look at the implementation:
 * `asIntOrZero` says "give me an int, default 0", `asNumericOrNull`
 * says "give me whatever numeric type the value already has, or null",
 * and so on.
 *
 * Picking the wrong helper is a known footgun — see the regression
 * test in `tests/Feature/Aggregates/FilteredDeltaMaintenanceTest.php`
 * for what happens when a NULL stored value coerces to 0 instead of
 * null and propagates as a fake candidate extreme.
 */
final class Numeric
{
    /**
     * Narrow to int; null and non-numeric values collapse to 0. Use
     * when the column is structurally an integer (lft, rgt, depth,
     * integer-typed aggregate sources). Mirrors `NodeTrait::intAttr()`
     * but tolerant of null so unset/never-saved attributes default to
     * zero rather than throwing.
     */
    public static function asIntOrZero(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Return the value's natural numeric type (int or float) or null.
     * Distinguishes "no weight recorded" (no contribution) from
     * "weight = 0" (also no contribution but arithmetically explicit)
     * — used by the weighted-average delta path and companion source
     * transforms.
     */
    public static function asNumericOrNull(mixed $value): int|float|null
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (! is_numeric($value)) {
            return null;
        }

        // String → number with PHP auto-juggle: "5" → 5 (int),
        // "5.5" → 5.5 (float). Preserves the column's natural type.
        return $value + 0;
    }

    /**
     * Return the value's natural numeric type (int or float), or 0
     * for null/non-numeric. Used when reading stored aggregate
     * columns whose contents may be int or fractional depending on
     * the model's casts (decimal-cast columns come back as strings).
     */
    public static function asNumericOrZero(mixed $value): int|float
    {
        if ($value === null) {
            return 0;
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (! is_numeric($value)) {
            return 0;
        }

        // Decimal-cast strings: keep as float only when fractional or
        // exponent-bearing. "10" → 10 (int), "10.5" → 10.5 (float),
        // "1e2" → 100.0 (float).
        $string = $value;

        return str_contains($string, '.') || str_contains($string, 'e') || str_contains($string, 'E')
            ? (float) $value
            : (int) $value;
    }

    /**
     * Pass through a listener's contribution value; null collapses to
     * 0 (int) so a "no contribution" listener doesn't shift downstream
     * arithmetic. The contract advertises `int|float|null`; truncating
     * floats to int here would silently corrupt weighted-sum listeners
     * (e.g. `base_power * 0.5`).
     */
    public static function contributionOrZero(int|float|null $value): int|float
    {
        return $value ?? 0;
    }
}
