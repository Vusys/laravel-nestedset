<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeDiff;

final class TreeDiffEmptyTest extends TestCase
{
    public function test_identical_snapshots_produce_empty_diff(): void
    {
        $snapshot = [
            ['id' => 1, 'name' => 'Root', 'parent_id' => null],
            ['id' => 2, 'name' => 'Child', 'parent_id' => 1],
        ];

        $diff = TreeDiff::between($snapshot, $snapshot);

        $this->assertTrue($diff->isEmpty());
        $this->assertSame(['added' => 0, 'removed' => 0, 'moved' => 0, 'modified' => 0], $diff->summary());
    }

    public function test_empty_input_on_both_sides_is_empty(): void
    {
        $diff = TreeDiff::between([], []);
        $this->assertTrue($diff->isEmpty());
    }
}
