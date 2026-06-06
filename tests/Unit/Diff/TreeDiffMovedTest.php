<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeChange\Moved;
use Vusys\NestedSet\Diff\TreeDiff;

final class TreeDiffMovedTest extends TestCase
{
    #[Test]
    public function reparent_records_from_and_to(): void
    {
        $before = [
            ['id' => 1, 'name' => 'A', 'parent_id' => null],
            ['id' => 2, 'name' => 'B', 'parent_id' => null],
            ['id' => 3, 'name' => 'X', 'parent_id' => 1],
        ];
        $after = [
            ['id' => 1, 'name' => 'A', 'parent_id' => null],
            ['id' => 2, 'name' => 'B', 'parent_id' => null],
            ['id' => 3, 'name' => 'X', 'parent_id' => 2],
        ];

        $diff = TreeDiff::between($before, $after);

        $this->assertCount(1, $diff->moved);
        $m = $diff->moved[0];
        $this->assertInstanceOf(Moved::class, $m);
        $this->assertSame(3, $m->key);
        $this->assertSame(1, $m->fromParent);
        $this->assertSame(2, $m->toParent);
    }

    #[Test]
    public function sibling_reorder_is_a_moved_with_same_parent(): void
    {
        $before = [
            ['id' => 1, 'name' => 'Root', 'parent_id' => null],
            ['id' => 2, 'name' => 'A', 'parent_id' => 1],
            ['id' => 3, 'name' => 'B', 'parent_id' => 1],
        ];
        $after = [
            ['id' => 1, 'name' => 'Root', 'parent_id' => null],
            ['id' => 3, 'name' => 'B', 'parent_id' => 1],
            ['id' => 2, 'name' => 'A', 'parent_id' => 1],
        ];

        $diff = TreeDiff::between($before, $after);

        $this->assertCount(2, $diff->moved);
        foreach ($diff->moved as $m) {
            $this->assertSame($m->fromParent, $m->toParent);
        }
    }
}
