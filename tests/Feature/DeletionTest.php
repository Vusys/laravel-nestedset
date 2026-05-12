<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Initial tree:
 *  Root
 *    A
 *      AA
 *      AB
 *    B
 */
final class DeletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 7,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',   'lft' => 3, 'rgt' => 4,  'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'AB',   'lft' => 5, 'rgt' => 6,  'depth' => 2, 'parent_id' => 2],
            ['id' => 5, 'name' => 'B',    'lft' => 8, 'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
        ]);
    }

    public function test_force_delete_removes_only_the_node(): void
    {
        $a = Category::query()->findOrFail(2);
        $a->forceDelete();

        // A's descendants (AA, AB) still exist as rows in the table — the
        // tree may now be inconsistent but no rows were destroyed beyond A.
        $this->assertNull(Category::withTrashed()->find(2));
        $this->assertNotNull(Category::withTrashed()->find(3));
        $this->assertNotNull(Category::withTrashed()->find(4));
    }

    public function test_soft_delete_marks_descendants_deleted_at_same_timestamp(): void
    {
        $a = Category::query()->findOrFail(2);
        $a->delete();

        $aa = Category::withTrashed()->findOrFail(3);
        $ab = Category::withTrashed()->findOrFail(4);
        $b = Category::withTrashed()->findOrFail(5);

        $this->assertNotNull($aa->deleted_at);
        $this->assertNotNull($ab->deleted_at);
        $this->assertNull($b->deleted_at, 'Unrelated subtree should stay alive');

        $this->assertSame((string) $a->deleted_at, (string) $aa->deleted_at);
        $this->assertSame((string) $a->deleted_at, (string) $ab->deleted_at);
    }

    public function test_soft_delete_does_not_affect_already_trashed_rows(): void
    {
        // Trash AA first with an earlier timestamp.
        $aa = Category::query()->findOrFail(3);
        $aa->delete();
        $earlierDeletedAt = $aa->refresh()->deleted_at;

        // Some time passes, then delete A.
        Sleep::sleep(1);
        $a = Category::query()->findOrFail(2);
        $a->delete();

        $aa = Category::withTrashed()->findOrFail(3);

        // AA's deleted_at should NOT have been overwritten by A's cascade.
        $this->assertSame(
            (string) $earlierDeletedAt,
            (string) $aa->deleted_at,
        );
    }
}
