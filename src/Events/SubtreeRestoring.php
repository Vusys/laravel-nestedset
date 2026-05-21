<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasSoftDeleteTree;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;

/**
 * Fires from {@see HasSoftDeleteTree::applyRestoreCascade()}
 * immediately BEFORE the descendant UPDATE that clears the
 * `deleted_at` marker on every descendant that shares the anchor's
 * original soft-delete timestamp.
 *
 * Symmetric counterpart to {@see SubtreeSoftDeleting}; same
 * rationale (cascaded descendants never get their own Eloquent
 * `restoring` event).
 *
 * Not queue-safe — carries a live anchor model.
 */
final readonly class SubtreeRestoring
{
    /**
     * @param  array<string, mixed>  $scope  scope-column values bounding the cascade
     */
    public function __construct(
        public string $modelClass,
        public Model&HasNestedSet $anchor,
        public NodeBounds $bounds,
        public array $scope,
        /** The marker the restore cascade will match against. */
        public string $marker,
    ) {}
}
