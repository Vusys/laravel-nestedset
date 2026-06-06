<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeDiff;

/**
 * Surface methods on `TreeDiff` itself: `toArray()`, `jsonSerialize()`,
 * `isEmpty()` truthy/falsy, and ignored-column behaviour against
 * `Removed` (the attribute-strip path).
 */
final class TreeDiffApiSurfaceTest extends TestCase
{
    #[Test]
    public function to_array_returns_serialised_changes_and_summary(): void
    {
        $before = [['id' => 1, 'name' => 'Old', 'parent_id' => null]];
        $after = [
            ['id' => 1, 'name' => 'New', 'parent_id' => null],
            ['id' => 2, 'name' => 'C', 'parent_id' => 1],
        ];

        $array = TreeDiff::between($before, $after)->toArray();

        $this->assertSame(['added' => 1, 'removed' => 0, 'moved' => 0, 'modified' => 1], $array['summary']);

        $addedList = $array['added'];
        $this->assertIsArray($addedList);
        $this->assertCount(1, $addedList);
        $firstAdded = $addedList[0];
        $this->assertIsArray($firstAdded);
        $this->assertSame('added', $firstAdded['type']);

        $modifiedList = $array['modified'];
        $this->assertIsArray($modifiedList);
        $firstModified = $modifiedList[0];
        $this->assertIsArray($firstModified);
        $this->assertSame('modified', $firstModified['type']);
    }

    #[Test]
    public function json_encode_uses_to_array_shape(): void
    {
        $diff = TreeDiff::between(
            [['id' => 1, 'name' => 'A', 'parent_id' => null]],
            [['id' => 1, 'name' => 'B', 'parent_id' => null]],
        );

        $decoded = json_decode(json_encode($diff) ?: '', true);
        $this->assertIsArray($decoded);
        $this->assertSame(['added' => 0, 'removed' => 0, 'moved' => 0, 'modified' => 1], $decoded['summary']);
    }

    #[Test]
    public function is_empty_is_false_when_any_category_has_entries(): void
    {
        $diff = TreeDiff::between(
            [],
            [['id' => 1, 'name' => 'X', 'parent_id' => null]],
        );

        $this->assertFalse($diff->isEmpty());
    }

    #[Test]
    public function ignored_columns_apply_to_removed_attribute_capture(): void
    {
        $before = [
            ['id' => 1, 'name' => 'R', 'parent_id' => null, 'updated_at' => '2026-01-01'],
        ];
        $after = [];

        $diff = TreeDiff::between($before, $after, ignoreColumns: ['updated_at']);

        $this->assertCount(1, $diff->removed);
        $this->assertArrayNotHasKey('updated_at', $diff->removed[0]->attributes);
        $this->assertSame('R', $diff->removed[0]->attributes['name']);
    }
}
