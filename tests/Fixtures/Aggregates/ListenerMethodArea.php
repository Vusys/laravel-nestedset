<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\ListenerAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Listener aggregate declarations via method override only, no attributes.
 */
final class ListenerMethodArea extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @return list<ListenerAggregateDefinition> */
    protected function nestedSetListenerAggregates(): array
    {
        return [
            ListenerAggregate::sum(WeightedPowerListener::class)->into('weighted_power'),
        ];
    }
}
