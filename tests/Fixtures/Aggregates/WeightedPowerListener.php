<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\TreeAggregateListener;

/**
 * Computes base_power × level as a node's contribution to a SUM aggregate.
 *
 * Used to populate the `weighted_power` column on the Monster fixture model.
 */
final class WeightedPowerListener implements TreeAggregateListener
{
    public function contribution(Model $node): ?int
    {
        $basePower = $node->getAttribute('base_power');
        $level = $node->getAttribute('level');

        return is_numeric($basePower) && is_numeric($level)
            ? (int) $basePower * (int) $level
            : null;
    }

    /**
     * @return list<string>
     */
    public function watchColumns(): array
    {
        return ['base_power', 'level'];
    }
}
