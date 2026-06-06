<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Listener method-override returns an array containing a
 * non-ListenerAggregateDefinition entry.
 */
final class BadListenerMethodEntryArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'areas';

    /**
     * @return list<string> intentional wrong type for the test
     */
    protected function nestedSetListenerAggregates(): array
    {
        return ['not-a-listener-definition'];
    }
}
