<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\SoftDelete;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The soft-delete cascade stamps descendants with the **same** marker the
 * anchor row already carries (seconds precision, as fromDateTime writes
 * it). The original bug was a format mismatch — the anchor row stored
 * `…12:00:00` while descendants stored `…12:00:00.000000`, so a restore
 * could match some rows of an interleaved cascade but not others, leaving
 * a live node under a trashed ancestor (and diverging across backends).
 *
 * Seconds precision is deliberate: the default `deleted_at` column is
 * second-precision, where a sub-second write rounds on the column but the
 * in-memory cast truncates — so a finer marker disagrees across backends.
 * Independent / nested cascades in the same wall-clock second therefore
 * share a marker; the cascade is bounds-scoped, so disjoint subtrees stay
 * isolated regardless.
 */
final class SoftDeleteSubSecondCascadeTest extends TestCase
{
    #[Test]
    public function cascade_marker_is_byte_identical_on_anchor_and_descendants(): void
    {
        // The cross-backend invariant the fix restores: anchor and every
        // cascaded descendant carry the exact same stored deleted_at.
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'A', 'lft' => 1, 'rgt' => 6, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'B', 'lft' => 2, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'C', 'lft' => 3, 'rgt' => 4, 'depth' => 2, 'parent_id' => 2],
        ]);
        $this->syncSequence('categories');

        Category::query()->findOrFail(1)->delete();

        $anchor = DB::table('categories')->where('id', 1)->value('deleted_at');
        $child = DB::table('categories')->where('id', 2)->value('deleted_at');
        $grandchild = DB::table('categories')->where('id', 3)->value('deleted_at');

        $this->assertNotNull($anchor);
        $this->assertSame($anchor, $child, 'anchor and child must carry the identical marker');
        $this->assertSame($anchor, $grandchild, 'anchor and grandchild must carry the identical marker');
    }

    #[Test]
    public function disjoint_cascades_in_the_same_second_restore_independently(): void
    {
        // Two independent subtrees deleted in the same second:
        //   Root1 → A1 → B1     Root2 → A2 → B2
        // Restoring A1 must not touch Root2's subtree — the cascade is
        // bounds-scoped, so disjoint trees never interfere even when their
        // markers are identical.
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root1', 'lft' => 1, 'rgt' => 6, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A1',    'lft' => 2, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'B1',    'lft' => 3, 'rgt' => 4, 'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'Root2', 'lft' => 7, 'rgt' => 12, 'depth' => 0, 'parent_id' => null],
            ['id' => 5, 'name' => 'A2',    'lft' => 8, 'rgt' => 11, 'depth' => 1, 'parent_id' => 4],
            ['id' => 6, 'name' => 'B2',    'lft' => 9, 'rgt' => 10, 'depth' => 2, 'parent_id' => 5],
        ]);
        $this->syncSequence('categories');

        Date::setTestNow(Date::parse('2025-04-30 12:00:00'));
        Category::query()->findOrFail(2)->delete();   // A1 (+ B1)
        Category::query()->findOrFail(5)->delete();   // A2 (+ B2), same second
        Date::setTestNow();

        Category::withTrashed()->findOrFail(2)->restore();   // restore A1

        $this->assertNull(Category::query()->findOrFail(2)->deleted_at, 'A1 restored');
        $this->assertNull(Category::query()->findOrFail(3)->deleted_at, 'B1 restored');
        $this->assertNotNull(
            Category::withTrashed()->findOrFail(6)->deleted_at,
            'B2 stays trashed — outside A1\'s bounds, so the cascade never touches it',
        );
    }

    #[Test]
    public function nested_deletes_in_distinct_seconds_restore_independently(): void
    {
        // A → B → C → D. Soft-delete inner B first, then outer A one
        // second later. Restoring A must bring back ONLY A — B/C/D belong
        // to B's earlier, distinct-second cascade.
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'A', 'lft' => 1, 'rgt' => 8, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'B', 'lft' => 2, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'C', 'lft' => 3, 'rgt' => 6, 'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'D', 'lft' => 4, 'rgt' => 5, 'depth' => 3, 'parent_id' => 3],
        ]);
        $this->syncSequence('categories');

        Date::setTestNow(Date::parse('2025-04-30 12:00:00'));
        Category::query()->findOrFail(2)->delete();   // B (+ C, D)

        Date::setTestNow(Date::parse('2025-04-30 12:00:05'));
        Category::query()->findOrFail(1)->delete();   // A, five seconds later

        Date::setTestNow();

        Category::withTrashed()->findOrFail(1)->restore();   // restore A

        $this->assertNull(Category::query()->findOrFail(1)->deleted_at, 'A restored');
        $this->assertNotNull(Category::withTrashed()->findOrFail(2)->deleted_at, 'B stays trashed');
        $this->assertNotNull(Category::withTrashed()->findOrFail(3)->deleted_at, 'C stays trashed');
        $this->assertNotNull(Category::withTrashed()->findOrFail(4)->deleted_at, 'D stays trashed');

        // Restoring B then brings back its whole cascade.
        Category::withTrashed()->findOrFail(2)->restore();
        $this->assertNull(Category::query()->findOrFail(2)->deleted_at, 'B restored');
        $this->assertNull(Category::query()->findOrFail(3)->deleted_at, 'C restored with B');
        $this->assertNull(Category::query()->findOrFail(4)->deleted_at, 'D restored with B');
    }

    #[Test]
    public function same_second_nested_restore_leaves_no_orphan(): void
    {
        // The original corruption: with mismatched marker formats, restoring
        // the outer node restored *some* of an inner same-second cascade but
        // not all, stranding a trashed node under a live parent. With one
        // shared marker the same-second set is all-or-nothing — no orphan.
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'A', 'lft' => 1, 'rgt' => 6, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'B', 'lft' => 2, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'C', 'lft' => 3, 'rgt' => 4, 'depth' => 2, 'parent_id' => 2],
        ]);
        $this->syncSequence('categories');

        Date::setTestNow(Date::parse('2025-04-30 12:00:00'));
        Category::query()->findOrFail(2)->delete();   // B (+ C)
        Category::query()->findOrFail(1)->delete();   // A, same second
        Date::setTestNow();

        Category::withTrashed()->findOrFail(1)->restore();   // restore A

        // The same-second set is all-or-nothing — restoring A brings the
        // whole same-second cascade back. Pre-fix, the marker mismatch
        // restored C but left B trashed under a live A (the orphan).
        $this->assertNull(Category::query()->findOrFail(1)->deleted_at, 'A live');
        $this->assertNull(
            Category::withTrashed()->findOrFail(2)->deleted_at,
            'B live — no trashed node stranded under a live parent',
        );
        $this->assertNull(Category::withTrashed()->findOrFail(3)->deleted_at, 'C live');
    }
}
