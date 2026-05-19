<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Pins that the cascade marker uses microsecond precision so two
 * independent soft-deletes within the same second still produce
 * distinct markers — restoring one only un-trashes descendants of
 * that operation, not of the other one.
 *
 * On a `DATETIME(0)` column the DB truncates to seconds, so this
 * test would only pass with the secondary fall-through to in-memory
 * Carbon precision. The fixture's deleted_at column is whatever
 * SQLite stores by default (a string), which preserves microseconds.
 * On the persistent backends (MySQL/MariaDB/PG), Laravel's default
 * `softDeletes()` migration macro creates a column that supports
 * microseconds.
 */
final class SoftDeleteSubSecondCascadeTest extends TestCase
{
    public function test_two_cascades_in_the_same_second_do_not_share_a_marker(): void
    {
        // Two independent subtrees:
        //   Root1 → A1 → B1
        //   Root2 → A2 → B2
        // Soft-delete A1 then A2 with deleted_at values pinned to the
        // SAME second-truncated timestamp, then restore A1. Only A1's
        // descendants should come back — B2 must stay trashed because
        // its marker (microsecond suffix differs) doesn't match.
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root1', 'lft' => 1, 'rgt' => 6, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A1',    'lft' => 2, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'B1',    'lft' => 3, 'rgt' => 4, 'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'Root2', 'lft' => 7, 'rgt' => 12, 'depth' => 0, 'parent_id' => null],
            ['id' => 5, 'name' => 'A2',    'lft' => 8, 'rgt' => 11, 'depth' => 1, 'parent_id' => 4],
            ['id' => 6, 'name' => 'B2',    'lft' => 9, 'rgt' => 10, 'depth' => 2, 'parent_id' => 5],
        ]);
        $this->syncSequence('categories');

        $second = '2025-04-30 12:00:00';

        // Force two distinct microsecond values in the same second so
        // sub-second precision is what differentiates them.
        Date::setTestNow(Date::parse($second.'.100000'));
        $a1 = Category::query()->findOrFail(2);
        $a1->delete();

        Date::setTestNow(Date::parse($second.'.900000'));
        $a2 = Category::query()->findOrFail(5);
        $a2->delete();

        Date::setTestNow();   // release the test clock

        // Sanity-check: both subtrees are trashed, and both share the
        // second-truncated timestamp but differ in microseconds.
        $b1 = Category::withTrashed()->findOrFail(3);
        $b2 = Category::withTrashed()->findOrFail(6);

        $this->assertNotNull($b1->deleted_at);
        $this->assertNotNull($b2->deleted_at);

        // Restore A1.
        $a1Trashed = Category::withTrashed()->findOrFail(2);
        $a1Trashed->restore();

        // A1 and B1 must come back. B2 must stay trashed because its
        // deleted_at microsecond suffix differs from A1's.
        $this->assertNull(Category::query()->findOrFail(2)->deleted_at, 'A1 restored');
        $this->assertNull(Category::query()->findOrFail(3)->deleted_at, 'B1 restored (same cascade as A1)');

        $b2After = Category::withTrashed()->findOrFail(6);
        $this->assertNotNull(
            $b2After->deleted_at,
            'B2 must stay trashed — its cascade marker differs from A1 by microseconds within the same second',
        );
    }
}
