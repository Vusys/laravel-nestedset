<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use LogicException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Edge cases that are easy to get wrong and that the public API needs to
 * handle predictably: self-moves, descendant-moves, save-with-no-pending-op,
 * empty-tree operations.
 */
final class EdgeCasesTest extends TestCase
{
    public function test_save_without_pending_operation_works_as_normal_update(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root = $root->refresh();

        // Plain attribute update — no tree mutation.
        $root->name = 'Renamed';
        $root->save();

        $this->assertSame('Renamed', $root->refresh()->name);
        $this->assertSame(1, $root->lft);
        $this->assertSame(2, $root->rgt);
    }

    public function test_save_after_mutation_clears_pending_so_resaving_is_a_noop(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root = $root->refresh();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root)->save();

        $before = DB::table('categories')->orderBy('id')->get()->toArray();

        // Second save shouldn't re-run makeGap / move logic.
        $a->save();

        $after = DB::table('categories')->orderBy('id')->get()->toArray();

        $this->assertEquals($before, $after);
        $this->assertFalse(Category::isBroken());
    }

    public function test_appending_node_to_its_own_descendant_throws(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 6, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',   'lft' => 3, 'rgt' => 4, 'depth' => 2, 'parent_id' => 2],
        ]);

        $a = Category::query()->findOrFail(2);
        $aa = Category::query()->findOrFail(3);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Cannot move node into itself/');

        // Cycle: try to nest A inside its descendant AA.
        $this->allowBrokenTreeAtTearDown = true; // exception thrown before tree change
        $a->appendToNode($aa)->save();
    }

    public function test_move_to_same_position_is_a_no_op(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 6, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'B',    'lft' => 4, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
        ]);

        $b = Category::query()->findOrFail(3);
        $root = Category::query()->findOrFail(1);

        $before = DB::table('categories')->orderBy('id')->get()->toArray();
        $b->appendToNode($root)->save();
        $after = DB::table('categories')->orderBy('id')->get()->toArray();

        // B was already at the end of Root — appending again should not corrupt.
        $this->assertEquals($before, $after);
        $this->assertFalse(Category::isBroken());
    }

    public function test_up_returns_false_on_lone_root(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $this->assertFalse($root->refresh()->up());
        $this->assertFalse($root->refresh()->down());
    }

    public function test_count_errors_on_empty_table_returns_zero(): void
    {
        $errors = Category::countErrors();

        foreach ($errors as $count) {
            $this->assertSame(0, $count);
        }

        $this->assertFalse(Category::isBroken());
    }

    public function test_node_height_and_descendant_count_match_lft_rgt(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 7,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',   'lft' => 3, 'rgt' => 4,  'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'AB',   'lft' => 5, 'rgt' => 6,  'depth' => 2, 'parent_id' => 2],
            ['id' => 5, 'name' => 'B',    'lft' => 8, 'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
        ]);

        $root = Category::query()->findOrFail(1);
        $a = Category::query()->findOrFail(2);
        $aa = Category::query()->findOrFail(3);

        $this->assertSame(10, $root->getSubtreeSize());   // rgt - lft + 1
        $this->assertSame(4, $root->getDescendantCount()); // A, AA, AB, B
        $this->assertSame(6, $a->getSubtreeSize());
        $this->assertSame(2, $a->getDescendantCount());    // AA, AB
        $this->assertSame(2, $aa->getSubtreeSize());
        $this->assertSame(0, $aa->getDescendantCount());
    }

    public function test_is_sibling_of_returns_false_for_self(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 6, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'B',    'lft' => 4, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
        ]);

        $a = Category::query()->findOrFail(2);
        $b = Category::query()->findOrFail(3);

        $this->assertTrue($a->isSiblingOf($b));
        $this->assertFalse($a->isSiblingOf($a));
    }
}
