<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use LogicException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Defensive semantics for the structural mutation API — the cases
 * where "what should the package do?" isn't obvious from the happy
 * path. Each test pins one decision:
 *
 *   - Self-as-own-parent (`$x->appendToNode($x)`) → throws.
 *   - `makeRoot()` on a node already at the root level → no-op.
 *   - `insertBeforeNode` / `insertAfterNode` on a root sibling → places
 *     the new node as a sibling root, no inheritance from sibling.
 *   - Save with `parent_id` mutated by hand AND a pending placement →
 *     the pending placement wins.
 *   - Cross-scope `appendToNode` (different `menu_id`) → throws
 *     `ScopeViolationException`.
 *
 * The roadmap calls these out as "we don't know what the package does"
 * — these tests lock in the answer. If the answer is wrong, we change
 * the implementation; if it's right, future refactors stay honest.
 */
final class MutationSemanticsTest extends TestCase
{
    private function seedFamilyTree(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 8, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',   'lft' => 3, 'rgt' => 4, 'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'B',    'lft' => 6, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
        ]);

        $this->syncSequence('categories');
    }

    // ================================================================
    // Self-as-own-parent
    // ================================================================

    public function test_appending_node_to_itself_throws(): void
    {
        $this->seedFamilyTree();

        $a = Category::query()->findOrFail(2);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Cannot move node into itself/');

        $this->allowBrokenTreeAtTearDown = true; // exception is thrown mid-mutation
        $a->appendToNode($a)->save();
    }

    public function test_prepending_node_to_itself_throws(): void
    {
        $this->seedFamilyTree();

        $a = Category::query()->findOrFail(2);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Cannot move node into itself/');

        $this->allowBrokenTreeAtTearDown = true;
        $a->prependToNode($a)->save();
    }

    public function test_insert_before_node_on_self_is_rejected(): void
    {
        // "Insert before yourself" is logically meaningless — it would
        // be a silent no-op without the guard in actSibling.
        $this->seedFamilyTree();

        $a = Category::query()->findOrFail(2);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sibling of itself/');

        $this->allowBrokenTreeAtTearDown = true;
        $a->insertBeforeNode($a)->save();
    }

    public function test_insert_after_node_on_self_is_rejected(): void
    {
        $this->seedFamilyTree();

        $a = Category::query()->findOrFail(2);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/sibling of itself/');

        $this->allowBrokenTreeAtTearDown = true;
        $a->insertAfterNode($a)->save();
    }

    // ================================================================
    // makeRoot on an already-root row
    // ================================================================

    public function test_make_root_on_already_root_is_a_safe_noop(): void
    {
        $this->seedFamilyTree();

        $root = Category::query()->findOrFail(1);
        $before = DB::table('categories')->orderBy('id')->get()->toArray();

        $root->makeRoot()->save();

        $after = DB::table('categories')->orderBy('id')->get()->toArray();
        $this->assertEquals($before, $after, 'makeRoot on a root must not move any row');
        $this->assertFalse(Category::isBroken());
    }

    // ================================================================
    // insertBeforeNode / insertAfterNode targeting a root
    // ================================================================

    public function test_insert_before_node_on_a_root_creates_a_sibling_root(): void
    {
        // Multiple roots are allowed for unscoped models (forest).
        // Inserting a new node "before" a root should produce another
        // root, not a child of nothing.
        $r1 = new Category(['name' => 'Root 1']);
        $r1->saveAsRoot();
        $r1 = $r1->refresh();

        $newRoot = new Category(['name' => 'New root']);
        $newRoot->insertBeforeNode($r1)->save();
        $newRoot->refresh();
        $r1->refresh();

        $this->assertNull($newRoot->parent_id, 'new node sits at the root level');
        $this->assertSame(0, $newRoot->depth);
        $this->assertLessThan($r1->lft, $newRoot->lft, 'new root sits before the original');
        $this->assertFalse(Category::isBroken());
    }

    public function test_insert_after_node_on_a_root_creates_a_sibling_root(): void
    {
        $r1 = new Category(['name' => 'Root 1']);
        $r1->saveAsRoot();
        $r1 = $r1->refresh();

        $newRoot = new Category(['name' => 'After root']);
        $newRoot->insertAfterNode($r1)->save();
        $newRoot->refresh();
        $r1->refresh();

        $this->assertNull($newRoot->parent_id);
        $this->assertSame(0, $newRoot->depth);
        $this->assertGreaterThan($r1->rgt, $newRoot->lft, 'new root sits after the original');
        $this->assertFalse(Category::isBroken());
    }

    // ================================================================
    // Pending placement wins over hand-set parent_id
    // ================================================================

    public function test_pending_placement_wins_when_parent_id_is_also_set_by_hand(): void
    {
        // A user does `$x->appendToNode($a)` AND sets `$x->parent_id = $b->id`
        // before save. Behaviour to lock in: the pending placement wins —
        // the hand-set parent_id is overwritten by `actAppendTo`.
        $this->seedFamilyTree();

        $a = Category::query()->findOrFail(2);
        $b = Category::query()->findOrFail(4);

        $node = new Category(['name' => 'Conflicted']);
        $node->parent_id = $b->id;       // hand-set
        $node->appendToNode($a)->save(); // pending placement
        $node->refresh();

        $this->assertSame(
            $a->id,
            $node->parent_id,
            'pending appendToNode must overwrite the hand-set parent_id',
        );
        $this->assertSame($a->depth + 1, $node->depth);
        $this->assertFalse(Category::isBroken());
    }

    // ================================================================
    // Cross-scope moves throw ScopeViolationException
    // ================================================================

    public function test_append_to_node_across_scopes_throws(): void
    {
        $m1 = Menu::create(['name' => 'Menu 1']);
        $m2 = Menu::create(['name' => 'Menu 2']);

        $m1Root = new MenuItem(['name' => 'm1_root', 'menu_id' => $m1->id]);
        $m1Root->saveAsRoot();
        $m1Root = $m1Root->refresh();

        $m2Root = new MenuItem(['name' => 'm2_root', 'menu_id' => $m2->id]);
        $m2Root->saveAsRoot();
        $m2Root = $m2Root->refresh();

        $child = new MenuItem(['name' => 'child', 'menu_id' => $m1->id]);
        $child->appendToNode($m1Root)->save();
        $child = $child->refresh();

        $this->expectException(ScopeViolationException::class);

        $this->allowBrokenTreeAtTearDown = false; // throw is pre-mutation
        $child->appendToNode($m2Root)->save();
    }

    public function test_insert_before_node_across_scopes_throws(): void
    {
        $m1 = Menu::create(['name' => 'Menu 1']);
        $m2 = Menu::create(['name' => 'Menu 2']);

        $m1Root = new MenuItem(['name' => 'm1_root', 'menu_id' => $m1->id]);
        $m1Root->saveAsRoot();
        $m1Root = $m1Root->refresh();

        $m2Root = new MenuItem(['name' => 'm2_root', 'menu_id' => $m2->id]);
        $m2Root->saveAsRoot();
        $m2Root = $m2Root->refresh();

        $m2Child = new MenuItem(['name' => 'm2_child', 'menu_id' => $m2->id]);
        $m2Child->appendToNode($m2Root)->save();
        $m2Child = $m2Child->refresh();

        $child = new MenuItem(['name' => 'child', 'menu_id' => $m1->id]);
        $child->appendToNode($m1Root)->save();
        $child = $child->refresh();

        $this->expectException(ScopeViolationException::class);

        $child->insertBeforeNode($m2Child)->save();
    }
}
