<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\SoftDelete;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Decision #11 — soft-deleted nodes keep their lft/rgt slot, so any
 * structural permutation must reason over the *raw* sibling set (live +
 * trashed). `reorderChildren()` already validates against the raw child
 * rows ({@see Category::loadDirectChildBounds()} bypasses the soft-delete
 * scope); `reorderChildrenBy()` must build its id list from the same raw
 * set or it omits the trashed slots and `reorderChildren()` rejects the
 * list as "missing children".
 *
 * Tree:
 *   Root
 *     Cherry
 *     Apple   (soft-deleted)
 *     Banana
 */
final class ReorderWithTrashedChildrenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root',   'lft' => 1, 'rgt' => 8, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'Cherry', 'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'Apple',  'lft' => 4, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 4, 'name' => 'Banana', 'lft' => 6, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
        ]);
        $this->syncSequence('categories');

        Category::query()->findOrFail(3)->delete(); // soft-delete Apple
    }

    #[Test]
    public function reorder_children_by_includes_trashed_slots(): void
    {
        Category::query()->findOrFail(1)->reorderChildrenBy('name');

        // The trashed slot (Apple) is ordered alongside the live ones —
        // the raw lft sequence reflects the full alphabetical order.
        $this->assertSame(
            ['Apple', 'Banana', 'Cherry'],
            Category::withTrashed()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );

        // The visible (live) order is the same minus the trashed row.
        $this->assertSame(
            ['Banana', 'Cherry'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }
}
