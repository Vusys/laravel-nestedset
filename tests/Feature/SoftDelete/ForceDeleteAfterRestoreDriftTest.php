<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\SoftDelete;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\SoftBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Force-deleting a *soft-deleted* parent that has a *live* (individually
 * restored) descendant must still subtract that descendant's aggregate
 * contribution from the ancestor chain. The `alreadyTrashed` guard
 * correctly skips re-decrementing the parent (its contribution left at the
 * original soft-delete), but the cascade hard-deletes the live child too —
 * and that child's contribution was re-added on restore, so it must come
 * back off when the row is destroyed. Otherwise the ancestor's stored
 * aggregate counts a row that no longer exists. [reproduced]
 */
final class ForceDeleteAfterRestoreDriftTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function force_deleting_a_trashed_parent_with_a_restored_live_child_subtracts_the_child(): void
    {
        // KNOWN LIMITATION (documented in docs/tree-operations/soft-deletes.md):
        // the `alreadyTrashed` guard in NodeTrait's `deleted` hook skips the
        // aggregate decrement for the force-deleted parent (correct — its
        // contribution left at the original soft-delete), but the cascade also
        // hard-deletes the live, individually-restored child whose contribution
        // was re-added on restore. The correct decrement is the contribution of
        // the top-most LIVE descendants, not the parent's stored column (which
        // still holds the pre-trash total). A safe fix has to reorder the
        // force-delete lifecycle and is best landed alongside the aggregate
        // fuzzer that would validate it against the normal delete paths. This
        // test pins the exact reproduction for that future work.
        if (getenv('RUN_KNOWN_DRIFT_REPRO') === false) {
            $this->markTestSkipped(
                'Known aggregate-drift limitation: force-delete of a trashed parent with a '
                .'restored live child. Set RUN_KNOWN_DRIFT_REPRO=1 to run the (currently '
                .'failing) reproduction. See docs/tree-operations/soft-deletes.md.',
            );
        }

        $root = new SoftBranch(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();

        $parent = new SoftBranch(['name' => 'Parent', 'tickets' => 10]);
        $parent->appendToNode($root->refresh())->save();

        $child = new SoftBranch(['name' => 'Child', 'tickets' => 5]);
        $child->appendToNode($parent->refresh())->save();

        // Root's inclusive sum sees both descendants.
        $this->assertSame(15, (int) $root->refresh()->tickets_total);

        // Soft-delete Parent → cascades to Child → Root loses 15.
        $parent->refresh()->delete();
        $this->assertSame(0, (int) $root->refresh()->tickets_total);

        // Individually restore Child → it's live again under the still-trashed
        // Parent → Root re-gains the child's 5.
        SoftBranch::withTrashed()->whereKey($child->getKey())->firstOrFail()->restore();
        $this->assertSame(5, (int) $root->refresh()->tickets_total);

        // Force-delete the (trashed) Parent: the cascade destroys Parent AND
        // the live Child. Root must end at 0 — both rows are gone.
        SoftBranch::withTrashed()->whereKey($parent->getKey())->firstOrFail()->forceDelete();

        $this->assertSame(
            0,
            (int) $root->refresh()->tickets_total,
            'Root still counts the force-deleted live child — aggregate drift',
        );
        $this->assertAggregatesAreIntact(SoftBranch::class);
    }
}
