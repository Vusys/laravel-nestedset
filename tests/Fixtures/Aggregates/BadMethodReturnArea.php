<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Method-override returns a non-array. Used to exercise the
 * AggregateRegistry's validation error path.
 */
final class BadMethodReturnArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'areas';

    protected function nestedSetAggregates(): mixed
    {
        return 'not-an-array';
    }
}
