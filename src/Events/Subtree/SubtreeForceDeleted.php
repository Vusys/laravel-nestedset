<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\Subtree;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasTreeMutation;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;

/**
 * Fires from {@see HasTreeMutation::applyForceDeleteCascade()}
 * immediately AFTER the descendant DELETE has committed (and
 * before {@see HasTreeMutation::applyStructuralCleanupOnDelete()}
 * closes the lft/rgt gap).
 *
 * The anchor row itself was deleted just before the cascade ran —
 * `$anchor` is the in-memory model in its post-delete state
 * (`$anchor->exists === false`).
 *
 * Not queue-safe — carries a live (post-delete) anchor model. If
 * queue-safe handoff is needed, capture `$descendantIds` and
 * `$bounds` synchronously and forward those.
 */
final readonly class SubtreeForceDeleted
{
    /**
     * @param  array<string, mixed>  $scope
     * @param  list<int|string>  $descendantIds  ids that were deleted by the cascade
     */
    public function __construct(
        public string $modelClass,
        public Model&HasNestedSet $anchor,
        public NodeBounds $bounds,
        public array $scope,
        public array $descendantIds,
        public int $descendantsAffected,
    ) {}
}
