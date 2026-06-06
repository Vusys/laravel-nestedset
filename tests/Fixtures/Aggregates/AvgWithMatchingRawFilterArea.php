<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * AVG + Sum + Count over the same source, all sharing the same
 * `filterRaw` predicate. Pins the `FilterPredicateKind::Raw` arm of
 * `AggregateRegistry::filtersMatch()`.
 */
final class AvgWithMatchingRawFilterArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    /** @return list<AggregateDefinition> */
    protected function nestedSetAggregates(): array
    {
        return [
            Aggregate::sum('tickets')->filterRaw('type IS NOT NULL', watches: ['type'])->into('typed_total'),
            Aggregate::count('tickets')->filterRaw('type IS NOT NULL', watches: ['type'])->into('typed_count'),
            Aggregate::avg('tickets')->filterRaw('type IS NOT NULL', watches: ['type'])->into('typed_avg'),
        ];
    }
}
