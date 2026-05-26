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
 *  - *derived-from-companions*: Avg, Variance, Stddev, WeightedAvg,
 *    BoolOr, BoolAnd, GeometricMean, HarmonicMean — each declares a
 *    {@see CompanionSpec} set that the registry auto-promotes into
 *    delta-maintainable internal columns; the user-facing column is
 *    written by a SQL formula over the companions on every mutation.
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
    case WeightedAvg = 'weighted_avg';
    case BoolOr = 'bool_or';
    case BoolAnd = 'bool_and';
    case GeometricMean = 'geometric_mean';
    case HarmonicMean = 'harmonic_mean';
    case DistinctCount = 'distinct_count';
    case StringAgg = 'string_agg';
    case JsonAgg = 'json_agg';
    case JsonObjectAgg = 'json_object_agg';
    // M5: read-only quantile kinds — withFreshAggregates() only.
    case Median = 'median';
    case Percentile = 'percentile';

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
            self::WeightedAvg, self::BoolOr, self::BoolAnd,
            self::GeometricMean, self::HarmonicMean,
            self::DistinctCount, self::StringAgg,
            self::JsonAgg, self::JsonObjectAgg,
            self::Median, self::Percentile => false,
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
            self::WeightedAvg, self::BoolOr, self::BoolAnd,
            self::GeometricMean, self::HarmonicMean,
            self::StringAgg, self::JsonAgg, self::JsonObjectAgg,
            self::Median, self::Percentile => true,
        };
    }

    /**
     * Declares the delta-maintainable companion columns this function
     * needs in order to be maintainable. `Avg` is promoted to a
     * `Sum + Count` companion pair; `Variance` and `Stddev` add a
     * `Sum(source * source)` "sum of squares" companion on top, so the
     * textbook `E[X²] − E[X]²` form is available without re-reading the
     * subtree on every mutation. `WeightedAvg` adds a
     * `Sum(weight * value)` and a `Sum(weight)` pair; `BoolOr` and
     * `BoolAnd` are derived from a `Sum(source AS INT)` + `Count`
     * companion pair so a single set of stored integers serves
     * "any descendant true?" and "all descendants true?" without
     * recomputing the subtree.
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
            self::WeightedAvg => [
                new CompanionSpec('__sum_wx', self::Sum, CompanionSourceTransform::TimesWeight),
                new CompanionSpec('__sum_w', self::Sum, sourceOrigin: CompanionSourceOrigin::ParentWeight),
            ],
            self::BoolOr, self::BoolAnd => [
                new CompanionSpec('__sum', self::Sum, CompanionSourceTransform::AsInt),
                new CompanionSpec('__count', self::Count),
            ],
            self::GeometricMean => [
                new CompanionSpec('__sum_log', self::Sum, CompanionSourceTransform::Ln),
                // Count companion also carries the Ln transform so it
                // counts only positive rows — matches the sum's domain so
                // the EXP(sum_log / count) formula uses the right N when
                // allowNonPositive() lets non-positive rows reach storage.
                new CompanionSpec('__count', self::Count, CompanionSourceTransform::Ln),
            ],
            self::HarmonicMean => [
                new CompanionSpec('__sum_recip', self::Sum, CompanionSourceTransform::Recip),
                // Count companion also carries the Recip transform so it
                // counts only non-zero rows — matches the sum's domain so
                // the count / sum_recip formula uses the right N.
                new CompanionSpec('__count', self::Count, CompanionSourceTransform::Recip),
            ],
            self::Sum, self::Count, self::Min, self::Max,
            self::DistinctCount, self::StringAgg,
            self::JsonAgg, self::JsonObjectAgg,
            self::Median, self::Percentile => [],
        };
    }
}
