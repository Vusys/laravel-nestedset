<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Tree:
 *  Root
 *    A
 *      AA
 *      AB
 *    B
 */
final class SoftDeleteTest extends TestCase
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

    public function test_restore_cascades_to_matching_descendants(): void
    {
        $a = Category::query()->findOrFail(2);
        $a->delete();

        // Both A and its descendants are now trashed.
        $this->assertCount(0, Category::query()->whereIn('id', [2, 3, 4])->get());

        $a = Category::withTrashed()->findOrFail(2);
        $a->restore();

        // After restore, A and its descendants come back.
        $this->assertNotNull(Category::query()->find(2));
        $this->assertNotNull(Category::query()->find(3));
        $this->assertNotNull(Category::query()->find(4));
    }

    public function test_restore_does_not_bring_back_separately_trashed_descendant(): void
    {
        // Trash AA at an earlier moment.
        $aa = Category::query()->findOrFail(3);
        $aa->delete();
        Sleep::sleep(1);

        // Now trash A — its cascade only marks AB (AA already had a
        // different deleted_at and is skipped by the WHERE NULL guard).
        $a = Category::query()->findOrFail(2);
        $a->delete();

        // Restore A; AB comes back, AA stays trashed because its
        // deleted_at doesn't match A's restore marker.
        $a = Category::withTrashed()->findOrFail(2);
        $a->restore();

        $this->assertNotNull(Category::query()->find(2));   // A back
        $this->assertNotNull(Category::query()->find(4));   // AB back
        $this->assertNull(Category::query()->find(3));      // AA still trashed
    }
}
