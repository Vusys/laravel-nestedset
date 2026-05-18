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

    public function test_hard_leaf_delete_compacts_bounds(): void
    {
        // After hard-deleting a leaf, the surrounding lft/rgt values
        // collapse to close the gap so the bounds sequence stays a
        // contiguous 1..2N permutation. Wired via NodeTrait's `deleted`
        // hook → HasTreeMutation::applyStructuralCleanupOnDelete →
        // TreeMutationBuilder::closeGap.
        // Tree (from seedTree): Root(1,10) > A(2,7) > AA(3,4), AB(5,6); Root > B(8,9).
        $aa = Category::query()->findOrFail(3);
        $aa->forceDelete();

        // AA is gone; A's range shrinks by 2; B shifts left by 2.
        $root = Category::withTrashed()->findOrFail(1);
        $a = Category::withTrashed()->findOrFail(2);
        $ab = Category::withTrashed()->findOrFail(4);
        $b = Category::withTrashed()->findOrFail(5);

        // Bounds are now: Root(1,8), A(2,5), AB(3,4), B(6,7). Contiguous 1..8.
        $this->assertSame([1, 8], [$root->lft, $root->rgt], 'root range shrinks by 2');
        $this->assertSame([2, 5], [$a->lft, $a->rgt], 'A range shrinks by 2');
        $this->assertSame([3, 4], [$ab->lft, $ab->rgt], 'AB shifts left to fill the gap');
        $this->assertSame([6, 7], [$b->lft, $b->rgt], 'B shifts left by 2');

        // Tree must remain valid (no invalid_bounds, no duplicates, no orphans).
        $this->assertFalse(Category::isBroken());
    }

    public function test_force_delete_interior_node_skips_compaction(): void
    {
        // Force-deleting a non-leaf without first reparenting leaves
        // the children orphaned *inside the vanished range*. Closing
        // the gap would push those orphans into invalid bounds, so the
        // structural cleanup intentionally skips this case — fixTree
        // can recover the orphans more reliably.
        $this->allowBrokenTreeAtTearDown = true;

        $a = Category::query()->findOrFail(2);
        $aLft = (int) $a->lft;
        $aRgt = (int) $a->rgt;
        $a->forceDelete();

        // Children's bounds are unchanged — still inside A's former range.
        $aa = Category::withTrashed()->findOrFail(3);
        $ab = Category::withTrashed()->findOrFail(4);
        $this->assertGreaterThan($aLft, (int) $aa->lft);
        $this->assertLessThan($aRgt, (int) $aa->rgt);
        $this->assertGreaterThan($aLft, (int) $ab->lft);
        $this->assertLessThan($aRgt, (int) $ab->rgt);
    }

    public function test_force_delete_removes_only_the_node(): void
    {
        // Force-deleting a non-leaf without first reparenting children
        // leaves the tree corrupt — that's the documented behaviour
        // (we don't cascade hard-deletes through the tree).
        $this->allowBrokenTreeAtTearDown = true;

        $a = Category::query()->findOrFail(2);
        $a->forceDelete();

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
