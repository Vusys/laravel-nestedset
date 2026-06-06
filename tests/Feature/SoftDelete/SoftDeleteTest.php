<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\SoftDelete;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Foundational soft-delete contracts on an unscoped model with no
 * aggregates declared. The cascade-with-aggregates path is covered by
 * {@see SoftDeleteCascadeEdgeCasesTest} and {@see SoftBranchFuzzerTest};
 * the sub-second timestamp cascade by {@see SoftDeleteSubSecondCascadeTest}.
 * This file pins the plain structural / lifecycle behaviour those
 * harder cases inherit from — plus the negative cases (no-ops, leaves,
 * already-trashed nodes) that the heavier tests don't bother asserting.
 *
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

    #[Test]
    public function subtree_soft_delete_cascades_to_every_descendant(): void
    {
        $a = Category::query()->findOrFail(2);
        $a->delete();

        // Soft-deleting A trashes AA and AB too — every descendant
        // inside A's bounds picks up A's deleted_at marker in one
        // cascade UPDATE.
        $this->assertCount(0, Category::query()->whereIn('id', [2, 3, 4])->get());

        // Untouched: sibling B and the root.
        $this->assertNotNull(Category::query()->find(1));
        $this->assertNotNull(Category::query()->find(5));
    }

    #[Test]
    public function subtree_restore_brings_back_every_cascade_trashed_descendant(): void
    {
        Category::query()->findOrFail(2)->delete();
        Category::withTrashed()->findOrFail(2)->restore();

        // After restore, A and its descendants come back.
        $this->assertNotNull(Category::query()->find(2));
        $this->assertNotNull(Category::query()->find(3));
        $this->assertNotNull(Category::query()->find(4));
    }

    #[Test]
    public function restore_does_not_bring_back_a_descendant_trashed_at_an_earlier_timestamp(): void
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

    #[Test]
    public function soft_deleting_a_leaf_does_not_touch_unrelated_rows(): void
    {
        // Leaf delete — the cascade UPDATE has no descendants to mark,
        // so siblings/ancestors/cousins must keep their `deleted_at`
        // NULL. Pins that the cascade scope is the subtree of $this
        // (inclusive), not some looser predicate.
        $b = Category::query()->findOrFail(5);
        $b->delete();

        $this->assertNull(Category::query()->find(5), 'leaf B is trashed');
        $this->assertNotNull(Category::query()->find(1), 'root untouched');
        $this->assertNotNull(Category::query()->find(2), 'sibling A untouched');
        $this->assertNotNull(Category::query()->find(3), 'cousin AA untouched');
        $this->assertNotNull(Category::query()->find(4), 'cousin AB untouched');
    }

    #[Test]
    public function restoring_a_row_that_was_never_trashed_is_a_no_op_for_the_subtree(): void
    {
        // Eloquent's restore() on a row with `deleted_at = NULL` is a
        // documented no-op for that row; the package's cascade
        // shouldn't widen this into accidental writes to descendants.
        $a = Category::query()->findOrFail(2);
        $a->restore();

        // Every row's deleted_at is still NULL.
        $deletedAtCounts = DB::table('categories')
            ->whereNotNull('deleted_at')
            ->count();
        $this->assertSame(0, $deletedAtCounts, 'restore on a non-trashed node must not mark any row');
    }

    #[Test]
    public function double_delete_re_stamps_parent_but_does_not_re_stamp_already_trashed_descendants(): void
    {
        // Eloquent's `delete()` on an already-soft-deleted model is
        // documented to re-stamp `deleted_at`. The package's cascade
        // adds `WHERE deleted_at IS NULL` so already-trashed
        // descendants are skipped — they keep their original marker.
        //
        // Consequence: a restore on the parent's NEW marker will not
        // bring the descendants back (their marker doesn't match) —
        // restore them via the original parent marker (use the
        // SoftDeleteSubSecondCascadeTest pattern if you need to mix
        // sub-second cascade timing with re-delete).
        $a = Category::query()->findOrFail(2);
        $a->delete();

        $stampAfterFirst = DB::table('categories')
            ->whereIn('id', [2, 3, 4])
            ->pluck('deleted_at', 'id')
            ->all();

        Sleep::sleep(1);
        $aTrashed = Category::withTrashed()->findOrFail(2);
        $aTrashed->delete();

        $stampAfterSecond = DB::table('categories')
            ->whereIn('id', [2, 3, 4])
            ->pluck('deleted_at', 'id')
            ->all();

        // Parent (id=2) re-stamped to the new time.
        $this->assertNotSame(
            $stampAfterFirst[2],
            $stampAfterSecond[2],
            'parent deleted_at must update on the second delete',
        );

        // Descendants (id=3, id=4) keep their original marker — the
        // cascade WHERE NULL guard skipped them.
        $this->assertSame(
            $stampAfterFirst[3],
            $stampAfterSecond[3],
            'AA deleted_at must NOT be re-stamped — cascade WHERE NULL guard',
        );
        $this->assertSame(
            $stampAfterFirst[4],
            $stampAfterSecond[4],
            'AB deleted_at must NOT be re-stamped — cascade WHERE NULL guard',
        );
    }
}
