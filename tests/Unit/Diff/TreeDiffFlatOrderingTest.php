<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeDiff;

/**
 * Flat snapshots assign each node a sibling position. When the rows carry
 * `lft`, that position must follow the tree's real order — not the order
 * the rows happen to arrive in. Two snapshots of the *same* DB state, one
 * read `orderBy('lft')` and one `orderBy('id')`, must diff to nothing.
 */
final class TreeDiffFlatOrderingTest extends TestCase
{
    #[Test]
    public function same_state_in_different_row_order_is_not_a_move(): void
    {
        // Root(1) with children A(2, lft=2) and B(3, lft=4).
        $byLft = [
            ['id' => 1, 'name' => 'Root', 'parent_id' => null, 'lft' => 1, 'rgt' => 6, 'depth' => 0],
            ['id' => 2, 'name' => 'A',    'parent_id' => 1,    'lft' => 2, 'rgt' => 3, 'depth' => 1],
            ['id' => 3, 'name' => 'B',    'parent_id' => 1,    'lft' => 4, 'rgt' => 5, 'depth' => 1],
        ];

        // Same rows, supplied id-descending (B before A). lft is unchanged.
        $byIdDesc = [
            ['id' => 3, 'name' => 'B',    'parent_id' => 1,    'lft' => 4, 'rgt' => 5, 'depth' => 1],
            ['id' => 2, 'name' => 'A',    'parent_id' => 1,    'lft' => 2, 'rgt' => 3, 'depth' => 1],
            ['id' => 1, 'name' => 'Root', 'parent_id' => null, 'lft' => 1, 'rgt' => 6, 'depth' => 0],
        ];

        $diff = TreeDiff::between($byLft, $byIdDesc);

        $this->assertSame([], $diff->moved, 'reordering the input rows is not a structural move');
        $this->assertTrue($diff->isEmpty());
    }

    #[Test]
    public function a_genuine_reorder_is_still_detected(): void
    {
        $before = [
            ['id' => 1, 'name' => 'Root', 'parent_id' => null, 'lft' => 1, 'rgt' => 6, 'depth' => 0],
            ['id' => 2, 'name' => 'A',    'parent_id' => 1,    'lft' => 2, 'rgt' => 3, 'depth' => 1],
            ['id' => 3, 'name' => 'B',    'parent_id' => 1,    'lft' => 4, 'rgt' => 5, 'depth' => 1],
        ];

        // B now sorts before A (lft swapped) — a real move.
        $after = [
            ['id' => 1, 'name' => 'Root', 'parent_id' => null, 'lft' => 1, 'rgt' => 6, 'depth' => 0],
            ['id' => 2, 'name' => 'A',    'parent_id' => 1,    'lft' => 4, 'rgt' => 5, 'depth' => 1],
            ['id' => 3, 'name' => 'B',    'parent_id' => 1,    'lft' => 2, 'rgt' => 3, 'depth' => 1],
        ];

        $diff = TreeDiff::between($before, $after);

        $this->assertNotSame([], $diff->moved, 'a real lft swap is a move');
    }
}
