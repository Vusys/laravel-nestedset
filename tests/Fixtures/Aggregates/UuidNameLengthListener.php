<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\TreeAggregateListener;

/**
 * Returns the byte length of a UuidTag's `name`. Summed up the
 * subtree, this lets a UUID-keyed fixture exercise the PHP listener
 * aggregate paths (chunked repair, full repair, on-demand fresh
 * read) alongside its SQL aggregate.
 */
final class UuidNameLengthListener implements TreeAggregateListener
{
    public function contribution(Model $node): int
    {
        $name = $node->getAttribute('name');

        return is_string($name) ? strlen($name) : 0;
    }

    /**
     * @return list<string>
     */
    public function watchColumns(): array
    {
        return ['name'];
    }
}
