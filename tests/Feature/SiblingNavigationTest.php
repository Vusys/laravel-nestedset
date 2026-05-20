<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Concerns\HasTreeMutation;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Direct tests for {@see HasTreeMutation::prevSibling()}
 * and {@see HasTreeMutation::nextSibling()}.
 *
 * These are publicly documented methods (README's tree navigation
 * section) but the existing suite only exercises them indirectly via
 * `up()` / `down()` or the cross-scope tests in `ScopingTest`. A
 * one-off / off-by-one regression in either lookup would not surface
 * in the up/down tests because the displaced row still moves
 * "somewhere".
 *
 * Tree shape used here (unscoped, single root):
 *
 *   Root        lft=1  rgt=10  depth=0
 *     A         lft=2  rgt=3   depth=1   (first child)
 *     B         lft=4  rgt=5   depth=1
 *     C         lft=6  rgt=7   depth=1
 *     D         lft=8  rgt=9   depth=1   (last child)
 */
final class SiblingNavigationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1,  'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2,  'rgt' => 3,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'B',    'lft' => 4,  'rgt' => 5,  'depth' => 1, 'parent_id' => 1],
            ['id' => 4, 'name' => 'C',    'lft' => 6,  'rgt' => 7,  'depth' => 1, 'parent_id' => 1],
            ['id' => 5, 'name' => 'D',    'lft' => 8,  'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
        ]);
    }

    private function find(int $id): Category
    {
        $row = Category::query()->find($id);
        if ($row === null) {
            $this->fail("Category {$id} not found");
        }

        return $row;
    }

    // ----------------------------------------------------------------
    // prevSibling
    // ----------------------------------------------------------------

    public function test_prev_sibling_of_first_child_is_null(): void
    {
        // A is the leftmost child; there is no sibling to its left.
        $this->assertNull($this->find(2)->prevSibling());
    }

    public function test_prev_sibling_of_middle_child_is_the_immediate_left_neighbour(): void
    {
        // C's previous sibling is B, not A.
        $prev = $this->find(4)->prevSibling();

        $this->assertNotNull($prev);
        $this->assertSame(3, $prev->id, 'expected B (id=3), the immediate left neighbour, not A (id=2)');
        $this->assertSame('B', $prev->name);
    }

    public function test_prev_sibling_of_last_child_is_the_immediate_left_neighbour(): void
    {
        $prev = $this->find(5)->prevSibling();

        $this->assertNotNull($prev);
        $this->assertSame(4, $prev->id);
    }

    // ----------------------------------------------------------------
    // nextSibling
    // ----------------------------------------------------------------

    public function test_next_sibling_of_last_child_is_null(): void
    {
        // D is the rightmost child; no sibling to its right.
        $this->assertNull($this->find(5)->nextSibling());
    }

    public function test_next_sibling_of_middle_child_is_the_immediate_right_neighbour(): void
    {
        // B's next sibling is C, not D.
        $next = $this->find(3)->nextSibling();

        $this->assertNotNull($next);
        $this->assertSame(4, $next->id, 'expected C (id=4), the immediate right neighbour, not D (id=5)');
        $this->assertSame('C', $next->name);
    }

    public function test_next_sibling_of_first_child_is_the_immediate_right_neighbour(): void
    {
        $next = $this->find(2)->nextSibling();

        $this->assertNotNull($next);
        $this->assertSame(3, $next->id);
    }

    // ----------------------------------------------------------------
    // Only-child + lone-root edge cases
    // ----------------------------------------------------------------

    public function test_only_child_has_no_siblings_in_either_direction(): void
    {
        // Fresh isolated tree so the "only child" status is unambiguous.
        DB::table('categories')->delete();

        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $only = new Category(['name' => 'Only']);
        $only->appendToNode($root->refresh())->save();
        $only->refresh();

        $this->assertNull($only->prevSibling());
        $this->assertNull($only->nextSibling());
    }

    public function test_lone_root_has_no_siblings_in_either_direction(): void
    {
        DB::table('categories')->delete();

        $root = new Category(['name' => 'Sole root']);
        $root->saveAsRoot();
        $root->refresh();

        $this->assertNull($root->prevSibling());
        $this->assertNull($root->nextSibling());
    }
}
