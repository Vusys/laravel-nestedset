<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

/**
 * Aggregate functions the package can maintain as precalculated columns.
 *
 * The first five — SUM/COUNT/AVG/MIN/MAX — are the SQL-standard numeric
 * roll-ups. The remaining four (DistinctCount / StringAgg / JsonAgg /
 * JsonObjectAgg) are collection-aggregate kinds (recompute-only) added by the
 * "more aggregate kinds" design: they all go through recompute on every
 * mutation, never the delta fast-path.
 *
 * Backed by string so values appear human-readable in error messages,
 * logs, and debug dumps.
 */
enum AggregateFunction: string
{
    case Sum = 'sum';
    case Count = 'count';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';
    case DistinctCount = 'distinct_count';
    case StringAgg = 'string_agg';
    case JsonAgg = 'json_agg';
    case JsonObjectAgg = 'json_object_agg';

    /**
     * True for functions whose maintenance can be expressed as a single
     * delta `UPDATE col = col + Δ` on each ancestor row. The remaining
     * functions need a subtree recompute on at least some mutation paths
     * (see {@see Aggregate} class docblock).
     */
    public function supportsDelta(): bool
    {
        return match ($this) {
            self::Sum, self::Count => true,
            self::Avg, self::Min, self::Max,
            self::DistinctCount, self::StringAgg,
            self::JsonAgg, self::JsonObjectAgg => false,
        };
    }

    /**
     * True for functions whose canonical "empty subtree" answer is NULL
     * rather than zero. Aggregate columns of these functions are stored
     * nullable; SUM, COUNT and DistinctCount default to 0 and stay
     * non-null.
     */
    public function nullableOnEmpty(): bool
    {
        return match ($this) {
            self::Sum, self::Count, self::DistinctCount => false,
            self::Avg, self::Min, self::Max,
            self::StringAgg, self::JsonAgg, self::JsonObjectAgg => true,
        };
    }
}
