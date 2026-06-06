<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * AVG declared alongside Sum + Count, all with the *same* Equality
 * filter. The registry should adopt the user's Sum / Count as the
 * AVG's companions rather than auto-promoting internals — the filters
 * agree, so the values are semantically compatible.
 *
 * Pins the `FilterPredicateKind::Equality` arm of
 * `AggregateDefinitionValidator::filtersMatch()`.
 */
final class AvgWithMatchingEqualityFilterArea extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @return list<AggregateDefinition> */
    protected function nestedSetAggregates(): array
    {
        return [
            Aggregate::sum('tickets')->filter(['type' => 'fire'])->into('fire_total'),
            Aggregate::count('tickets')->filter(['type' => 'fire'])->into('fire_count'),
            Aggregate::avg('tickets')->filter(['type' => 'fire'])->into('fire_avg'),
        ];
    }
}
