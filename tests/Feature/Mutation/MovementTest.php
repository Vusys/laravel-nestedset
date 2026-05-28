<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Mutation;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Built tree:
 *  Root
 *    A
 *      AA
 *      AB
 *    B
 */
final class MovementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 7,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',   'lft' => 3, 'rgt' => 4,  'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'AB',   'lft' => 5, 'rgt' => 6,  'depth' => 2, 'parent_id' => 2],
            ['id' => 5, 'name' => 'B',    'lft' => 8, 'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
        ]);
    }

    public function test_append_to_node_updates_parent_id_depth_and_bounds_in_one_atomic_save(): void
    {
        $ab = Category::query()->findOrFail(4);
        $b = Category::query()->findOrFail(5);

        $ab->appendToNode($b)->save();

        $ab = $ab->refresh();
        $b = $b->refresh();

        $this->assertSame($b->id, $ab->parent_id);
        $this->assertSame($b->depth + 1, $ab->depth);
        $this->assertTrue($b->getBounds()->contains($ab->getBounds()));
        $this->assertFalse(Category::isBroken());
    }

    public function test_subtree_move_carries_every_descendant_with_depth_shifted_relative_to_new_parent(): void
    {
        $a = Category::query()->findOrFail(2);
        $b = Category::query()->findOrFail(5);

        // Move A (with AA, AB) under B.
        $a->appendToNode($b)->save();

        $a = $a->refresh();
        $b = $b->refresh();
        $aa = Category::query()->findOrFail(3);
        $ab = Category::query()->findOrFail(4);

        $this->assertSame($b->id, $a->parent_id);
        $this->assertSame($a->id, $aa->parent_id);
        $this->assertSame($a->id, $ab->parent_id);

        // Depth shifts: A was depth 1, now depth 2; AA/AB were depth 2, now 3.
        $this->assertSame(2, $a->depth);
        $this->assertSame(3, $aa->depth);
        $this->assertSame(3, $ab->depth);

        $this->assertFalse(Category::isBroken());
    }

    public function test_save_as_root_clears_parent_id_and_resets_depth_to_zero(): void
    {
        $a = Category::query()->findOrFail(2);

        $a->saveAsRoot();
        $a = $a->refresh();

        $this->assertNull($a->parent_id);
        $this->assertSame(0, $a->depth);
        $this->assertFalse(Category::isBroken());
    }

    public function test_up_places_node_before_its_previous_sibling_via_lft_swap(): void
    {
        $b = Category::query()->findOrFail(5);
        $a = Category::query()->findOrFail(2);

        $this->assertTrue($b->up());

        $a = $a->refresh();
        $b = $b->refresh();

        // B should now come before A (smaller lft).
        $this->assertLessThan($a->lft, $b->lft);
        $this->assertFalse(Category::isBroken());
    }

    public function test_up_returns_false_on_first_child_with_no_previous_sibling_to_swap_with(): void
    {
        $a = Category::query()->findOrFail(2);
        $this->assertFalse($a->up());
    }

    public function test_down_places_node_after_its_next_sibling_via_lft_swap(): void
    {
        $a = Category::query()->findOrFail(2);

        $this->assertTrue($a->down());

        $a = $a->refresh();
        $b = Category::query()->findOrFail(5);

        $this->assertGreaterThan($b->lft, $a->lft);
        $this->assertFalse(Category::isBroken());
    }

    public function test_has_moved_flag_is_true_immediately_after_a_structural_save(): void
    {
        $ab = Category::query()->findOrFail(4);
        $b = Category::query()->findOrFail(5);

        $ab->appendToNode($b)->save();

        $this->assertTrue($ab->hasMoved());
    }
}
