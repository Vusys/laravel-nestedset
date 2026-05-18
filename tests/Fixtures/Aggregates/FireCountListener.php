<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\TreeAggregateListener;

/**
 * Returns 1 when a node's type is 'fire', 0 otherwise.
 *
 * Used with a Sum operation to simulate a count of fire-type nodes.
 * Populates the `fire_count` column on the Pokemon fixture model.
 */
final class FireCountListener implements TreeAggregateListener
{
    public function contribution(Model $node): int
    {
        return $node->getAttribute('type') === 'fire' ? 1 : 0;
    }

    /**
     * @return list<string>
     */
    public function watchColumns(): array
    {
        return ['type'];
    }
}
