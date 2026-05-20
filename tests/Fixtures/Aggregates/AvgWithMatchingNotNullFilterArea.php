<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * AVG + Sum + Count over the same source, all sharing the same
 * `filterNotNull` predicate. Pins the
 * `FilterPredicateKind::NotNull` arm of
 * `AggregateRegistry::filtersMatch()`.
 */
final class AvgWithMatchingNotNullFilterArea extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @return list<AggregateDefinition> */
    protected function nestedSetAggregates(): array
    {
        return [
            Aggregate::sum('tickets')->filterNotNull('type')->into('type_total'),
            Aggregate::count('tickets')->filterNotNull('type')->into('type_count'),
            Aggregate::avg('tickets')->filterNotNull('type')->into('type_avg'),
        ];
    }
}
