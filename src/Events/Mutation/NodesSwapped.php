<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\Mutation;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasTreeMutation;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;

/**
 * Fires from {@see HasTreeMutation::up()} / {@see HasTreeMutation::down()}
 * after the sibling-swap completes. Frames the swap as a single
 * logical operation — two `NodeMoved` events still fire (one per
 * participant) but they're hard to correlate without this signal.
 *
 * Both participants are refreshed and reflect their post-swap
 * positions.
 *
 * Not queue-safe — carries two live model instances.
 */
final readonly class NodesSwapped
{
    public function __construct(
        public string $modelClass,
        public Model&HasNestedSet $movedNode,
        public NodeBounds $movedNodeFrom,
        public NodeBounds $movedNodeTo,
        public Model&HasNestedSet $displacedSibling,
        public NodeBounds $displacedFrom,
        public NodeBounds $displacedTo,
        /** 'up' or 'down'. */
        public string $direction,
        public float $durationMs,
    ) {}
}
