<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\Mutation;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasTreeMutation;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Fires from {@see HasTreeMutation::reorderChildren()} after the
 * single CASE-WHEN UPDATE that reshuffles a sibling group completes.
 *
 * `$idsInOrder` is the post-reorder direct-child order under
 * `$parent`. `$rowsAffected` is the number of rows the UPDATE
 * touched — the size of the parent's subtree excluding the parent
 * itself. For an identity reorder (the supplied order matches the
 * current order) no UPDATE fires and the event is **not** emitted.
 *
 * Not queue-safe — carries a live parent model instance.
 */
final readonly class SiblingsReordered
{
    /**
     * @param  list<int|string>  $idsInOrder
     */
    public function __construct(
        public string $modelClass,
        public Model&HasNestedSet $parent,
        public array $idsInOrder,
        public int $rowsAffected,
        public float $durationMs,
    ) {}
}
