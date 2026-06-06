<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Functions;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\FlagArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * M3 maintenance tests: BoolOr / BoolAnd display columns stay
 * correct across inserts, updates, deletes, and recompute. Both
 * derive from a single (`__sum`, `__count`) companion pair so a
 * single mutation produces a single delta UPDATE.
 */
final class BoolRollupMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    #[Test]
    public function root_alone_reflects_its_own_active_flag(): void
    {
        $root = new FlagArea(['name' => 'Root', 'active' => true]);
        $root->saveAsRoot();
        $root->refresh();

        $this->assertTrue($root->any_active);
        $this->assertTrue($root->all_active);

        $root->update(['active' => false]);
        $root->refresh();

        $this->assertFalse($root->any_active);
        $this->assertFalse($root->all_active);
    }

    #[Test]
    public function mixed_subtree_distinguishes_any_from_all(): void
    {
        $root = new FlagArea(['name' => 'Root', 'active' => true]);
        $root->saveAsRoot();

        $a = new FlagArea(['name' => 'A', 'active' => false]);
        $a->appendToNode($root)->save();

        $b = new FlagArea(['name' => 'B', 'active' => true]);
        $b->appendToNode($root->refresh())->save();

        $root->refresh();
        // Subtree = [true, false, true] → any=true, all=false.
        $this->assertTrue($root->any_active);
        $this->assertFalse($root->all_active);

        // A alone is its own subtree.
        $a->refresh();
        $this->assertFalse($a->any_active);
        $this->assertFalse($a->all_active);

        // B alone is too.
        $b->refresh();
        $this->assertTrue($b->any_active);
        $this->assertTrue($b->all_active);
    }

    #[Test]
    public function flipping_descendant_active_propagates(): void
    {
        $root = new FlagArea(['name' => 'Root', 'active' => true]);
        $root->saveAsRoot();

        $a = new FlagArea(['name' => 'A', 'active' => true]);
        $a->appendToNode($root)->save();

        $b = new FlagArea(['name' => 'B', 'active' => true]);
        $b->appendToNode($root->refresh())->save();

        $root->refresh();
        $this->assertTrue($root->all_active);

        // Flip B false. Now any=true, all=false.
        $b->refresh()->update(['active' => false]);
        $root->refresh();
        $this->assertTrue($root->any_active);
        $this->assertFalse($root->all_active);

        // Flip B back. all=true again.
        $b->refresh()->update(['active' => true]);
        $root->refresh();
        $this->assertTrue($root->all_active);
    }

    #[Test]
    public function descendant_delete_re_evaluates_rollups(): void
    {
        $root = new FlagArea(['name' => 'Root', 'active' => false]);
        $root->saveAsRoot();

        $a = new FlagArea(['name' => 'A', 'active' => true]);
        $a->appendToNode($root)->save();

        $root->refresh();
        $this->assertTrue($root->any_active);

        // Delete A. Subtree = [false] → any=false, all=false.
        $a->refresh()->delete();
        $root->refresh();
        $this->assertFalse($root->any_active);
        $this->assertFalse($root->all_active);
    }

    #[Test]
    public function fix_aggregates_repairs_corruption(): void
    {
        $root = new FlagArea(['name' => 'Root', 'active' => true]);
        $root->saveAsRoot();

        $a = new FlagArea(['name' => 'A', 'active' => false]);
        $a->appendToNode($root)->save();

        FlagArea::query()->where('id', $root->refresh()->getKey())->update([
            'any_active' => false,
            'all_active' => true,
        ]);

        FlagArea::fixAggregates($root);
        $root->refresh();

        // True/False under root → any=true, all=false.
        $this->assertTrue($root->any_active);
        $this->assertFalse($root->all_active);
    }

    #[Test]
    public function aggregate_errors_is_silent_on_correctly_maintained_chain_tree(): void
    {
        // Chain shape: Root(true) → A(true) → B(false) → C(true).
        // Each ancestor's any_active should be true, all_active should
        // be true at C, then false at B / A / Root.
        $values = [true, true, false, true];
        $previous = null;
        foreach ($values as $i => $value) {
            $node = new FlagArea(['name' => 'Node'.$i, 'active' => $value]);
            if (! $previous instanceof FlagArea) {
                $node->saveAsRoot();
            } else {
                $node->appendToNode($previous->refresh())->save();
            }
            $previous = $node;
        }

        $errors = FlagArea::aggregateErrors();
        $this->assertSame(0, array_sum($errors), 'aggregateErrors() flagged drift on a clean chain: '.json_encode($errors));
    }

    #[Test]
    public function cross_parent_move_re_evaluates_rollups_on_both_ancestor_chains(): void
    {
        // Two siblings with different active values:
        //   Root(true)
        //   ├── A(true)
        //   │   └── A1(false)   ← carries the only `false` in the tree
        //   └── B(true)
        // any_active = true everywhere; all_active = false at A and
        // Root (because A1=false drags both down).
        $root = new FlagArea(['name' => 'Root', 'active' => true]);
        $root->saveAsRoot();

        $a = new FlagArea(['name' => 'A', 'active' => true]);
        $a->appendToNode($root)->save();

        $a1 = new FlagArea(['name' => 'A1', 'active' => false]);
        $a1->appendToNode($a->refresh())->save();

        $b = new FlagArea(['name' => 'B', 'active' => true]);
        $b->appendToNode($root->refresh())->save();

        $a->refresh();
        $this->assertFalse($a->all_active, 'A.all_active drops because A1=false');

        // Move A1 from A's subtree to B's subtree.
        $a1->refresh()->moveTo($b->refresh());
        $a1->save();

        $a->refresh();
        $b->refresh();
        $root->refresh();

        // Old chain (A): no more `false` descendants → all_active climbs
        // back to true.
        $this->assertTrue($a->all_active, 'A.all_active recovers after losing A1');
        $this->assertTrue($a->any_active, 'A.any_active stays true (A itself is true)');

        // New chain (B): now carries A1=false → all_active drops.
        $this->assertFalse($b->all_active, 'B.all_active drops after gaining A1=false');
        $this->assertTrue($b->any_active);

        // Root sees the same multiset {true, true, true, false} either
        // way, so its rollups don't change.
        $this->assertTrue($root->any_active);
        $this->assertFalse($root->all_active);
    }

    #[Test]
    public function make_root_subtracts_descendant_from_old_chain_and_starts_fresh_at_new_root(): void
    {
        // Root(true) → A(true) → A1(false). A.all_active = false because
        // of A1; Root.all_active = false too.
        $root = new FlagArea(['name' => 'Root', 'active' => true]);
        $root->saveAsRoot();

        $a = new FlagArea(['name' => 'A', 'active' => true]);
        $a->appendToNode($root)->save();

        $a1 = new FlagArea(['name' => 'A1', 'active' => false]);
        $a1->appendToNode($a->refresh())->save();

        $root->refresh();
        $this->assertFalse($root->all_active);

        // Promote A (carrying A1) to its own tree. Old root is now
        // alone with active=true → all_active flips back to true.
        // New A tree still has the false descendant → A.all_active
        // stays false.
        $a->refresh()->makeRoot()->save();

        $root->refresh();
        $a->refresh();

        $this->assertTrue($root->all_active, 'old root has no false descendants left');
        $this->assertFalse($a->all_active, 'new root still carries A1=false');
    }

    #[Test]
    public function fix_aggregates_is_a_no_op_on_a_correctly_maintained_tree(): void
    {
        // Steady-state chain: Root(true) → A(false) → B(true).
        $root = new FlagArea(['name' => 'Root', 'active' => true]);
        $root->saveAsRoot();

        $a = new FlagArea(['name' => 'A', 'active' => false]);
        $a->appendToNode($root)->save();

        $b = new FlagArea(['name' => 'B', 'active' => true]);
        $b->appendToNode($a->refresh())->save();

        $result = FlagArea::fixAggregates($root->refresh());

        $this->assertSame(
            0,
            $result->totalRowsUpdated,
            'fixAggregates rewrote rows on a clean tree — would mean per-mutation maintenance drifted',
        );
    }

    #[Test]
    public function fix_tree_followed_by_fix_aggregates_clears_synthetic_corruption(): void
    {
        // Build a clean tree, then trash both lft/rgt AND the rollup
        // columns to simulate a worst-case post-incident state.
        $root = new FlagArea(['name' => 'Root', 'active' => true]);
        $root->saveAsRoot();

        $a = new FlagArea(['name' => 'A', 'active' => false]);
        $a->appendToNode($root)->save();

        $b = new FlagArea(['name' => 'B', 'active' => true]);
        $b->appendToNode($root->refresh())->save();

        // Smash structural columns on one row (the package's repair
        // contract: parent_id is the source of truth).
        FlagArea::query()->where('id', $a->id)->update([
            'lft' => 9999,
            'rgt' => 9998,
            'any_active' => false,
            'all_active' => true,
        ]);

        // fixTree rebuilds lft/rgt from parent_id and calls
        // fixAggregates internally on the post-rebuild structure.
        FlagArea::fixTree();

        $root->refresh();
        $this->assertTrue($root->any_active, 'rollups recover when fixTree triggers internal fixAggregates');
        $this->assertFalse($root->all_active, 'A=false correctly pulls all_active down again');
    }
}
