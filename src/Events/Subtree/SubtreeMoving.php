<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\Subtree;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasTreeMutation;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\Mutation\NodeMoved;
use Vusys\NestedSet\NodeBounds;

/**
 * Fires inside {@see HasTreeMutation::callPendingAction()} before
 * the structural SQL runs for an existing-node mutation. The
 * pre-move bounds are still accurate; handlers can capture state
 * before the renumber.
 *
 * Distinct from {@see NodeMoved} in two ways:
 *  - Fires *before* the SQL, so listeners can snapshot the old
 *    ancestor chain via bounds-based queries.
 *  - Carries the anchor model (not just the id), so decorators
 *    can attach data to the node before it moves.
 *
 * Skipped for new-node placements (where there's no "before"
 * state to snapshot — Eloquent's `creating` covers that case).
 *
 * Not queue-safe — carries a live anchor model.
 */
final readonly class SubtreeMoving
{
    public function __construct(
        public string $modelClass,
        public Model&HasNestedSet $anchor,
        public NodeBounds $fromBounds,
        /** One of 'appendTo', 'prependTo', 'sibling', 'root' — matches PendingOperation::$action. */
        public string $operation,
    ) {}
}
