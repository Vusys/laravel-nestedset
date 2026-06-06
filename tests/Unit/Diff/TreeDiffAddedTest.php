<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeChange\Added;
use Vusys\NestedSet\Diff\TreeDiff;

final class TreeDiffAddedTest extends TestCase
{
    #[Test]
    public function single_added_root_in_flat_form(): void
    {
        $before = [];
        $after = [
            ['id' => 1, 'name' => 'Root', 'parent_id' => null],
        ];

        $diff = TreeDiff::between($before, $after);

        $this->assertCount(1, $diff->added);
        $this->assertSame([], $diff->removed);
        $this->assertSame([], $diff->moved);
        $this->assertSame([], $diff->modified);

        $added = $diff->added[0];
        $this->assertInstanceOf(Added::class, $added);
        $this->assertSame(1, $added->key);
        $this->assertNull($added->parentKey);
        $this->assertSame(['name' => 'Root'], $added->attributes);
        $this->assertSame(0, $added->siblingPosition);
    }

    #[Test]
    public function added_descendant_uses_after_position(): void
    {
        $before = [
            ['id' => 1, 'name' => 'Root', 'parent_id' => null],
            ['id' => 2, 'name' => 'A', 'parent_id' => 1],
        ];
        $after = [
            ['id' => 1, 'name' => 'Root', 'parent_id' => null],
            ['id' => 2, 'name' => 'A', 'parent_id' => 1],
            ['id' => 3, 'name' => 'B', 'parent_id' => 1],
        ];

        $diff = TreeDiff::between($before, $after);

        $this->assertCount(1, $diff->added);
        $added = $diff->added[0];
        $this->assertSame(3, $added->key);
        $this->assertSame(1, $added->parentKey);
        $this->assertSame(1, $added->siblingPosition);
    }

    #[Test]
    public function nested_form_input_is_accepted(): void
    {
        $before = [];
        $after = [
            ['id' => 1, 'name' => 'Root', 'children' => [
                ['id' => 2, 'name' => 'A', 'children' => []],
            ]],
        ];

        $diff = TreeDiff::between($before, $after);

        $this->assertCount(2, $diff->added);
        $this->assertSame(1, $diff->added[0]->key);
        $this->assertSame(2, $diff->added[1]->key);
        $this->assertSame(1, $diff->added[1]->parentKey);
    }
}
