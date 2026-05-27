<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\SoftDelete;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasSoftDeleteTree;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;

/**
 * Fires from {@see HasSoftDeleteTree::applyRestoreCascade()}
 * immediately AFTER the descendant UPDATE that clears the
 * `deleted_at` marker on every descendant that shares the anchor's
 * original soft-delete timestamp.
 *
 * `$descendantIds` lists every descendant that was actually
 * restored (rows whose deleted_at no longer matches the marker —
 * i.e. trashed by a later cascade — are not touched and therefore
 * not in this list).
 *
 * Carries a live anchor model — not queue-safe.
 */
final readonly class SubtreeRestored
{
    /**
     * @param  array<string, mixed>  $scope
     * @param  list<int|string>  $descendantIds  ids of descendants whose deleted_at was cleared
     */
    public function __construct(
        public string $modelClass,
        public Model&HasNestedSet $anchor,
        public NodeBounds $bounds,
        public array $scope,
        public string $marker,
        public array $descendantIds,
    ) {}
}
