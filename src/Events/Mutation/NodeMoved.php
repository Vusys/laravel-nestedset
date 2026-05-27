<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\Mutation;

use Vusys\NestedSet\NodeBounds;

/**
 * Fires after a structural mutation (`appendToNode`,
 * `prependToNode`, `insertBeforeNode`, `insertAfterNode`,
 * `makeRoot`) on an *existing* node completes. New-node placements
 * fire Eloquent's `created` event instead — this event is
 * specifically for moves of already-persisted nodes.
 *
 * Adds tree-aware context that Eloquent's standard `saved` event
 * can't easily carry: the bounds before and after the move, the
 * depth delta (`$toBounds->depthDelta($fromBounds)`), and which
 * structural operation was applied.
 *
 * Useful for: structural audit logs, cache invalidation hooks
 * scoped to the moved subtree, alerts on moves between specific
 * ancestor chains.
 */
final readonly class NodeMoved
{
    public function __construct(
        public string $modelClass,
        public int|string $nodeId,
        public NodeBounds $fromBounds,
        public NodeBounds $toBounds,
        /**
         * One of 'appendTo', 'prependTo', 'sibling', 'root' (matches
         * PendingOperation::$action), or 'sibling-displaced' for the
         * second participant in an `up()`/`down()` swap — switch
         * consumers MUST handle 'sibling-displaced' or fall through
         * to a default branch.
         */
        public string $operation,
        public float $durationMs,
    ) {}
}
