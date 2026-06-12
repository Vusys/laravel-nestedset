<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\SoftDelete;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Decision #11 — `up()` / `down()` (and `prevSibling()` / `nextSibling()`)
 * resolve the *immediately adjacent* sibling through the soft-delete scope,
 * so a trashed node sitting in the adjacent slot acts as a wall: the move
 * is a no-op rather than a swap with a hidden row. Reordering across a
 * trashed slot is reorderChildren()'s job (it sees the raw set).
 *
 * Tree:
 *   Root
 *     A
 *     B   (soft-deleted)
 *     C
 */
final class SiblingMoveWithTrashedNeighbourTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 8, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'B',    'lft' => 4, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 4, 'name' => 'C',    'lft' => 6, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
        ]);
        $this->syncSequence('categories');

        Category::query()->findOrFail(3)->delete(); // soft-delete B (the middle slot)
    }

    #[Test]
    public function up_is_a_no_op_when_the_adjacent_slot_is_trashed(): void
    {
        $c = Category::query()->findOrFail(4); // C, adjacent slot held by trashed B

        $this->assertNull($c->prevSibling(), 'the live query cannot see the trashed neighbour');
        $this->assertFalse($c->up(), 'a trashed neighbour walls the one-slot move');

        // Order is unchanged: the trashed slot is intact, C kept its place.
        $this->assertSame(
            ['A', 'B', 'C'],
            Category::withTrashed()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }

    #[Test]
    public function reorder_children_moves_a_live_node_across_the_trashed_slot(): void
    {
        // The escape hatch: reorderChildren() sees the raw set, so the full
        // list (including the trashed B) re-slots C ahead of A.
        Category::query()->findOrFail(1)->reorderChildren([4, 2, 3]);

        $this->assertSame(
            ['C', 'A', 'B'],
            Category::withTrashed()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
        $this->assertSame(
            ['C', 'A'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }
}
