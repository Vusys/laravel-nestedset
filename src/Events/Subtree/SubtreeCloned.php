<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\Subtree;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Fires once at the end of a successful `cloneSubtreeTo()` or
 * `cloneSubtreeAsRoot()` call. The clone path inherits
 * `bulkInsertTree`'s contract — per-row Eloquent `created` events
 * do NOT fire — so listeners that need to react to "a whole
 * subtree was just cloned" hook this signal instead of the
 * per-row lifecycle.
 *
 * `includeTrashed` reports the option the caller passed: false for
 * the default "live snapshot" copy, true when trashed descendants
 * (and possibly a trashed root) were materialised as live rows on
 * the destination side. Useful for cache invalidation and side-
 * effect replication (a "live snapshot" clone has a smaller
 * downstream footprint than a "everything-included" clone).
 *
 * Not queue-safe — carries live `source` and `clone` models.
 */
final readonly class SubtreeCloned
{
    public function __construct(
        public string $modelClass,
        public Model&HasNestedSet $source,
        public Model&HasNestedSet $clone,
        public int $rowCount,
        public bool $includeTrashed,
    ) {}
}
