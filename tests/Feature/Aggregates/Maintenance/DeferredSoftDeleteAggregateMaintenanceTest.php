<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Maintenance;

use Vusys\NestedSet\Tests\Fixtures\Models\SoftBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Pins the soft-delete + force-delete combo when paired with
 * {@see SoftBranch::withDeferredAggregateMaintenance()}.
 *
 * Two paths interact here:
 *  - The `$alreadyTrashed` guard in NodeTrait::bootNodeTrait skips the
 *    per-row aggregate-on-delete hook when forceDelete fires a second
 *    `deleted` event on an already-trashed row. Without the guard the
 *    aggregate decrement would run twice.
 *  - Inside `withDeferredAggregateMaintenance` the per-row hooks
 *    short-circuit on a non-zero deferred depth and the closing
 *    `fixAggregates` recomputes the subtree from the live set.
 *
 * Failure mode if either path is wrong: aggregates underflow (double
 * decrement), overflow (skipped restore), or drift (closing fix
 * disagrees with the per-row trail).
 */
final class DeferredSoftDeleteAggregateMaintenanceTest extends TestCase
{
    public function test_soft_then_force_delete_under_deferred_does_not_double_decrement(): void
    {
        [$root, $left, $right] = $this->seedThreeNodeTree();

        $expectedAfterRemoval = (int) $right->tickets;

        SoftBranch::withDeferredAggregateMaintenance(function () use ($left): void {
            $left->delete();
            $trashed = SoftBranch::query()->withTrashed()->findOrFail($left->id);
            $trashed->forceDelete();
        }, $root);

        $root->refresh();

        $this->assertSame(
            $expectedAfterRemoval,
            (int) $root->tickets_total,
            'closing fixAggregates must reflect only the live right child (no double decrement, no stale soft-delete contribution)',
        );
        $this->assertFalse(SoftBranch::aggregatesAreBroken($root), 'no drift after deferred soft+force delete');
    }

    public function test_force_delete_only_under_deferred_matches_live_state(): void
    {
        [$root, $left, $right] = $this->seedThreeNodeTree();

        $expectedAfterRemoval = (int) $right->tickets;

        SoftBranch::withDeferredAggregateMaintenance(function () use ($left): void {
            $left->forceDelete();
        }, $root);

        $root->refresh();

        $this->assertSame(
            $expectedAfterRemoval,
            (int) $root->tickets_total,
            'pure force-delete inside deferred mode produces a clean recompute',
        );
        $this->assertFalse(SoftBranch::aggregatesAreBroken($root));
    }

    public function test_soft_then_force_delete_on_two_leaves_under_deferred(): void
    {
        [$root, $left, $right] = $this->seedThreeNodeTree();

        SoftBranch::withDeferredAggregateMaintenance(function () use ($left, $right): void {
            $left->delete();
            $right->delete();

            SoftBranch::query()->withTrashed()->findOrFail($left->id)->forceDelete();
            SoftBranch::query()->withTrashed()->findOrFail($right->id)->forceDelete();
        }, $root);

        $root->refresh();

        $this->assertSame(0, (int) $root->tickets_total, 'all live descendants gone — total collapses to zero');
        $this->assertFalse(SoftBranch::aggregatesAreBroken($root));
    }

    /**
     * @return array{0: SoftBranch, 1: SoftBranch, 2: SoftBranch}
     */
    private function seedThreeNodeTree(): array
    {
        $root = new SoftBranch(['name' => 'root', 'tickets' => 0, 'active' => 1]);
        $root->saveAsRoot();
        $root->refresh();

        $left = new SoftBranch(['name' => 'left', 'tickets' => 5, 'active' => 1]);
        $left->appendToNode($root)->save();
        $left->refresh();

        $right = new SoftBranch(['name' => 'right', 'tickets' => 7, 'active' => 1]);
        $right->appendToNode($root)->save();
        $right->refresh();

        $root->refresh();

        return [$root, $left, $right];
    }
}
