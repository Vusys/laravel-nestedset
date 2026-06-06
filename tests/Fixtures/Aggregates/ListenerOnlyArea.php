<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Listener-only aggregate declarations via repeatable attribute, no SQL
 * aggregate attributes and no method overrides.
 */
#[NestedSetAggregateListener(column: 'weighted_power', listener: WeightedPowerListener::class, operation: AggregateFunction::Sum)]
#[NestedSetAggregateListener(column: 'fire_count', listener: FireCountListener::class, operation: AggregateFunction::Sum)]
final class ListenerOnlyArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;
}
