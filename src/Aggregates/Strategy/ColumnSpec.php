<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Strategy;

use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceTransform;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Lifecycle\LifecycleSupport;

/**
 * Recompute-time description of one aggregate column.
 *
 * Threaded into {@see RecomputeMaintenance::apply()} (one entry per
 * column to recompute) and consumed by its private helpers. Replaces
 * the inline `array{column, function, source, inclusive, filter?,
 * sample?, sourceTransform?, definition?}` shape that the same three
 * producer sites in {@see LifecycleSupport}
 * used to construct ad-hoc.
 *
 * `sourceTransform` is left nullable so callers that already have an
 * {@see AggregateDefinition} can omit it — the reader falls back to
 * `$definition->sourceTransform` when the explicit value is null, then
 * to {@see CompanionSourceTransform::Identity} as a final default. Same
 * fallback chain the array shape used.
 */
final readonly class ColumnSpec
{
    public function __construct(
        public string $column,
        public AggregateFunction $function,
        public string $source,
        public bool $inclusive,
        public ?FilterPredicate $filter = null,
        public bool $sample = false,
        public ?CompanionSourceTransform $sourceTransform = null,
        public ?AggregateDefinition $definition = null,
    ) {}
}
