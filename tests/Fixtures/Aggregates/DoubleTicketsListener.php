<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\TreeAggregateListener;

/**
 * Per-node contribution: `tickets * 2`. Used by the lazy-aggregate
 * fixture to exercise the listener-side lazy path with a recognisably
 * different number than the SQL Sum aggregates over the same source.
 */
final class DoubleTicketsListener implements TreeAggregateListener
{
    public function contribution(Model $node): ?int
    {
        $tickets = $node->getAttribute('tickets');

        return is_numeric($tickets) ? (int) $tickets * 2 : null;
    }

    /**
     * @return list<string>
     */
    public function watchColumns(): array
    {
        return ['tickets'];
    }
}
