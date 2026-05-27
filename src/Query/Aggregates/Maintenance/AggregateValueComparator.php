<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query\Aggregates\Maintenance;

use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;

/**
 * Drift-detection comparator for stored vs computed aggregate values.
 *
 * Owns the per-kind comparison logic for the maintenance path:
 *  - numeric kinds use a two-tier tolerance (absolute floor +
 *    relative tolerance) to absorb storage-precision noise without
 *    flagging it as drift.
 *  - JSON kinds decode then normalise key order before comparing.
 *  - StringAgg with `distinct: true` splits, sorts, and set-compares
 *    to absorb backend differences in segment ordering.
 *  - Bool kinds normalise driver-specific encodings ('t'/'f', 0/1,
 *    native bool) to a single shape before comparing.
 */
final class AggregateValueComparator
{
    /**
     * Tolerant numeric equality. Both sides may arrive as int, float,
     * decimal-string (PostgreSQL), or null. Two-tier comparison:
     *
     *  1. **Absolute floor** — `|a - b| < 1e-4`. Covers AVG values
     *     stored as `DECIMAL(_, 4)` (or similar) compared against a
     *     freshly-computed PHP float. A 4-decimal-place store can
     *     differ from the unrounded float by up to 5e-5; the 1e-4
     *     threshold absorbs that without flagging it as drift.
     *
     *  2. **Relative tolerance** — `|a - b| / max(|a|, |b|) < 1e-9`.
     *     For large magnitudes (SUM in the millions or billions),
     *     float arithmetic noise scales with the value. The relative
     *     check tolerates that without loosening the absolute floor
     *     for typical (small-scale) AVG cases.
     *
     * The two are OR'd: drift is detected only when BOTH the
     * absolute floor and the relative tolerance reject the pair.
     */
    public static function aggregatesEqual(mixed $a, mixed $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        if (! is_numeric($a) || ! is_numeric($b)) {
            return $a === $b;
        }

        $af = (float) $a;
        $bf = (float) $b;
        $diff = abs($af - $bf);

        if ($diff < 1e-4) {
            return true;
        }

        $scale = max(abs($af), abs($bf));
        if ($scale === 0.0) {
            return false;
        }

        return $diff / $scale < 1e-9;
    }

    /**
     * Definition-aware drift check. The four collection-aggregate kinds need
     * specialised comparators:
     *
     *  - JSON kinds: do not "optimise" to a string compare — jsonb
     *    reorders object keys on read, so two semantically-equal values
     *    may differ as bytes. Decode both sides and compare structurally.
     *  - StringAgg with `distinct: true`: backends differ on segment
     *    ordering when DISTINCT is set (SQLite preserves insertion
     *    order; PG/MySQL order by source). Split on the separator,
     *    sort, and compare as a set.
     *
     * Everything else (numeric kinds, plain stringAgg) delegates to
     * {@see aggregatesEqual()} which keeps the numeric-tolerance shape.
     */
    public static function aggregateValuesEqual(AggregateDefinition $def, mixed $stored, mixed $computed): bool
    {
        return match ($def->function) {
            AggregateFunction::JsonAgg,
            AggregateFunction::JsonObjectAgg => self::jsonValuesEqual($stored, $computed),
            AggregateFunction::StringAgg => $def->distinct
                ? self::distinctStringAggEqual($def, $stored, $computed)
                : self::aggregatesEqual($stored, $computed),
            AggregateFunction::BoolOr,
            AggregateFunction::BoolAnd => self::boolValuesEqual($stored, $computed),
            default => self::aggregatesEqual($stored, $computed),
        };
    }

    /**
     * Boolean drift-detection comparator. Normalises raw driver
     * outputs ('t'/'f' from PG, 0/1 from MySQL/SQLite, native bool
     * after an Eloquent cast) so a stored TRUE compares equal to
     * the chain-fold's computed `true` regardless of which driver
     * fetched the row.
     */
    private static function boolValuesEqual(mixed $stored, mixed $computed): bool
    {
        return self::normaliseBool($stored) === self::normaliseBool($computed);
    }

    private static function normaliseBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value !== 0 && $value !== 0.0;
        }
        if (is_string($value)) {
            $trimmed = strtolower(trim($value));

            return ! in_array($trimmed, ['', '0', 'f', 'false'], true);
        }

        return null;
    }

    private static function jsonValuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }

        $decodedA = self::decodeJsonValue($a);
        $decodedB = self::decodeJsonValue($b);

        // Both decode to identical PHP structures regardless of which
        // backend wrote them — but assoc arrays are order-sensitive
        // under `===` in PHP, so normalise key order recursively before
        // comparing.
        return self::normaliseJsonStructure($decodedA) === self::normaliseJsonStructure($decodedB);
    }

    private static function normaliseJsonStructure(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normaliseJsonStructure(...), $value);
        }

        ksort($value);
        $result = [];
        foreach ($value as $k => $v) {
            $result[$k] = self::normaliseJsonStructure($v);
        }

        return $result;
    }

    private static function decodeJsonValue(mixed $value): mixed
    {
        if (is_string($value)) {
            $decoded = json_decode($value, associative: true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    private static function distinctStringAggEqual(AggregateDefinition $def, mixed $stored, mixed $computed): bool
    {
        if ($stored === null && $computed === null) {
            return true;
        }
        if ($stored === null || $computed === null) {
            return false;
        }
        if (! is_string($stored) || ! is_string($computed)) {
            return $stored === $computed;
        }

        // Split on the configured separator, with optional whitespace
        // tolerance for SQLite (where DISTINCT loses the separator).
        $separator = $def->separator;
        $pattern = '/'.preg_quote(rtrim($separator), '/').'\s*/';

        $segmentsA = preg_split($pattern, $stored) ?: [];
        $segmentsB = preg_split($pattern, $computed) ?: [];

        sort($segmentsA);
        sort($segmentsB);

        return $segmentsA === $segmentsB;
    }
}
