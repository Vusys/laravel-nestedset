<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Captures the trap of declaring exclusive SUM and COUNT alongside an
 * inclusive AVG over the same source. Without an inclusivity check the
 * registry would silently adopt the exclusive Sum / Count as the
 * inclusive AVG's companions — the three would read different row sets
 * (descendants-only vs descendants-plus-self) and the AVG display
 * column would drift relative to its inputs.
 */
final class MismatchedInclusiveAvgArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    /** @return list<AggregateDefinition> */
    protected function nestedSetAggregates(): array
    {
        return [
            Aggregate::sum('tickets')->exclusive()->into('tickets_sum_exc'),
            Aggregate::count('tickets')->exclusive()->into('tickets_count_exc'),
            Aggregate::avg('tickets')->into('tickets_avg'),
        ];
    }
}
