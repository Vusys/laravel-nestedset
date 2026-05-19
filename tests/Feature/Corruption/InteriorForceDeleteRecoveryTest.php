<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Corruption;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * `forceDelete` on an interior node cascades through its descendants
 * (mirroring the soft-delete cascade) and closes the entire subtree
 * gap — the table stays intact instead of producing orphans + a
 * stranded range.
 *
 * Orphans are still reachable when something bypasses the trait
 * (raw DELETE, direct DB::table updates) — this file pins the
 * recovery recipe for that case.
 */
final class InteriorForceDeleteRecoveryTest extends TestCase
{
    public function test_force_delete_of_interior_node_cascades(): void
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

        $a->forceDelete();

        $this->assertNull(Category::withTrashed()->find($a->id), 'A is gone');
        $this->assertNull(Category::withTrashed()->find($a1->id), 'A1 was cascade-deleted');
        $this->assertNotNull(Category::withTrashed()->find($root->id), 'Root survives');
        $this->assertNotNull(Category::withTrashed()->find($b->id), 'B (sibling) survives');

        $this->assertSame(0, array_sum(Category::countErrors()));
        $this->assertFalse(Category::isBroken());
    }

    public function test_recovery_from_raw_orphan_via_re_parenting(): void
    {
        // Recovery recipe for orphans introduced by bypassing the
        // trait (raw DELETE, direct DB::table updates): re-parent
        // the orphan, then run fixTree() to rebuild bounds.
        $this->allowBrokenTreeAtTearDown = true;

        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root->refresh();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root)->save();
        $a->refresh();

        $a1 = new Category(['name' => 'A1']);
        $a1->appendToNode($a)->save();
        $a1->refresh();

        // Bypass the trait — raw DELETE leaves A1 as an orphan.
        DB::table('categories')->where('id', $a->id)->delete();

        DB::table('categories')->where('id', $a1->id)->update([
            'parent_id' => $root->id,
        ]);

        $result = Category::fixTree();

        $this->assertSame(
            0,
            array_sum($result->errors),
            'after re-parenting the orphan and rebuilding, the tree is clean',
        );

        $a1After = Category::query()->findOrFail($a1->id);
        $this->assertSame($root->id, $a1After->parent_id);
        $this->assertSame(1, $a1After->depth);
    }

    public function test_recovery_via_promote_orphan_to_root(): void
    {
        // Alternative recovery: promote the orphan to a sibling root.
        $this->allowBrokenTreeAtTearDown = true;

        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root->refresh();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root)->save();
        $a->refresh();

        $a1 = new Category(['name' => 'A1']);
        $a1->appendToNode($a)->save();
        $a1->refresh();

        DB::table('categories')->where('id', $a->id)->delete();

        DB::table('categories')->where('id', $a1->id)->update(['parent_id' => null]);
        Category::fixTree();

        $errors = Category::countErrors();
        $this->assertSame(0, array_sum($errors));

        $a1After = Category::query()->findOrFail($a1->id);
        $this->assertNull($a1After->parent_id, 'A1 is now a root');
        $this->assertSame(0, $a1After->depth);
    }
}
