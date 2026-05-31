<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeDiff;

/**
 * The column-equality helpers behind `Modified` detection: DateTime
 * equivalence, numeric coercion across stored/cast representations,
 * canonical-JSON for nested arrays, and the value-mismatch fallthrough.
 */
final class TreeDiffEqualityHelpersTest extends TestCase
{
    public function test_carbon_date_columns_compare_equal_when_same_instant(): void
    {
        $before = [['id' => 1, 'parent_id' => null, 'occurred_at' => Date::parse('2026-05-31 12:00:00')]];
        $after = [['id' => 1, 'parent_id' => null, 'occurred_at' => Date::parse('2026-05-31 12:00:00')]];

        $diff = TreeDiff::between($before, $after);

        $this->assertSame([], $diff->modified);
    }

    public function test_numeric_strings_compare_equal_to_their_int_counterparts(): void
    {
        $before = [['id' => 1, 'parent_id' => null, 'count' => '5']];
        $after = [['id' => 1, 'parent_id' => null, 'count' => 5]];

        $diff = TreeDiff::between($before, $after);

        $this->assertSame([], $diff->modified);
    }

    public function test_nested_json_arrays_compare_canonically_after_key_sort(): void
    {
        $before = [['id' => 1, 'parent_id' => null, 'meta' => ['nested' => ['z' => 1, 'a' => 2]]]];
        $after = [['id' => 1, 'parent_id' => null, 'meta' => ['nested' => ['a' => 2, 'z' => 1]]]];

        $diff = TreeDiff::between($before, $after);

        $this->assertSame([], $diff->modified);
    }

    public function test_actual_column_changes_still_surface_as_modified(): void
    {
        $before = [['id' => 1, 'parent_id' => null, 'meta' => ['a' => 1]]];
        $after = [['id' => 1, 'parent_id' => null, 'meta' => ['a' => 2]]];

        $diff = TreeDiff::between($before, $after);

        $this->assertCount(1, $diff->modified);
    }
}
