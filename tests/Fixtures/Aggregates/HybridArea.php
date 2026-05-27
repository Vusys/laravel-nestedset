<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Both an attribute and a method override are present. Registry order
 * must be: attribute declarations first, method-override declarations
 * appended. Mirrors {@see NestedSetScopeResolver}
 * precedence.
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
final class HybridArea extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @return list<AggregateDefinition> */
    protected function nestedSetAggregates(): array
    {
        return [
            Aggregate::max('tickets')->into('tickets_max'),
        ];
    }
}
