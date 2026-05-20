<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasSoftDeleteTree;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;

/**
 * Fires from {@see HasSoftDeleteTree::applySoftDeleteCascade()}
 * immediately BEFORE the descendant UPDATE that propagates the
 * `deleted_at` marker through the anchor's subtree.
 *
 * Eloquent's `deleting` event fires only for the anchor row — the
 * cascade UPDATE never instantiates descendant models, so per-row
 * `deleting` listeners on the model class never see them. This
 * event closes that gap: one signal per cascade, before the write,
 * so audit/permission-check listeners get their last look at the
 * pre-cascade state.
 *
 * Not queue-safe — carries a live anchor model.
 */
final readonly class SubtreeSoftDeleting
{
    /**
     * @param  array<string, mixed>  $scope  scope-column values bounding the cascade
     */
    public function __construct(
        public string $modelClass,
        public Model&HasNestedSet $anchor,
        public NodeBounds $bounds,
        public array $scope,
        /** Stringified timestamp the cascade is about to write. */
        public string $deletedAt,
    ) {}
}
