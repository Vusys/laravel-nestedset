<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\Subtree;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\Mutation\NodeMoved;
use Vusys\NestedSet\NodeBounds;

/**
 * Fires alongside (and immediately after) {@see NodeMoved} on every
 * existing-node structural mutation.
 *
 * Difference from `NodeMoved`: when an interior node moves, its
 * **whole subtree** moves with it — the descendants in the DB are
 * renumbered but no events fire for them. `NodeMoved` implicitly
 * implies "one node moved" because its payload is anchor-only.
 * `SubtreeMoved` carries the descendant ids so listeners that
 * care about the whole subtree (breadcrumb/permission/cache
 * invalidation under the new path) get the full set in one signal.
 *
 * `$descendantIds` may be empty for leaf moves; it's gathered via
 * one bounds SELECT against the post-move ancestor chain.
 *
 * Not queue-safe — carries a live anchor model.
 */
final readonly class SubtreeMoved
{
    /**
     * @param  list<int|string>  $descendantIds  ids of strict descendants (excludes the anchor itself)
     */
    public function __construct(
        public string $modelClass,
        public Model&HasNestedSet $anchor,
        public NodeBounds $fromBounds,
        public NodeBounds $toBounds,
        public string $operation,
        public array $descendantIds,
        public float $durationMs,
    ) {}
}
