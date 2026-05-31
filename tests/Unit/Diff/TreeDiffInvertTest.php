<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeDiff;

final class TreeDiffInvertTest extends TestCase
{
    public function test_invert_swaps_added_and_removed(): void
    {
        $before = [['id' => 1, 'name' => 'A', 'parent_id' => null]];
        $after = [
            ['id' => 1, 'name' => 'A', 'parent_id' => null],
            ['id' => 2, 'name' => 'B', 'parent_id' => 1],
        ];

        $diff = TreeDiff::between($before, $after);
        $inverted = $diff->invert();

        $this->assertSame(['added' => 0, 'removed' => 1, 'moved' => 0, 'modified' => 0], $inverted->summary());
        $this->assertSame(2, $inverted->removed[0]->key);
    }

    public function test_invert_swaps_modified_before_after(): void
    {
        $before = [['id' => 1, 'name' => 'Old', 'parent_id' => null]];
        $after = [['id' => 1, 'name' => 'New', 'parent_id' => null]];

        $inverted = TreeDiff::between($before, $after)->invert();

        $this->assertCount(1, $inverted->modified);
        $this->assertSame(['name' => 'New'], $inverted->modified[0]->before);
        $this->assertSame(['name' => 'Old'], $inverted->modified[0]->after);
    }

    public function test_invert_swaps_moved_from_and_to_parent(): void
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

        $inverted = TreeDiff::between($before, $after)->invert();

        $this->assertCount(1, $inverted->moved);
        $this->assertSame(2, $inverted->moved[0]->fromParent);
        $this->assertSame(1, $inverted->moved[0]->toParent);
    }
}
