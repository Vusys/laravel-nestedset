<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Two declarations target the same stored column. Registry must reject
 * this configuration.
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
#[NestedSetAggregate(column: 'tickets_total', max: 'tickets')]
final class DuplicateColumnArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;
}
