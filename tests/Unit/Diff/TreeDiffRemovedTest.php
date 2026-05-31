<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeChange\Removed;
use Vusys\NestedSet\Diff\TreeDiff;

final class TreeDiffRemovedTest extends TestCase
{
    public function test_removed_leaf(): void
    {
        $before = [
            ['id' => 1, 'name' => 'Root', 'parent_id' => null],
            ['id' => 2, 'name' => 'Leaf', 'parent_id' => 1],
        ];
        $after = [
            ['id' => 1, 'name' => 'Root', 'parent_id' => null],
        ];

        $diff = TreeDiff::between($before, $after);

        $this->assertCount(1, $diff->removed);
        $r = $diff->removed[0];
        $this->assertInstanceOf(Removed::class, $r);
        $this->assertSame(2, $r->key);
    }

    public function test_removed_interior_flags_each_descendant_separately(): void
    {
        $before = [
            ['id' => 1, 'name' => 'Root', 'parent_id' => null],
            ['id' => 2, 'name' => 'Mid', 'parent_id' => 1],
            ['id' => 3, 'name' => 'Leaf', 'parent_id' => 2],
        ];
        $after = [
            ['id' => 1, 'name' => 'Root', 'parent_id' => null],
        ];

        $diff = TreeDiff::between($before, $after);

        $keys = array_map(static fn (Removed $r): mixed => $r->key, $diff->removed);
        $this->assertCount(2, $diff->removed);
        $this->assertEqualsCanonicalizing([2, 3], $keys);
    }
}
