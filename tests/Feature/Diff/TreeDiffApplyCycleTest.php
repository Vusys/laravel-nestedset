<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Diff;

use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Exceptions\CyclicMoveException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

final class TreeDiffApplyCycleTest extends TestCase
{
    public function test_swap_parents_in_move_set_is_rejected(): void
    {
        $a = new Category(['name' => 'A']);
        $a->makeRoot()->save();
        $b = new Category(['name' => 'B']);
        $b->makeRoot()->save();

        $before = [
            ['id' => $a->id, 'name' => 'A', 'parent_id' => null],
            ['id' => $b->id, 'name' => 'B', 'parent_id' => null],
        ];
        // The "after" puts A under B and B under A — a cycle inside the
        // move set itself, not against the existing tree.
        $after = [
            ['id' => $a->id, 'name' => 'A', 'parent_id' => $b->id],
            ['id' => $b->id, 'name' => 'B', 'parent_id' => $a->id],
        ];

        $diff = TreeDiff::between($before, $after);
        $this->assertCount(2, $diff->moved);

        $this->expectException(CyclicMoveException::class);
        $diff->apply(Category::class);
    }
}
