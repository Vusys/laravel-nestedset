<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\BulkInsert;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasBulkInsert;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Fires once per row inside the {@see HasBulkInsert::bulkInsertTree()}
 * save loop, immediately after each model's `->save()` has returned.
 *
 * Distinct from Eloquent's `created` event in that this event:
 *  - carries the bulk-insert context (`$planIndex`, total count),
 *    so listeners can distinguish "row 7 of an import" from a
 *    regular `Model::create()`;
 *  - resolves a sensible `$parent` reference (the parent within
 *    the import, falling back to `appendTo` for top-level rows).
 *
 * The model is fully hydrated (id set, bounds set, scope copied).
 *
 * Not queue-safe — carries a live model instance.
 */
final readonly class BulkInsertNodeSaved
{
    public function __construct(
        public string $modelClass,
        public Model&HasNestedSet $node,
        /** Zero-based index into the import's flat plan. */
        public int $planIndex,
        /** Total nodes the import will save. */
        public int $totalNodes,
        /**
         * Parent of $node within the import: the previously-saved
         * model when $node has a parent in the input, otherwise
         * the user-supplied $appendTo anchor (or null for new
         * roots).
         */
        public (Model&HasNestedSet)|null $parent,
    ) {}
}
