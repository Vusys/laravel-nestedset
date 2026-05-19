<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Corruption;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * `forceDelete` on an interior node leaves its children in their
 * pre-delete bounds with their `parent_id` pointing at the deleted
 * row — an orphan + invalid-gap combo the audit flagged as
 * incompletely tested.
 *
 * `applyStructuralCleanupOnDelete` deliberately skips interior
 * deletes (it can't sensibly close a gap whose children would shift
 * into impossible positions). The audit pins what corruption results
 * and what recovery requires.
 *
 * Audit reference: build/CORRECTNESS_AUDIT.md → F6 + T15.
 */
final class InteriorForceDeleteRecoveryTest extends TestCase
{
    /** Every test in this class deliberately corrupts the tree. */
    protected bool $allowBrokenTreeAtTearDown = true;

    public function test_force_delete_of_interior_node_leaves_orphans(): void
    {
        // Tree:
        //   Root
        //   ├── A (interior — has child A1)
        //   │   └── A1
        //   └── B
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root->refresh();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root)->save();
        $a->refresh();

        $a1 = new Category(['name' => 'A1']);
        $a1->appendToNode($a)->save();
        $a1->refresh();

        $b = new Category(['name' => 'B']);
        $b->appendToNode($root->refresh())->save();
        $b->refresh();

        // Hard-delete A (interior). A1 still references A.id in
        // parent_id, but A is gone from the table. The gap A
        // occupied does NOT close — the package skips that step for
        // interior deletes since A1's bounds inside that range
        // would otherwise be invalidated.
        $a->forceDelete();

        $errors = Category::countErrors();
        $this->assertGreaterThan(0, $errors['orphans'], 'A1 is now an orphan (its parent_id no longer resolves)');

        // The lft/rgt sequence has a hole where A used to be — Root's
        // rgt sits past A1's now-stranded bounds. invalid_bounds is
        // not necessarily flagged (each surviving row still satisfies
        // lft < rgt), but the table is broken.
        $this->assertTrue(Category::isBroken());
    }

    public function test_recovery_requires_re_parenting_orphans_then_fix_tree(): void
    {
        // Same shape as above. Recovery recipe documented in
        // CORRUPTION.md §3.3 is: re-parent each orphan (or promote
        // to root), then `fixTree()` rebuilds bounds from the
        // reconnected parent_id graph.
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root->refresh();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root)->save();
        $a->refresh();

        $a1 = new Category(['name' => 'A1']);
        $a1->appendToNode($a)->save();
        $a1->refresh();

        $a->forceDelete();

        // Recovery: re-parent A1 to Root.
        DB::table('categories')->where('id', $a1->id)->update([
            'parent_id' => $root->id,
        ]);

        $result = Category::fixTree();

        $this->assertSame(
            0,
            array_sum($result->errors),
            'after re-parenting the orphan and rebuilding, the tree is clean',
        );

        // A1's bounds were rebuilt from its (now-valid) parent_id chain.
        $a1After = Category::query()->findOrFail($a1->id);
        $this->assertSame($root->id, $a1After->parent_id);
        $this->assertSame(1, $a1After->depth);
    }

    public function test_recovery_via_promote_orphan_to_root(): void
    {
        // Alternative recovery: promote the orphan to a sibling root
        // via makeRoot. Useful when the original parent is gone and
        // there's no other natural place for the orphan to live.
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root->refresh();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root)->save();
        $a->refresh();

        $a1 = new Category(['name' => 'A1']);
        $a1->appendToNode($a)->save();
        $a1->refresh();

        $a->forceDelete();

        // Promote A1 to root by clearing its dangling parent_id
        // first (so makeRoot sees a fresh state), then makeRoot.
        // makeRoot reads parent_id internally and would refuse to
        // touch a row whose parent doesn't exist if we tried the
        // ORM path — but the bounds are still stale, so a manual
        // parent_id clear is the documented step.
        DB::table('categories')->where('id', $a1->id)->update(['parent_id' => null]);
        Category::fixTree();

        $errors = Category::countErrors();
        $this->assertSame(0, array_sum($errors));

        $a1After = Category::query()->findOrFail($a1->id);
        $this->assertNull($a1After->parent_id, 'A1 is now a root');
        $this->assertSame(0, $a1After->depth);
    }
}
