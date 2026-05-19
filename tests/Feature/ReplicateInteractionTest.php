<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\Fixtures\Models\Monster;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The interactions surrounding `replicate()`: the cloned model
 * inherits the source's user attributes and (intentionally) drops the
 * primary key + stored aggregates, but its interaction with the
 * structural columns, scope columns, soft-delete state, and pending
 * placement is the part most likely to surprise a user.
 *
 * Each test below pins one decision, and (where the behaviour was
 * undefined) fixes the source to match.
 */
final class ReplicateInteractionTest extends TestCase
{
    public function test_replicate_resets_stored_sql_aggregates(): void
    {
        // Area has SUM/COUNT/AVG/MIN/MAX aggregates declared. After a
        // replicate, the clone must read 0 / null on each — taking the
        // source's stored values into a fresh placement would
        // double-count those tickets on the destination subtree.
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root->refresh();

        $rich = new Area(['name' => 'rich', 'tickets' => 10]);
        $rich->appendToNode($root)->save();
        $rich->refresh();

        $this->assertSame(10, $rich->tickets_total);

        $clone = $rich->replicate();

        $this->assertSame(0, $clone->getAttribute('tickets_total'));
        $this->assertSame(0, $clone->getAttribute('tickets_count_all'));
        $this->assertNull($clone->getAttribute('tickets_avg'));
        $this->assertNull($clone->getAttribute('tickets_min'));
        $this->assertNull($clone->getAttribute('tickets_max'));
    }

    public function test_replicate_resets_listener_aggregate_columns(): void
    {
        $root = new Monster(['name' => 'root', 'type' => 'fire', 'base_power' => 1, 'level' => 1]);
        $root->saveAsRoot();
        $root->refresh();

        $rich = new Monster(['name' => 'rich', 'type' => 'fire', 'base_power' => 5, 'level' => 3]);
        $rich->appendToNode($root)->save();
        $rich->refresh();

        // Monster's listener aggregates populated.
        $this->assertSame(15, (int) $rich->weighted_power);

        $clone = $rich->replicate();

        // Sum / Count listener columns reset to 0.
        $this->assertSame(0, $clone->getAttribute('weighted_power'));
        $this->assertSame(0, $clone->getAttribute('fire_count'));
        // Min listener column reset to null.
        $this->assertNull($clone->getAttribute('weakest_level'));
        // AVG display column reset to null.
        $this->assertNull($clone->getAttribute('weighted_avg'));
    }

    public function test_replicate_keeps_source_user_attributes(): void
    {
        // The user-facing source attributes (name, tickets, etc.) DO
        // copy over — that's the whole point of replicate.
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();

        $rich = new Area(['name' => 'rich', 'tickets' => 42]);
        $rich->appendToNode($root->refresh())->save();
        $rich->refresh();

        $clone = $rich->replicate();

        $this->assertSame('rich', $clone->name);
        $this->assertSame(42, $clone->tickets);
    }

    public function test_replicate_clears_structural_columns(): void
    {
        // Without resetting lft/rgt/depth/parent_id on the clone, an
        // accidental ->save() (no appendToNode/makeRoot) would write a
        // duplicate at the source's bounds — the lft/rgt invariant
        // breaks. Reset them so isPlacedInTree() returns false and a
        // bare save is harmless (or surfaces the missing placement).
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root->refresh();

        $left = new Area(['name' => 'left', 'tickets' => 1]);
        $left->appendToNode($root)->save();
        $left = $left->refresh();

        $clone = $left->replicate();

        $this->assertSame(0, (int) $clone->lft);
        $this->assertSame(0, (int) $clone->rgt);
        $this->assertSame(0, (int) $clone->depth);
        $this->assertNull($clone->parent_id);
        $this->assertFalse(
            $clone->isPlacedInTree(),
            'replicated clone must report unplaced until appendToNode/makeRoot runs',
        );
    }

