<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\TreeAggregateListener;

/**
 * Float-returning listener: (base_power * level) / 2.
 *
 * Exists to exercise the int|float path through delta maintenance —
 * a regression to the previous `(int) $contrib` cast would silently
 * truncate the .5 endings produced here when base_power*level is odd.
 */
final class HalfWeightedPowerListener implements TreeAggregateListener
{
    public function contribution(Model $node): ?float
    {
        $basePower = $node->getAttribute('base_power');
        $level = $node->getAttribute('level');

        if (! is_numeric($basePower) || ! is_numeric($level)) {
            return null;
        }

        return ((int) $basePower * (int) $level) / 2.0;
    }

    /**
     * @return list<string>
     */
    public function watchColumns(): array
    {
        return ['base_power', 'level'];
    }
}
