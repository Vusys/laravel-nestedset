<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Exceptions\DanglingParentException;

/**
 * The added-set topological sort throws when the after-snapshot
 * itself contains a parent cycle (A.parent = B, B.parent = A, both
 * absent from before). Without the guard, the recursive emitter
 * would loop until it ran out of stack.
 */
final class TreeDiffCyclicAddedTest extends TestCase
{
    public function test_cycle_in_added_rows_throws_rather_than_recursing_forever(): void
    {
        $before = [];
        $after = [
            ['id' => 1, 'name' => 'A', 'parent_id' => 2],
            ['id' => 2, 'name' => 'B', 'parent_id' => 1],
        ];

        $this->expectException(DanglingParentException::class);
        $this->expectExceptionMessageMatches('/parent cycle/');

        TreeDiff::between($before, $after);
    }
}
