<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Definitions;

use Vusys\NestedSet\Aggregates\AggregateFunction;

/**
 * Declares one of the delta-maintainable companion columns an aggregate
 * function needs in order to remain delta-maintainable.
 *
 * The `Avg` aggregate has been promoted to a `Sum + Count` companion
 * pair since the package's inception; this value object generalises
 * that mechanism so other aggregate kinds (variance, geometricMean,
 * weightedAvg, …) can plug into the same registry promotion + Blueprint
 * macro infrastructure without bespoke per-kind code.
 *
 * A companion column is an internal storage column that the maintenance
 * machinery writes alongside the user-facing aggregate. The user-facing
 * column is then derived (in PHP or in SQL) from the stored companions.
 *
 * Companions:
 *  - share their parent aggregate's source column, scope, and filter
 *    predicate;
 *  - use a delta-maintainable underlying function (Sum or Count today);
 *  - are flagged `internal: true` on the resulting {@see AggregateDefinition}
 *    so they stay out of error reports and user-facing inspection.
 */
final readonly class CompanionSpec
{
    /**
     * @param  CompanionSourceOrigin  $sourceOrigin  Which column on the parent aggregate's
     *                                               declaration this companion draws its source
     *                                               from. Defaults to the parent's primary source
     *                                               column; weighted average's `Sum(weight)`
     *                                               companion overrides to {@see CompanionSourceOrigin::ParentWeight}.
     */
    public function __construct(
        public string $suffix,
        public AggregateFunction $function,
        public CompanionSourceTransform $sourceTransform = CompanionSourceTransform::Identity,
        public CompanionSourceOrigin $sourceOrigin = CompanionSourceOrigin::ParentSource,
    ) {}

    /**
     * Compose the companion column name from the user-facing display
     * column name. Existing `Avg` companions use the `{display}__sum`
     * and `{display}__count` shape; the suffix is per-spec so future
     * kinds can use `{display}__sum_sq`, `{display}__sum_log`, etc.
     */
    public function columnFor(string $displayColumn): string
    {
        return $displayColumn.$this->suffix;
    }
}