    public function test_replicate_can_be_placed_via_append_to_node_after_clone(): void
    {
        // Counterpart to the above: once the clone IS placed via the
        // public API, the clone lands at the new spot and the tree
        // stays intact.
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root->refresh();

        $left = new Area(['name' => 'left', 'tickets' => 1]);
        $left->appendToNode($root)->save();
        $left->refresh();

        $right = new Area(['name' => 'right', 'tickets' => 2]);
        $right->appendToNode($root->refresh())->save();
        $right->refresh();

        $clone = $left->replicate();
        $clone->appendToNode($right)->save();
        $clone->refresh();

        $this->assertSame($right->id, $clone->parent_id);
        $this->assertSame($right->depth + 1, $clone->depth);
        $this->assertFalse(Area::isBroken());
    }

    public function test_replicate_of_soft_deleted_row_drops_deleted_at(): void
    {
        // Category has SoftDeletes. Replicating a trashed row should
        // produce a fresh model that ISN'T trashed by default — it's
        // intended as a template for a new placement.
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $original = new Category(['name' => 'original']);
        $original->appendToNode($root)->save();
        $original->delete(); // soft-delete
        $original = Category::withTrashed()->where('name', 'original')->firstOrFail();

        $this->assertNotNull($original->deleted_at);

        $clone = $original->replicate();

        $this->assertNull(
            $clone->getAttribute('deleted_at'),
            'a replicated trashed row should not stay trashed by default',
        );
    }

    public function test_replicate_preserves_source_scope_and_cross_scope_placement_requires_realignment(): void
    {
        // MenuItem is scoped by menu_id. The package does not auto-inherit
        // scope from a placement anchor — it enforces consistency via
        // {@see \Vusys\NestedSet\Scope\NestedSetScopeResolver::assertSameScope()}.
        // This test pins three contract points:
        //   1. replicate() preserves the source's scope attributes.
        //   2. Placing the clone under a different-scope anchor without
        //      first aligning scope throws ScopeViolationException.
        //   3. After aligning scope manually, placement succeeds and the
        //      clone joins the anchor's tree.
        $menuA = Menu::create(['name' => 'A']);
        $menuB = Menu::create(['name' => 'B']);

        $aRoot = new MenuItem(['name' => 'aRoot', 'menu_id' => $menuA->id]);
        $aRoot->saveAsRoot();
        $aRoot->refresh();

        $aChild = new MenuItem(['name' => 'aChild', 'menu_id' => $menuA->id]);
        $aChild->appendToNode($aRoot)->save();
        $aChild->refresh();

        $bRoot = new MenuItem(['name' => 'bRoot', 'menu_id' => $menuB->id]);
        $bRoot->saveAsRoot();
        $bRoot->refresh();

        // (1) replicate() retains the source's scope columns — including menu_id.
        $clone = $aChild->replicate();
        $this->assertSame((int) $menuA->id, (int) $clone->menu_id);

        // (2) Placing into menu B's tree without realigning scope throws.
        $cloneWithMismatchedScope = $aChild->replicate();
        try {
            $cloneWithMismatchedScope->appendToNode($bRoot)->save();
            $this->fail('cross-scope placement should have thrown ScopeViolationException');
        } catch (ScopeViolationException $e) {
            $this->assertStringContainsString('menu_id', $e->getMessage());
        }

        // (3) After manual realignment, the placement succeeds and the
        // clone joins menu B's tree under bRoot.
        $clone->menu_id = $menuB->id;
        $clone->appendToNode($bRoot)->save();
        $clone->refresh();

        $this->assertSame((int) $menuB->id, (int) $clone->menu_id);
        $this->assertSame($bRoot->id, $clone->parent_id);
        $this->assertFalse(MenuItem::isBroken(new MenuItem(['menu_id' => $menuA->id])));
        $this->assertFalse(MenuItem::isBroken(new MenuItem(['menu_id' => $menuB->id])));
    }

    public function test_replicate_returns_an_unsaved_instance(): void
    {
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Area(['name' => 's', 'tickets' => 5]);
        $source->appendToNode($root)->save();
        $source->refresh();

        $clone = $source->replicate();

        $this->assertFalse($clone->exists, 'clone is not persisted yet');
        $this->assertNull($clone->getKey(), 'clone has no primary key');
    }
}
