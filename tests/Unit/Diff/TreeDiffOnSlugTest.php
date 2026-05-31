<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeDiff;

final class TreeDiffOnSlugTest extends TestCase
{
    public function test_diff_keyed_by_slug_uses_slug_as_identity(): void
    {
        $before = [
            ['id' => 1, 'slug' => 'root', 'name' => 'Root', 'parent_id' => null],
            ['id' => 2, 'slug' => 'a', 'name' => 'A', 'parent_id' => 1],
        ];
        $after = [
            ['id' => 10, 'slug' => 'root', 'name' => 'Root', 'parent_id' => null],
            ['id' => 11, 'slug' => 'a', 'name' => 'A', 'parent_id' => 10],
        ];

        $diff = TreeDiff::between($before, $after, on: 'slug');

        $this->assertTrue($diff->isEmpty(), 'differing IDs should not be a diff when keyed by slug');
    }

    public function test_renamed_slug_surfaces_as_removed_plus_added(): void
    {
        $before = [
            ['id' => 1, 'slug' => 'old', 'parent_id' => null],
        ];
        $after = [
            ['id' => 1, 'slug' => 'new', 'parent_id' => null],
        ];

        $diff = TreeDiff::between($before, $after, on: 'slug');

        $this->assertCount(1, $diff->added);
        $this->assertSame('new', $diff->added[0]->key);
        $this->assertCount(1, $diff->removed);
        $this->assertSame('old', $diff->removed[0]->key);
    }
}
