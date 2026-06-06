<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Mutation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Events\Mutation\NodeMoved;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Built tree (per setUp):
 *  Root
 *    A
 *    B
 *    C
 *    D
 *
 * Plus a "Spare" root used as a fresh, empty cross-parent destination.
 *
 * Tests `moveTo()` position resolution, edge cases, and the
 * `moveBefore` / `moveAfter` sibling-relative variants. The wrapper
 * delegates to the existing primitives, so we focus on the new behavior
 * (position arithmetic, validation, self-exclusion) rather than
 * re-asserting primitive correctness already covered elsewhere.
 */
final class MoveToTest extends TestCase
{
    private Category $root;

    private Category $a;

    private Category $b;

    private Category $c;

    private Category $d;

    private Category $spare;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root',  'lft' => 1,  'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',     'lft' => 2,  'rgt' => 3,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'B',     'lft' => 4,  'rgt' => 5,  'depth' => 1, 'parent_id' => 1],
            ['id' => 4, 'name' => 'C',     'lft' => 6,  'rgt' => 7,  'depth' => 1, 'parent_id' => 1],
            ['id' => 5, 'name' => 'D',     'lft' => 8,  'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
            ['id' => 6, 'name' => 'Spare', 'lft' => 11, 'rgt' => 12, 'depth' => 0, 'parent_id' => null],
        ]);

        $this->syncSequence('categories');

        $this->root = Category::query()->findOrFail(1);
        $this->a = Category::query()->findOrFail(2);
        $this->b = Category::query()->findOrFail(3);
        $this->c = Category::query()->findOrFail(4);
        $this->d = Category::query()->findOrFail(5);
        $this->spare = Category::query()->findOrFail(6);
    }

    // ----- position resolution: string positions ------------------------

    #[Test]
    public function move_to_last_appends_to_parent(): void
    {
        $node = new Category(['name' => 'New']);
        $node->moveTo($this->spare, 'last')->save();

        $this->assertChildOrderUnder($this->spare, ['New']);
    }

    #[Test]
    public function move_to_first_prepends_to_parent(): void
    {
        $node = new Category(['name' => 'New']);
        $node->moveTo($this->root, 'first')->save();

        $this->assertChildOrderUnder($this->root, ['New', 'A', 'B', 'C', 'D']);
    }

    #[Test]
    public function move_to_default_position_is_last(): void
    {
        $node = new Category(['name' => 'New']);
        $node->moveTo($this->root)->save();

        $this->assertChildOrderUnder($this->root, ['A', 'B', 'C', 'D', 'New']);
    }

    // ----- position resolution: int positions ---------------------------

    #[Test]
    public function move_to_zero_prepends(): void
    {
        $node = new Category(['name' => 'New']);
        $node->moveTo($this->root, 0)->save();

        $this->assertChildOrderUnder($this->root, ['New', 'A', 'B', 'C', 'D']);
    }

    #[Test]
    public function move_to_middle_position_inserts_before_target(): void
    {
        $node = new Category(['name' => 'New']);
        $node->moveTo($this->root, 2)->save();

        // New should land at final index 2 — between B and C.
        $this->assertChildOrderUnder($this->root, ['A', 'B', 'New', 'C', 'D']);
    }

    #[Test]
    public function move_to_position_at_count_appends(): void
    {
        $node = new Category(['name' => 'New']);
        // 4 existing children, position 4 == count → append.
        $node->moveTo($this->root, 4)->save();

        $this->assertChildOrderUnder($this->root, ['A', 'B', 'C', 'D', 'New']);
    }

    #[Test]
    public function move_to_position_past_count_appends(): void
    {
        $node = new Category(['name' => 'New']);
        $node->moveTo($this->root, 99)->save();

        $this->assertChildOrderUnder($this->root, ['A', 'B', 'C', 'D', 'New']);
    }

    #[Test]
    public function move_to_int_position_on_empty_parent_appends(): void
    {
        $node = new Category(['name' => 'New']);
        // Spare has no children — any int position resolves to append.
        $node->moveTo($this->spare, 5)->save();

        $this->assertChildOrderUnder($this->spare, ['New']);
    }

    // ----- same-parent reorder (self-exclusion) -------------------------

    #[Test]
    public function same_parent_reorder_self_excluded_from_index(): void
    {
        // Move A from index 0 to index 2 within its current parent. With
        // self-exclusion, the remaining siblings are [B, C, D]; position 2
        // means "before D", so the final order is B, C, A, D.
        $this->a->moveTo($this->root, 2)->save();

        $this->assertChildOrderUnder($this->root->refresh(), ['B', 'C', 'A', 'D']);
        $this->assertFalse(Category::isBroken());
    }

    #[Test]
    public function same_parent_reorder_to_first(): void
    {
        $this->d->moveTo($this->root, 'first')->save();

        $this->assertChildOrderUnder($this->root->refresh(), ['D', 'A', 'B', 'C']);
        $this->assertFalse(Category::isBroken());
    }

    #[Test]
    public function same_parent_reorder_to_last(): void
    {
        $this->a->moveTo($this->root, 'last')->save();

        $this->assertChildOrderUnder($this->root->refresh(), ['B', 'C', 'D', 'A']);
        $this->assertFalse(Category::isBroken());
    }

    #[Test]
    public function same_parent_reorder_at_count_minus_one_is_last(): void
    {
        // Self-excluded count is 3, position 3 == count → append.
        $this->b->moveTo($this->root, 3)->save();

        $this->assertChildOrderUnder($this->root->refresh(), ['A', 'C', 'D', 'B']);
    }

    // ----- cross-parent move --------------------------------------------

    #[Test]
    public function cross_parent_move_to_specific_position(): void
    {
        $d = Category::query()->findOrFail(5);

        // Build a destination with two existing kids so position 1 is meaningful.
        $x = new Category(['name' => 'X']);
        $x->appendToNode($this->spare->refresh())->save();
        $y = new Category(['name' => 'Y']);
        $y->appendToNode($this->spare->refresh())->save();

        $d->moveTo($this->spare->refresh(), 1)->save();

        $this->assertChildOrderUnder($this->spare->refresh(), ['X', 'D', 'Y']);
        $this->assertFalse(Category::isBroken());
    }

    // ----- moveBefore / moveAfter ---------------------------------------

    #[Test]
    public function move_before_places_node_immediately_left_of_sibling(): void
    {
        $node = new Category(['name' => 'New']);
        $node->moveBefore($this->c)->save();

        $this->assertChildOrderUnder($this->root->refresh(), ['A', 'B', 'New', 'C', 'D']);
    }

    #[Test]
    public function move_after_places_node_immediately_right_of_sibling(): void
    {
        $node = new Category(['name' => 'New']);
        $node->moveAfter($this->b)->save();

        $this->assertChildOrderUnder($this->root->refresh(), ['A', 'B', 'New', 'C', 'D']);
    }

    #[Test]
    public function move_before_existing_node_in_same_parent(): void
    {
        $this->d->moveBefore($this->a)->save();

        $this->assertChildOrderUnder($this->root->refresh(), ['D', 'A', 'B', 'C']);
    }

    #[Test]
    public function move_after_existing_node_in_same_parent(): void
    {
        $this->a->moveAfter($this->c)->save();

        $this->assertChildOrderUnder($this->root->refresh(), ['B', 'C', 'A', 'D']);
    }

    // ----- validation ---------------------------------------------------

    #[Test]
    public function negative_int_position_throws(): void
    {
        $node = new Category(['name' => 'New']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('non-negative');

        $node->moveTo($this->root, -1);
    }

    #[Test]
    public function unrecognized_string_position_throws(): void
    {
        $node = new Category(['name' => 'New']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("'first' or 'last'");

        $node->moveTo($this->root, 'middle');
    }

    #[Test]
    public function unsaved_parent_throws(): void
    {
        $node = new Category(['name' => 'New']);
        $parent = new Category(['name' => 'Unsaved']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('saved parent');

        // Only fires for the int-position arm — the string arms delegate
        // to the primitives without touching $parent->children().
        $node->moveTo($parent, 1);
    }

    #[Test]
    public function cross_scope_throws(): void
    {
        $menu1 = Menu::create(['name' => 'Menu 1']);
        $menu2 = Menu::create(['name' => 'Menu 2']);

        $root1 = new MenuItem(['name' => 'Root1', 'menu_id' => $menu1->id]);
        $root1->saveAsRoot();
        $root2 = new MenuItem(['name' => 'Root2', 'menu_id' => $menu2->id]);
        $root2->saveAsRoot();

        $this->expectException(ScopeViolationException::class);

        $root1->moveTo($root2, 'last')->save();
    }

    #[Test]
    public function cross_scope_throws_eagerly_for_int_position(): void
    {
        $menu1 = Menu::create(['name' => 'Menu 1']);
        $menu2 = Menu::create(['name' => 'Menu 2']);

        $root1 = new MenuItem(['name' => 'Root1', 'menu_id' => $menu1->id]);
        $root1->saveAsRoot();
        $root2 = new MenuItem(['name' => 'Root2', 'menu_id' => $menu2->id]);
        $root2->saveAsRoot();

        $this->expectException(ScopeViolationException::class);

        // Int positions > 0 take the sibling-lookup arm which needs scope
        // for the query, so the scope check is eager (no ->save() needed
        // to surface the error). String positions defer to save-time
        // because they just wrap the primitives.
        $root1->moveTo($root2, 1);
    }

    // ----- zero-delta NodeMoved (documented surface) --------------------

    #[Test]
    public function zero_delta_move_to_same_position_still_emits_event(): void
    {
        Event::fake([NodeMoved::class]);

        // Moving A to position 0 — its current position — should be a
        // structural no-op (no rows shift) but the event surface still
        // fires per the design doc. This locks in the documented behavior.
        $this->a->moveTo($this->root, 0)->save();

        $aId = $this->a->id;
        Event::assertDispatched(NodeMoved::class, fn (NodeMoved $e): bool => $e->nodeId === $aId);
    }

    // ----- parity with existing primitives ------------------------------

    #[Test]
    public function move_to_last_is_equivalent_to_append_to_node(): void
    {
        $viaMoveTo = new Category(['name' => 'Via moveTo']);
        $viaMoveTo->moveTo($this->root, 'last')->save();

        $viaAppend = new Category(['name' => 'Via append']);
        $viaAppend->appendToNode($this->root->refresh())->save();

        $viaMoveTo = $viaMoveTo->refresh();
        $viaAppend = $viaAppend->refresh();

        $this->assertSame($viaMoveTo->depth, $viaAppend->depth);
        $this->assertSame($viaMoveTo->parent_id, $viaAppend->parent_id);
    }

    #[Test]
    public function move_to_first_is_equivalent_to_prepend_to_node(): void
    {
        $viaMoveTo = new Category(['name' => 'Via moveTo']);
        $viaMoveTo->moveTo($this->spare, 'first')->save();

        $viaPrepend = new Category(['name' => 'Via prepend']);
        $viaPrepend->prependToNode($this->spare->refresh())->save();

        $viaMoveTo = $viaMoveTo->refresh();
        $viaPrepend = $viaPrepend->refresh();

        $this->assertSame($viaMoveTo->depth, $viaPrepend->depth);
        $this->assertSame($viaMoveTo->parent_id, $viaPrepend->parent_id);
    }

    // ----- helpers ------------------------------------------------------

    /**
     * @param  list<string>  $expectedNames
     */
    private function assertChildOrderUnder(Category $parent, array $expectedNames): void
    {
        $names = Category::query()
            ->where('parent_id', $parent->id)
            ->orderBy('lft')
            ->pluck('name')
            ->all();

        $this->assertSame($expectedNames, $names, 'Child order under '.$parent->name.' does not match.');
        $this->assertFalse(Category::isBroken(), 'Tree is broken after move.');
    }
}
