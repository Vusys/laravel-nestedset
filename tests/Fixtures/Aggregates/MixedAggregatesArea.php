<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Mixed SQL-aggregate and listener-aggregate declarations. Registry must
 * return both kinds in the resolved list.
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
#[NestedSetAggregateListener(column: 'weighted_power', listener: WeightedPowerListener::class, operation: AggregateFunction::Sum)]
final class MixedAggregatesArea extends Model implements HasNestedSet
{
    use NodeTrait;
}
