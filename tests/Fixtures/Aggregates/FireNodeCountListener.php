<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\TreeAggregateListener;

/**
 * Counts only fire-type nodes. Returns `null` (not `0`) for non-fire
 * nodes so the row is excluded from the Count entirely — toggling a
 * node's `type` between 'fire' and anything else drives the
 * counted ↔ uncounted transition the listener-Count delta path keys on.
 */
final class FireNodeCountListener implements TreeAggregateListener
{
    public function contribution(Model $node): ?int
    {
        return $node->getAttribute('type') === 'fire' ? 1 : null;
    }

    /**
     * @return list<string>
     */
    public function watchColumns(): array
    {
        return ['type'];
    }
}
