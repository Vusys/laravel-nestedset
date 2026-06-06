<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Only AVG declared. Registry must auto-promote two internal companion
 * definitions (SUM and COUNT over the same source) so that later phases
 * can maintain the AVG without a separate user declaration.
 */
#[NestedSetAggregate(column: 'tickets_avg', avg: 'tickets')]
final class AvgOnlyArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;
}
