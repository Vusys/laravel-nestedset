<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Vusys\NestedSet\Aggregates\Strategy\RecomputeMaintenance;
use Vusys\NestedSet\Tests\Fixtures\Models\SoftBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Snapshot-semantics coverage for SQL aggregates that go through
 * {@see RecomputeMaintenance} —
 * the chain-recompute path used by exclusive aggregates and raw-SQL-
 * filter aggregates.
 *
 * SoftBranch combines:
 *   - tickets_total          inclusive SUM (delta path; covered elsewhere)
 *   - descendants_total      exclusive SUM (RecomputeMaintenance)
 *   - descendants_count      exclusive COUNT (RecomputeMaintenance)
 *   - descendants_max        exclusive MAX (RecomputeMaintenance)
 *   - active_tickets_total   inclusive SUM with raw filter (RecomputeMaintenance)
 *   - SoftDeletes
 *
 * Each test puts SoftBranch through the trash/restore lifecycle and
 * confirms the stored aggregate values track the live set, not the
 * trashed-included view.
 */
final class SoftBranchAggregateTest extends TestCase
{
    public function test_exclusive_aggregates_ignore_trashed_descendants(): void
    {
        // Root > [A(10, active), B(20, active), C(30, inactive)]
        // descendants_total on root should equal sum over live subtree's
        // descendants only. After soft-deleting A, root's exclusive
        // aggregates must reflect the post-trash live set — not the
        // pre-trash snapshot.
        $root = new SoftBranch(['name' => 'root', 'tickets' => 0, 'active' => 1]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $a = new SoftBranch(['name' => 'A', 'tickets' => 10, 'active' => 1]);
        $a->appendToNode($root)->save();

        $b = new SoftBranch(['name' => 'B', 'tickets' => 20, 'active' => 1]);
        $b->appendToNode($root->refresh())->save();

        $c = new SoftBranch(['name' => 'C', 'tickets' => 30, 'active' => 0]);
        $c->appendToNode($root->refresh())->save();

        $root->refresh();
        $this->assertSame(60, $root->descendants_total, 'baseline: 10 + 20 + 30');
        $this->assertSame(3, $root->descendants_count);
        $this->assertSame(30, $root->descendants_max);
        $this->assertSame(30, $root->active_tickets_total, 'raw-filter baseline: A + B (both active)');

        // Soft-delete C. Live descendants of root = A, B.
        $c = SoftBranch::query()->where('name', 'C')->firstOrFail();
        $c->delete();

        $root->refresh();
        $this->assertSame(
            30,
            $root->descendants_total,
            'after soft-delete of C, exclusive sum should drop to A + B = 30',
        );
        $this->assertSame(2, $root->descendants_count);
        $this->assertSame(20, $root->descendants_max);
        $this->assertSame(30, $root->active_tickets_total);

        $this->assertFalse(SoftBranch::aggregatesAreBroken());
    }

    public function test_trashed_ancestor_stored_values_stay_frozen_during_descendant_mutations(): void
    {
        // Set up: Root > A > B. Trash A (cascade trashes B). Then
        // modify the tree elsewhere — e.g., add a sibling to root.
        // A's stored values must not change while it's trashed.
        $root = new SoftBranch(['name' => 'root', 'tickets' => 0, 'active' => 1]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $a = new SoftBranch(['name' => 'A', 'tickets' => 5, 'active' => 1]);
        $a->appendToNode($root)->save();
        $a = $a->refresh();

        $b = new SoftBranch(['name' => 'B', 'tickets' => 7, 'active' => 1]);
        $b->appendToNode($a)->save();

        $a->refresh();
        $aStoredBefore = [
            'descendants_total' => $a->descendants_total,
            'descendants_count' => $a->descendants_count,
            'descendants_max' => $a->descendants_max,
        ];

        // Trash A. Cascade trashes B too.
        $a->delete();

        // Mutate the live world: add a new sibling to root.
        $d = new SoftBranch(['name' => 'D', 'tickets' => 100, 'active' => 1]);
        $d->appendToNode($root->refresh())->save();

        $aAfter = SoftBranch::withTrashed()->where('name', 'A')->firstOrFail();
        $this->assertSame(
            $aStoredBefore,
            [
                'descendants_total' => $aAfter->descendants_total,
                'descendants_count' => $aAfter->descendants_count,
                'descendants_max' => $aAfter->descendants_max,
            ],
            "A's stored aggregates must stay frozen while trashed",
        );

        // Root reflects the live set: just D.
        $root->refresh();
        $this->assertSame(100, $root->descendants_total);
        $this->assertSame(1, $root->descendants_count);
    }

    public function test_restore_resyncs_subtree_aggregates_against_current_live_set(): void
    {
        // Set up: Root > A > [B(5), C(10)]. Trash A (cascade B and C).
        // Force-delete C while it's trashed. Restore A — A's stored
        // aggregates and root's aggregates must reflect the now-live
        // subtree (which is just A + B = 5 tickets).
        $this->allowBrokenTreeAtTearDown = true;

        $root = new SoftBranch(['name' => 'root', 'tickets' => 0, 'active' => 1]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $a = new SoftBranch(['name' => 'A', 'tickets' => 0, 'active' => 1]);
        $a->appendToNode($root)->save();

        $b = new SoftBranch(['name' => 'B', 'tickets' => 5, 'active' => 1]);
        $b->appendToNode($a->refresh())->save();

        $c = new SoftBranch(['name' => 'C', 'tickets' => 10, 'active' => 1]);
        $c->appendToNode($a->refresh())->save();

        $a = SoftBranch::query()->where('name', 'A')->firstOrFail();
        $a->delete();

        // Force-delete C while it's trashed. C is a leaf so this is the
        // safe variant of force-delete-of-trashed.
        $cTrashed = SoftBranch::withTrashed()->where('name', 'C')->firstOrFail();
        $cTrashed->forceDelete();

        // Restore A. Cascade restores B (matching timestamp).
        $aTrashed = SoftBranch::withTrashed()->where('name', 'A')->firstOrFail();
        $aTrashed->restore();

        $a = $a->refresh();
        $root->refresh();

        $this->assertSame(5, $a->descendants_total, 'A.descendants_total = B only (C is gone)');
        $this->assertSame(1, $a->descendants_count);
        $this->assertSame(5, $a->descendants_max);

        $this->assertSame(5, $root->descendants_total, 'root sees A.tickets=0 + B.tickets=5 below');
        $this->assertSame(2, $root->descendants_count);
        $this->assertSame(5, $root->descendants_max);

        $this->assertFalse(SoftBranch::aggregatesAreBroken());
    }

    public function test_raw_filter_aggregate_settles_after_restore(): void
    {
        // active_tickets_total uses a raw SQL filter (`active = 1`).
        // It's maintained via RecomputeMaintenance — the snapshot-
        // semantics filter must hold here too.
        $root = new SoftBranch(['name' => 'root', 'tickets' => 0, 'active' => 1]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $active = new SoftBranch(['name' => 'active', 'tickets' => 10, 'active' => 1]);
        $active->appendToNode($root)->save();

        $inactive = new SoftBranch(['name' => 'inactive', 'tickets' => 25, 'active' => 0]);
        $inactive->appendToNode($root->refresh())->save();

        $root->refresh();
        $this->assertSame(10, $root->active_tickets_total, 'baseline: only active row contributes');

        $active = SoftBranch::query()->where('name', 'active')->firstOrFail();
        $active->delete();

        $root->refresh();
        $this->assertSame(
            0,
            $root->active_tickets_total,
            'trashed active row removed from active_tickets_total',
        );

        $activeTrashed = SoftBranch::withTrashed()->where('name', 'active')->firstOrFail();
        $activeTrashed->restore();

        $root->refresh();
        $this->assertSame(10, $root->active_tickets_total, 'restored active row credited again');
    }
}
