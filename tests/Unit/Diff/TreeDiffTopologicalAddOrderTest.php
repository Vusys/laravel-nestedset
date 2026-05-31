<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeChange\Added;
use Vusys\NestedSet\Diff\TreeDiff;

final class TreeDiffTopologicalAddOrderTest extends TestCase
{
    public function test_parents_appear_before_children_in_added_list(): void
    {
        $before = [];
        $after = [
            ['id' => 3, 'name' => 'grandchild', 'parent_id' => 2],
            ['id' => 1, 'name' => 'root', 'parent_id' => null],
            ['id' => 2, 'name' => 'child', 'parent_id' => 1],
        ];

        $diff = TreeDiff::between($before, $after);

        $keys = array_map(static fn (Added $a): mixed => $a->key, $diff->added);
        $this->assertSame([1, 2, 3], $keys);
    }
}
