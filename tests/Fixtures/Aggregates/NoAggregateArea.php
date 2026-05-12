<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * No aggregate declarations of any kind. Registry must return an empty
 * list.
 */
final class NoAggregateArea extends Model implements HasNestedSet
{
    use NodeTrait;
}
