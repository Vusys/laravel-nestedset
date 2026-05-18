<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Listener method-override returns a non-array. Used to exercise the
 * registry's listener validation throw.
 */
final class BadListenerMethodReturnArea extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $table = 'areas';

    protected function nestedSetListenerAggregates(): mixed
    {
        return 42;
    }
}
