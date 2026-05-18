<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\TreeAggregateListener;

/**
 * Returns the node's `level` as its contribution. Paired with a
 * NestedSetAggregateListener of operation Min, this populates a
 * "weakest level in subtree" column — exercising the listener Min/Max
 * recompute path on deletes and structural mutations.
 */
final class WeakestLevelListener implements TreeAggregateListener
{
    public function contribution(Model $node): ?int
    {
        $level = $node->getAttribute('level');

        return is_numeric($level) ? (int) $level : null;
    }

    /**
     * @return list<string>
     */
    public function watchColumns(): array
    {
        return ['level'];
    }
}
