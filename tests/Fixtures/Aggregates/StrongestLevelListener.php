<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\TreeAggregateListener;

/**
 * Returns the node's `level` as its contribution. Paired with a
 * NestedSetAggregateListener of operation Max, this populates a
 * "strongest level in subtree" column — companion to
 * WeakestLevelListener, but on the Max side. Without an explicit
 * Max-operation listener fixture the chain-inclusion arm for Max
 * in HasNestedSetAggregates can be mutated without observable
 * failure.
 */
final class StrongestLevelListener implements TreeAggregateListener
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
