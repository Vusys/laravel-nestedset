<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Vusys\NestedSet\Aggregates\AggregateRegistry;
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

    public function test_root_alone_reflects_its_own_active_flag(): void
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

    public function test_mixed_subtree_distinguishes_any_from_all(): void
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

    public function test_flipping_descendant_active_propagates(): void
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

    public function test_descendant_delete_re_evaluates_rollups(): void
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

    public function test_fix_aggregates_repairs_corruption(): void
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

    public function test_aggregate_errors_is_silent_on_correctly_maintained_chain_tree(): void
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
}
