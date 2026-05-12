<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Aggregate declarations via the method-override escape hatch only.
 * Mirrors how `getScopeAttributes()` is used elsewhere in the package.
 */
final class MethodOnlyArea extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @return list<AggregateDefinition> */
    protected function nestedSetAggregates(): array
    {
        return [
            Aggregate::sum('tickets')->into('tickets_total'),
            Aggregate::max('tickets')->into('tickets_max'),
        ];
    }
}
