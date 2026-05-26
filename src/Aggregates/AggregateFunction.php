<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

/**
 * Aggregate functions the package supports for precalculated columns.
 *
 * Backed by string so values appear human-readable in error messages,
 * logs, and debug dumps.
 *
 * Families:
 *  - *delta-maintainable*: Sum, Count.
 *  - *derived-from-companions*: Avg, Variance, Stddev — each declares a
 *    {@see CompanionSpec} set that the registry auto-promotes into
 *    delta-maintainable internal columns; the user-facing column is
 *    written by a SQL formula over the companions.
 *  - *recompute-only*: Min, Max, DistinctCount, StringAgg, JsonAgg,
 *    JsonObjectAgg — no companions; each mutation re-reads the subtree.
 */
enum AggregateFunction: string
{
    case Sum = 'sum';
    case Count = 'count';
    case Avg = 'avg';
    case Min = 'min';
    case Max = 'max';
    case Variance = 'variance';
    case Stddev = 'stddev';
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
            self::Avg, self::Min, self::Max, self::Variance, self::Stddev,
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
            self::Avg, self::Min, self::Max, self::Variance, self::Stddev,
            self::StringAgg, self::JsonAgg, self::JsonObjectAgg => true,
        };
    }

    /**
     * Declares the delta-maintainable companion columns this function
     * needs in order to be maintainable. `Avg` is promoted to a
     * `Sum + Count` companion pair; `Variance` and `Stddev` add a
     * `Sum(source * source)` "sum of squares" companion on top, so the
     * textbook `E[X²] − E[X]²` form is available without re-reading the
     * subtree on every mutation.
     *
     * Functions that are themselves delta-maintainable (`Sum`,
     * `Count`) or that route through full subtree recompute (`Min`,
     * `Max`, DistinctCount, StringAgg, JsonAgg, JsonObjectAgg) declare
     * no companions.
     *
     * @return list<CompanionSpec>
     */
    public function companionSet(): array
    {
        return match ($this) {
            self::Avg => [
                new CompanionSpec('__sum', self::Sum),
                new CompanionSpec('__count', self::Count),
            ],
            self::Variance, self::Stddev => [
                new CompanionSpec('__sum', self::Sum),
                new CompanionSpec('__sum_sq', self::Sum, CompanionSourceTransform::Square),
                new CompanionSpec('__count', self::Count),
            ],
            self::Sum, self::Count, self::Min, self::Max,
            self::DistinctCount, self::StringAgg,
            self::JsonAgg, self::JsonObjectAgg => [],
        };
    }
}
