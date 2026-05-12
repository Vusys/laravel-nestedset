<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Aggregate declarations via the repeatable attribute, no method
 * override. Registry should return exactly the declared definitions
 * plus AVG companions (in this fixture there are no AVG declarations,
 * so the resolved set equals the declared set).
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
#[NestedSetAggregate(column: 'tickets_count', count: true)]
final class AttributeOnlyArea extends Model implements HasNestedSet
{
    use NodeTrait;
}
