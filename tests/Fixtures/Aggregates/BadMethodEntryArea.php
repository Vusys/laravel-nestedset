<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Method-override returns an array containing a non-AggregateDefinition
 * entry. Used to exercise the registry's per-entry validation throw.
 */
final class BadMethodEntryArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'areas';

    /**
     * @return list<string> intentional wrong type for the test
     */
    protected function nestedSetAggregates(): array
    {
        return ['not-a-definition'];
    }
}
