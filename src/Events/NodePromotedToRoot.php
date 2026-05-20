<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasTreeMutation;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Fires from {@see HasTreeMutation::makeRoot()} after an existing
 * node has been promoted to a top-level root.
 *
 * Already covered by {@see NodeMoved} with `operation='root'`; this
 * dedicated event reads more clearly for listeners that only care
 * about root promotions (top-level menu changes, permission roots,
 * etc.) and don't want to filter every `NodeMoved`.
 *
 * Not fired for {@see HasTreeMutation::saveAsRoot()} on a new node —
 * that's an Eloquent `created`, not a move.
 *
 * Not queue-safe — carries a live anchor model.
 */
final readonly class NodePromotedToRoot
{
    public function __construct(
        public string $modelClass,
        public Model&HasNestedSet $anchor,
        public int|string|null $previousParentId,
        public int $previousDepth,
    ) {}
}
