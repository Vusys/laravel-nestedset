<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasTreeMutation;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;

/**
 * Fires from {@see HasTreeMutation::applyForceDeleteCascade()}
 * immediately BEFORE the raw DELETE that hard-deletes every
 * descendant of an interior node being forceDelete()d.
 *
 * Last chance to capture state — once the DELETE runs the rows
 * are gone. Eloquent `deleting` fires only for the anchor; the
 * descendants are removed without their own model events.
 *
 * `$descendantIds` is gathered from a SELECT immediately before
 * the DELETE so listeners can write out a tombstone (audit log,
 * archive table, search-index removal).
 *
 * Not queue-safe — carries a live anchor model.
 */
final readonly class SubtreeForceDeleting
{
    /**
     * @param  array<string, mixed>  $scope
     * @param  list<int|string>  $descendantIds  ids of descendants the cascade is about to delete
     */
    public function __construct(
        public string $modelClass,
        public Model&HasNestedSet $anchor,
        public NodeBounds $bounds,
        public array $scope,
        public array $descendantIds,
    ) {}
}
