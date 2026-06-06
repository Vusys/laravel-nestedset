<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Diff;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Exceptions\InvalidJsonTreeException;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;

/**
 * Validation errors surfaced when `between()` is fed a malformed
 * payload: identity-column missing, non-scalar identity, non-array
 * row, parent_id of the wrong type, and the mixed nested/flat shape.
 */
final class TreeDiffInputErrorsTest extends TestCase
{
    #[Test]
    public function identity_column_must_exist_on_row(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        TreeDiff::between(
            [],
            [['name' => 'no id here', 'parent_id' => null]],
        );
    }

    #[Test]
    public function identity_value_must_be_int_or_string(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        TreeDiff::between(
            [],
            [['id' => 1.5, 'name' => 'r', 'parent_id' => null]],
        );
    }

    #[Test]
    public function non_array_row_is_rejected(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        TreeDiff::between(
            [],
            ['not a row'],
        );
    }

    #[Test]
    public function mixed_nested_and_flat_shape_throws(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        TreeDiff::between(
            [],
            [
                ['id' => 1, 'children' => []],
                ['id' => 2, 'parent_id' => 1],
            ],
        );
    }

    #[Test]
    public function nested_children_must_be_an_array(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        TreeDiff::between(
            [],
            [['id' => 1, 'children' => 'not an array']],
        );
    }

    #[Test]
    public function aggregate_columns_for_a_class_returns_empty_when_none_declared(): void
    {
        $cols = TreeDiff::aggregateColumnsFor(Category::class);
        $this->assertSame([], $cols);
    }

    #[Test]
    public function aggregate_columns_for_a_class_lists_declared_aggregates(): void
    {
        $cols = TreeDiff::aggregateColumnsFor(Area::class);
        $this->assertContains('tickets_total', $cols);
        $this->assertContains('tickets_max', $cols);
    }
}
