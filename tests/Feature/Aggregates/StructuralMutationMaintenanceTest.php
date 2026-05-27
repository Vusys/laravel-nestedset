<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Concerns\HasTreeMutation;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\SoftBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Phase G: structural mutations (move / makeRoot) and soft-delete
 * restore. Inserts already worked via the `created` event hook from
 * Phase D; this phase adds the existing-node path through
 * {@see HasTreeMutation::onAfterPendingAction()},
 * the `restored` listener, and the `replicate()` override.
 */
final class StructuralMutationMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    private function asInt(mixed $value): int
    {
        if ($value === null || ! is_numeric($value)) {
            $this->fail('Expected numeric, got '.get_debug_type($value));
        }

        return (int) $value;
    }

    private function asNullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value)) {
            $this->fail('Expected numeric or null, got '.get_debug_type($value));
        }

        return (int) $value;
    }

    /**
     * Walks every node and asserts stored aggregates match the freshly
     * recomputed values. Used as a post-mutation invariant check.
     */
    private function assertAggregatesIntact(): void
    {
        foreach (Area::query()->orderBy('lft')->get() as $node) {
            $this->assertSame(
                $this->asInt($node->freshAggregate('tickets_total')),
                $this->asInt($node->tickets_total),
                "tickets_total drift on node {$node->id} ({$node->name})",
            );
            $this->assertSame(
                $this->asInt($node->freshAggregate('tickets_count_all')),
                $this->asInt($node->tickets_count_all),
                "tickets_count_all drift on node {$node->id} ({$node->name})",
            );
            $this->assertSame(
                $this->asNullableInt($node->freshAggregate('tickets_min')),
                $this->asNullableInt($node->tickets_min),
                "tickets_min drift on node {$node->id} ({$node->name})",
            );
            $this->assertSame(
                $this->asNullableInt($node->freshAggregate('tickets_max')),
                $this->asNullableInt($node->tickets_max),
                "tickets_max drift on node {$node->id} ({$node->name})",
            );
        }
    }

    // ----------------------------------------------------------------
    // Move between siblings of the same parent
    // ----------------------------------------------------------------

    public function test_move_within_parent_via_insert_after_keeps_aggregates_intact(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();

        // Move A after B — same parent, no aggregate change for root.
        $a->refresh();
        $b->refresh();
        $a->insertAfterNode($b);

        $this->assertSame(175, $this->asInt($root->refresh()->tickets_total));
        $this->assertSame(3, $this->asInt($root->refresh()->tickets_count_all));
        $this->assertAggregatesIntact();
    }

    // ----------------------------------------------------------------
    // Cross-parent move
    // ----------------------------------------------------------------

    public function test_cross_parent_move_subtracts_from_old_and_adds_to_new(): void
    {
        // Two-branch tree:
        //   Root(100)
        //   ├── A(50)
        //   │   └── A1(30)
        //   └── B(25)
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 30]);
        $a1->appendToNode($a->refresh())->save();

        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();

        $a = $a->refresh();
        $b = $b->refresh();

        // Move A1 (subtree of A) under B.
        $a1->refresh();
        $a1->appendToNode($b)->save();

        $a->refresh();
        $b->refresh();

        // A loses A1(30); A becomes leaf-sized: total=50, count=1.
        $this->assertSame(50, $this->asInt($a->tickets_total));
        $this->assertSame(1, $this->asInt($a->tickets_count_all));

        // B gains A1(30): total=55, count=2.
        $this->assertSame(55, $this->asInt($b->tickets_total));
        $this->assertSame(2, $this->asInt($b->tickets_count_all));

        // Root unchanged: 205, 4.
        $this->assertSame(205, $this->asInt($root->refresh()->tickets_total));
        $this->assertSame(4, $this->asInt($root->refresh()->tickets_count_all));

        $this->assertAggregatesIntact();
    }

    public function test_cross_parent_move_recomputes_max_when_holder_leaves(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 999]); // A's max holder
        $a1->appendToNode($a->refresh())->save();

        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();

        $a = $a->refresh();
        $b = $b->refresh();

        $this->assertSame(999, $this->asInt($a->tickets_max));

        // Move A1 (the holder) out of A.
        $a1->refresh();
        $a1->appendToNode($b)->save();

        $a->refresh();
        $b->refresh();

        // A's max drops to its own value 50 (no other children).
        $this->assertSame(50, $this->asInt($a->tickets_max));

        // B's max rises to 999 via cheap-delta.
        $this->assertSame(999, $this->asInt($b->tickets_max));

        // Root's max remains 999 (still in the subtree, just elsewhere).
        $this->assertSame(999, $this->asInt($root->refresh()->tickets_max));

        $this->assertAggregatesIntact();
    }

    // ----------------------------------------------------------------
    // makeRoot — old chain loses, no new chain to add to
    // ----------------------------------------------------------------

    public function test_make_root_subtracts_from_old_ancestor_chain(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 30]);
        $a1->appendToNode($a->refresh())->save();

        // Promote A1 to its own root.
        $a1->refresh();
        $a1->makeRoot()->save();

        $root->refresh();
        $a->refresh();
        $a1->refresh();

        $this->assertSame(150, $this->asInt($root->tickets_total)); // 100 + 50, lost 30
        $this->assertSame(2, $this->asInt($root->tickets_count_all));

        $this->assertSame(50, $this->asInt($a->tickets_total));
        $this->assertSame(1, $this->asInt($a->tickets_count_all));

        $this->assertSame(30, $this->asInt($a1->tickets_total));
        $this->assertSame(1, $this->asInt($a1->tickets_count_all));

        $this->assertAggregatesIntact();
    }

    // ----------------------------------------------------------------
    // Move into descendant: existing logic rejects; aggregates untouched
    // ----------------------------------------------------------------

    public function test_move_into_own_descendant_does_not_corrupt_aggregates(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 30]);
        $a1->appendToNode($a->refresh())->save();

        $rootTotalBefore = $this->asInt($root->refresh()->tickets_total);

        try {
            // A under its own child A1 — should throw.
            $a->refresh();
            $a->appendToNode($a1->refresh())->save();
            $this->fail('Expected move-into-descendant to throw.');
        } catch (\LogicException) {
            // Expected.
        }

        $this->assertSame(
            $rootTotalBefore,
            $this->asInt($root->refresh()->tickets_total),
            'aggregates must not change when the structural move is rejected',
        );

        $this->assertAggregatesIntact();
    }

    // ----------------------------------------------------------------
    // Soft-delete + restore
    // ----------------------------------------------------------------

    public function test_aggregate_on_delete_and_on_restore_handlers_are_inverses_when_called_directly(): void
    {
        // Handler-level test: invoke applyAggregateOnDelete() /
        // applyAggregateOnRestore() directly on Area (which does NOT
        // use SoftDeletes) to pin that the two handlers compose to
        // identity on the ancestor chain — independent of the
        // Eloquent soft-delete lifecycle that calls them in practice.
        //
        // See the companion test below for the full Eloquent
        // soft-delete + restore lifecycle exercised through SoftBranch.
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $rootBefore = $this->asInt($root->refresh()->tickets_total);

        $a->refresh();
        $a->applyAggregateOnDelete();

        $this->assertSame(
            $rootBefore - 50,
            $this->asInt($root->refresh()->tickets_total),
            'ancestor lost A on direct applyAggregateOnDelete call',
        );

        $a->applyAggregateOnRestore();

        $this->assertSame(
            $rootBefore,
            $this->asInt($root->refresh()->tickets_total),
            'ancestor regained A on direct applyAggregateOnRestore call',
        );
    }

    public function test_eloquent_soft_delete_then_restore_keeps_ancestor_aggregates_consistent(): void
    {
        // Full lifecycle through Eloquent's soft-delete events on a
        // model that actually uses SoftDeletes (SoftBranch). This
        // exercises the wired-up onDelete / onRestore observers, not
        // just the handlers in isolation.
        $root = new SoftBranch(['name' => 'Root', 'tickets' => 0, 'active' => 1]);
        $root->saveAsRoot();

        $a = new SoftBranch(['name' => 'A', 'tickets' => 30, 'active' => 1]);
        $a->appendToNode($root->refresh())->save();

        $b = new SoftBranch(['name' => 'B', 'tickets' => 70, 'active' => 1]);
        $b->appendToNode($root->refresh())->save();

        $rootBefore = $this->asInt($root->refresh()->tickets_total);
        $this->assertSame(100, $rootBefore, 'baseline sum 30 + 70');

        // Eloquent soft-delete fires the onDelete handler under the hood.
        $a->refresh()->delete();

        $this->assertSame(
            70,
            $this->asInt($root->refresh()->tickets_total),
            'root lost A on soft-delete via the real Eloquent lifecycle',
        );

        // Eloquent restore fires the onRestore handler.
        SoftBranch::withTrashed()->findOrFail($a->id)->restore();

        $this->assertSame(
            100,
            $this->asInt($root->refresh()->tickets_total),
            'root regained A on restore via the real Eloquent lifecycle',
        );

        $this->assertFalse(SoftBranch::aggregatesAreBroken(), 'aggregate state must be clean after delete+restore round trip');
    }

    // ----------------------------------------------------------------
    // replicate(): clone produces empty aggregates that backfill on save
    // ----------------------------------------------------------------

    public function test_replicate_clones_with_empty_aggregates(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 30]);
        $a1->appendToNode($a->refresh())->save();

        $a->refresh();
        $this->assertSame(80, $this->asInt($a->tickets_total));

        // Clone — replicated instance should have empty aggregate columns
        // even though the source's tickets_total is 80.
        $clone = $a->replicate();

        $this->assertSame(0, $this->asInt($clone->tickets_total));
        $this->assertSame(0, $this->asInt($clone->tickets_count_all));
        $this->assertNull($clone->tickets_min);
        $this->assertNull($clone->tickets_max);
        $this->assertNull($clone->tickets_avg);
    }

    public function test_replicated_clone_backfills_aggregates_when_placed(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        // Replicate A (which has stale aggregates from its current
        // subtree); name it differently and place it under the root.
        $a->refresh();
        $clone = $a->replicate();
        $clone->name = 'A-prime';
        $clone->appendToNode($root->refresh())->save();
        $clone->refresh();

        // Clone is a leaf — its aggregates should reflect just itself.
        $this->assertSame(50, $this->asInt($clone->tickets_total));
        $this->assertSame(1, $this->asInt($clone->tickets_count_all));
        $this->assertSame(50, $this->asInt($clone->tickets_min));
        $this->assertSame(50, $this->asInt($clone->tickets_max));

        $this->assertAggregatesIntact();
    }

    // ----------------------------------------------------------------
    // Transaction safety: rollback during move leaves aggregates intact
    // ----------------------------------------------------------------

    public function test_transaction_rollback_during_save_leaves_aggregates_intact(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $rootBefore = $this->asInt($root->refresh()->tickets_total);
        $aBefore = $this->asInt($a->refresh()->tickets_total);

        // Wrap a move + an intentional failure in a manual transaction.
        // The closure always throws; if it ever didn't, the assertions
        // below would catch the missing rollback.
        try {
            DB::transaction(function () use ($a): never {
                $a->makeRoot()->save();
                throw new \RuntimeException('roll it back');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('roll it back', $e->getMessage());
        }

        $this->assertSame(
            $rootBefore,
            $this->asInt($root->refresh()->tickets_total),
            'rollback must revert aggregate changes alongside the structural mutation',
        );
        $this->assertSame(
            $aBefore,
            $this->asInt($a->refresh()->tickets_total),
        );

        $this->assertAggregatesIntact();
    }
}
