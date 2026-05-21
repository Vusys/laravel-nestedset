<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

/**
 * Which column on the parent aggregate's declaration a companion
 * draws its source from. AVG / Variance / Stddev companions all
 * use the parent's primary source ({@see self::ParentSource}); the
 * `Sum(weight)` companion of a {@see AggregateFunction::WeightedAvg}
 * draws from the parent's weight column instead
 * ({@see self::ParentWeight}).
 *
 * Stored as an enum rather than a sentinel string so the registry's
 * companion-promotion path can resolve the actual column name at
 * promotion time without baking a magic value into spec construction.
 */
enum CompanionSourceOrigin
{
    case ParentSource;
    case ParentWeight;
}
