<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasSoftDeleteTree;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;

/**
 * Fires from {@see HasSoftDeleteTree::applySoftDeleteCascade()}
 * immediately AFTER the descendant UPDATE that propagates the
 * `deleted_at` marker through the anchor's subtree.
 *
 * Mirrors the pre-cascade {@see SubtreeSoftDeleting} so listeners
 * that need before-vs-after symmetry (cache eviction, search-index
 * removal, audit logs) have a single signal per cascade. Per-row
 * Eloquent `deleted` events never fire for the cascaded
 * descendants — the package issues a single UPDATE without
 * instantiating models.
 *
 * `$descendantIds` lists every descendant id that the UPDATE
 * actually wrote (rows that were already soft-deleted are skipped).
 * It can be empty when the anchor was a leaf or every descendant
 * was already trashed. The cost of gathering ids is one extra SELECT
 * before the UPDATE (cheap on the same bounds index the UPDATE uses).
 *
 * Carries a live anchor model — not queue-safe by default. The
 * `$descendantIds` field is queue-safe; if you need to enqueue work,
 * capture ids synchronously and forward those.
 */
final readonly class SubtreeSoftDeleted
{
    /**
     * @param  array<string, mixed>  $scope  scope-column values bounding the cascade
     * @param  list<int|string>  $descendantIds  ids of descendants whose deleted_at was set
     */
    public function __construct(
        public string $modelClass,
        public Model&HasNestedSet $anchor,
        public NodeBounds $bounds,
        public array $scope,
        public string $deletedAt,
        public array $descendantIds,
    ) {}
}
