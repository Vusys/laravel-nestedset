<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeChange\Modified;
use Vusys\NestedSet\Diff\TreeDiff;

final class TreeDiffModifiedTest extends TestCase
{
    #[Test]
    public function column_change_surfaces_as_modified(): void
    {
        $before = [['id' => 1, 'name' => 'Old', 'parent_id' => null]];
        $after = [['id' => 1, 'name' => 'New', 'parent_id' => null]];

        $diff = TreeDiff::between($before, $after);

        $this->assertCount(1, $diff->modified);
        $m = $diff->modified[0];
        $this->assertInstanceOf(Modified::class, $m);
        $this->assertSame(1, $m->key);
        $this->assertSame(['name' => 'Old'], $m->before);
        $this->assertSame(['name' => 'New'], $m->after);
    }

    #[Test]
    public function ignored_columns_do_not_count(): void
    {
        $before = [['id' => 1, 'name' => 'X', 'parent_id' => null, 'updated_at' => '2026-01-01']];
        $after = [['id' => 1, 'name' => 'X', 'parent_id' => null, 'updated_at' => '2026-05-31']];

        $diff = TreeDiff::between($before, $after, ignoreColumns: ['updated_at']);

        $this->assertSame([], $diff->modified);
        $this->assertTrue($diff->isEmpty());
    }

    #[Test]
    public function structural_columns_are_never_modified(): void
    {
        $before = [['id' => 1, 'name' => 'X', 'parent_id' => null, 'lft' => 1, 'rgt' => 2, 'depth' => 0]];
        $after = [['id' => 1, 'name' => 'X', 'parent_id' => null, 'lft' => 5, 'rgt' => 6, 'depth' => 1]];

        $diff = TreeDiff::between($before, $after);

        $this->assertSame([], $diff->modified);
    }

    #[Test]
    public function json_column_equality_is_canonical(): void
    {
        $before = [['id' => 1, 'parent_id' => null, 'meta' => ['a' => 1, 'b' => 2]]];
        $after = [['id' => 1, 'parent_id' => null, 'meta' => ['b' => 2, 'a' => 1]]];

        $diff = TreeDiff::between($before, $after);

        $this->assertSame([], $diff->modified);
    }
}
