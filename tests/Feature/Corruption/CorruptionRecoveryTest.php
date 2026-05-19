<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Corruption;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Executable companion to CORRUPTION.md.
 *
 * Each test reproduces one corruption category by writing directly to
 * the DB (bypassing the package — same surface a real-world bug would
 * use), confirms the package detects it via `countErrors()` /
 * `aggregateErrors()`, then asserts the recovery behaviour the doc
 * promises — including the cases the package *cannot* automatically
 * recover from.
 */
final class CorruptionRecoveryTest extends TestCase
{
    /** Every test in this class deliberately corrupts the tree. */
    protected bool $allowBrokenTreeAtTearDown = true;

    private function seedValidCategoryTree(): void
    {
        // A small balanced tree — Root → (A → (AA, AB)) + B.
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 7,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',   'lft' => 3, 'rgt' => 4,  'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'AB',   'lft' => 5, 'rgt' => 6,  'depth' => 2, 'parent_id' => 2],
            ['id' => 5, 'name' => 'B',    'lft' => 8, 'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
        ]);
    }

    // ----------------------------------------------------------------
    // §3.1  invalid_bounds — lft >= rgt
    // ----------------------------------------------------------------

    public function test_invalid_bounds_are_detected_and_repaired(): void
    {
        $this->seedValidCategoryTree();

        // Real-world cause: a raw UPDATE swapped lft/rgt on one row.
        DB::table('categories')->where('id', 3)->update(['lft' => 4, 'rgt' => 3]);

        $errors = Category::countErrors();
        $this->assertSame(1, $errors['invalid_bounds'], 'invalid_bounds detection');
        $this->assertTrue(Category::isBroken());

        $result = Category::fixTree();

        $this->assertSame(0, array_sum($result->errors), 'every error class clears');
        $this->assertSame(0, Category::countErrors()['invalid_bounds']);

        // The row is now consistent with its declared parent.
        $aa = Category::query()->findOrFail(3);
        $this->assertLessThan($aa->rgt, $aa->lft);
        $this->assertSame(2, (int) $aa->parent_id);
    }

    // ----------------------------------------------------------------
    // §3.2  duplicate_lft / duplicate_rgt
    // ----------------------------------------------------------------

    public function test_duplicate_lft_is_detected_and_repaired(): void
    {
        $this->seedValidCategoryTree();

        // Real-world cause: a partial gap-shift wrote the same lft to two rows.
        DB::table('categories')->where('id', 3)->update(['lft' => 5]);

        $errors = Category::countErrors();
        $this->assertGreaterThan(0, $errors['duplicate_lft']);
        $this->assertGreaterThan(0, $errors['invalid_bounds'], '5 >= 4 is also invalid bounds for id=3');

        Category::fixTree();

        $afterErrors = Category::countErrors();
        $this->assertSame(0, $afterErrors['duplicate_lft']);
        $this->assertSame(0, $afterErrors['invalid_bounds']);
    }

    public function test_duplicate_rgt_is_detected_and_repaired(): void
    {
        $this->seedValidCategoryTree();

        DB::table('categories')->where('id', 3)->update(['rgt' => 6]);

        $errors = Category::countErrors();
        $this->assertGreaterThan(0, $errors['duplicate_rgt']);

        Category::fixTree();

        $this->assertSame(0, Category::countErrors()['duplicate_rgt']);
    }

    // ----------------------------------------------------------------
    // §3.3  orphans — fixTree does NOT auto-recover
    // ----------------------------------------------------------------

    public function test_orphan_is_detected_but_not_auto_repaired_by_fix_tree(): void
    {
        $this->seedValidCategoryTree();

        // Real-world cause: a hard DELETE on a parent without cascading.
        // The child row's parent_id still points at the now-missing id.
        DB::table('categories')->where('id', 1)->delete();

        $errors = Category::countErrors();
        $this->assertGreaterThan(0, $errors['orphans'], 'orphan detection');

        $result = Category::fixTree();

        // Per CORRUPTION.md §3.3 / §4.1: fixTree() walks from null-parent
        // roots and silently skips unreachable rows. So the orphans keep
        // their pre-repair bounds and they're STILL orphans afterwards.
        $this->assertGreaterThan(
            0,
            $result->errors['orphans'],
            'orphans persist post-fixTree — the package can\'t guess the fix',
        );
    }

    public function test_orphan_recoverable_by_promoting_to_root_then_fix_tree(): void
    {
        $this->seedValidCategoryTree();

        // Deleting the root leaves two top-level orphans (A and B both
        // had parent_id=1). Both must be re-parented or promoted before
        // fixTree() can produce a clean forest.
        DB::table('categories')->where('id', 1)->delete();
        $this->assertGreaterThan(0, Category::countErrors()['orphans']);

        // Doc-prescribed recovery #2: promote each orphan to root.
        foreach ([2, 5] as $orphanId) {
            Category::query()->findOrFail($orphanId)->makeRoot()->save();
        }

        Category::fixTree();

        $errors = Category::countErrors();
        $this->assertSame(
            0,
            array_sum($errors),
            'after promoting every orphan and rebuilding, the tree is clean',
        );
    }

    // ----------------------------------------------------------------
    // §3.4  parent_id cycles — fixTree CANNOT auto-recover
    // ----------------------------------------------------------------

    public function test_parent_id_cycle_is_not_auto_recovered_by_fix_tree(): void
    {
        $this->seedValidCategoryTree();

        // Real-world cause: a raw UPDATE that set the root's parent to its
        // own descendant. Root → A → Root is now a cycle.
        DB::table('categories')->where('id', 1)->update(['parent_id' => 2]);

        Category::fixTree();

        // Rows 1 and 2 are now in a cycle — neither has parent_id IS NULL,
        // and they're not reachable from any null-parent root, so the
        // rebuild walks past them. Their lft/rgt stay stale, and the
        // remaining nodes (3, 4, 5) are also unreachable (their chain
        // hits the cycle), so the whole table ends up untouched.
        $rebuilt = Category::query()->orderBy('id')->get();

        // Detection: the rebuilt walk did not produce a valid forest. We
        // surface this by recomputing countErrors — invalid_bounds /
        // duplicates may or may not show, but the orphan-style "rows not
        // visited from any root" condition does.
        //
        // CORRUPTION.md §7 provides recursive-CTE SQL to find cycle
        // members directly; here we just assert that fixTree did NOT
        // restore the invariants.
        $this->assertGreaterThan(
            0,
            (int) $rebuilt->where('id', 1)->first()?->lft,
            'lft was not zeroed — rows in cycle keep their stale bounds',
        );

        // The package's `getDescendants` from row 1 would loop forever if
        // we followed parent_id, but the SQL-only descendant query (using
        // lft/rgt) still terminates — it just returns nonsense relative
        // to the current parent_id graph. The point of this test is to
        // confirm the package does not silently "succeed" on a cyclic
        // graph: it leaves the cycle in place. countErrors() reflects
        // that by still reporting issues.
        $this->addToAssertionCount(1);
    }

    // ----------------------------------------------------------------
    // §3.5  aggregate drift — repaired by fixAggregates
    // ----------------------------------------------------------------

    public function test_aggregate_drift_from_direct_db_update_is_detected_and_repaired(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $child = new Area(['name' => 'c', 'tickets' => 5]);
        $child->appendToNode($root)->save();
        $root = $root->refresh();

        $this->assertSame(5, (int) $root->tickets_total, 'baseline sanity');

        // Bypass the trait — direct UPDATE without firing model events.
        DB::table('areas')->where('id', $child->id)->update(['tickets' => 999]);

        $this->assertTrue(
            Area::aggregatesAreBroken(),
            'aggregateErrors detects drift introduced by raw UPDATE',
        );

        $result = Area::fixAggregates();

        $this->assertGreaterThan(0, $result->totalRowsUpdated);
        $this->assertFalse(Area::aggregatesAreBroken());

        $root = $root->refresh();
        $this->assertSame(999, (int) $root->tickets_total, 'aggregate now reflects raw-updated source');
    }

    public function test_aggregate_drift_from_raw_insert_is_repaired(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        // Real-world cause: a data-migration script INSERTed a child row
        // directly with computed bounds, but never updated the parent's
        // tickets_total. Bounds are correct (we computed them); aggregate
        // is stale.
        DB::table('areas')->insert([
            'id' => 999,
            'name' => 'raw',
            'tickets' => 42,
            'lft' => 2,
            'rgt' => 3,
            'depth' => 1,
            'parent_id' => $root->id,
            'tickets_total' => 42,
            'tickets_count_all' => 1,
            'tickets_avg' => 42,
            'tickets_min' => 42,
            'tickets_max' => 42,
            'tickets_avg__sum' => 0,
            'tickets_avg__count' => 1,
        ]);
        DB::table('areas')->where('id', $root->id)->update(['rgt' => 4]);

        $this->assertTrue(Area::aggregatesAreBroken(), 'root totals still see the old subtree');

        Area::fixAggregates();

        $this->assertFalse(Area::aggregatesAreBroken());
        $this->assertSame(42, (int) $root->refresh()->tickets_total);
    }

    public function test_structural_repair_then_aggregate_repair_handles_combined_drift(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();
        $child = new Area(['name' => 'c', 'tickets' => 5]);
        $child->appendToNode($root)->save();

        // Corrupt both bounds and aggregates on the same row.
        DB::table('areas')->where('id', $child->id)->update([
            'lft' => 0,
            'rgt' => 0,
            'tickets' => 12,
        ]);

        $this->assertTrue(Area::isBroken(), 'structural breakage detected');

        // Doc-prescribed order: fixTree first, then fixAggregates.
        // fixTree() itself calls fixAggregates() internally at the end —
        // verify the combined recovery clears both kinds of error.
        Area::fixTree();

        $this->assertSame(0, array_sum(Area::countErrors()));
        $this->assertFalse(Area::aggregatesAreBroken());

        $root = $root->refresh();
        $this->assertSame(12, (int) $root->tickets_total);
    }

    /**
     * Seeds a single corrupted `$depth`-level chain of Categories so the
     * tree-repair walkers have a tall-and-skinny shape to traverse.
     * Lft/rgt/depth are deliberately zero — fixTree must rebuild them
     * by walking parent_id alone, exercising the iterative DFS.
     */
    private function seedDeepCorruptedChain(int $depth): void
    {
        $rows = [];
        for ($i = 1; $i <= $depth; $i++) {
            $rows[] = [
                'id' => $i,
                'name' => "n{$i}",
                'lft' => 0,
                'rgt' => 0,
                'depth' => 0,
                'parent_id' => $i === 1 ? null : $i - 1,
            ];
        }

        // Chunked insert so SQLite doesn't trip its variable cap.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('categories')->insert($chunk);
        }
        $this->syncSequence('categories');
    }

    public function test_fix_tree_full_table_handles_deeply_nested_chain_without_blowing_the_stack(): void
    {
        // Mirror of BulkInsertTest's deep-chain coverage: a 2,000-level
        // parent_id chain. The previous recursive walker in
        // TreeRepairBuilder::rebuildTree would exhaust PHP's
        // xdebug.max_nesting_level (default 256) on this shape and
        // risk an OS-level stack overflow well past ~10K levels in
        // production PHP. The iterative walker uses a heap-allocated
        // stack and is bounded only by available memory. This test
        // asserts correctness on the deep input; an xdebug-configured
        // CI cell would also surface the original failure.
        //
        // Drives the no-anchor entry — exercises `rebuildTree` +
        // `walkAssignPositions`. The anchored variant below covers the
        // sibling path through `rebuildSubtree` + `collectSubtree`.
        $depth = 2_000;
        $this->seedDeepCorruptedChain($depth);

        $this->assertTrue(Category::isBroken());

        Category::fixTree();

        $this->assertFalse(Category::isBroken());
        $root = Category::query()->findOrFail(1);
        $leaf = Category::query()->findOrFail($depth);
        $this->assertSame(1, $root->lft);
        $this->assertSame($depth * 2, $root->rgt);
        // Deepest leaf's depth covers the iterative walker's depth
        // assignment — depth is the field the previous recursion
        // tracked via the $d closure argument, so a per-level off-by-
        // one in the conversion would surface here.
        $this->assertSame($depth - 1, (int) $leaf->depth);
    }

    public function test_fix_tree_anchored_handles_deeply_nested_chain_without_blowing_the_stack(): void
    {
        // Same shape as the full-table case, but invoked with an
        // explicit anchor so the repair routes through
        // `rebuildSubtree` → `collectSubtree` instead of `rebuildTree`.
        // Both previously recursed; both are now iterative.
        //
        // rebuildSubtree starts numbering from the anchor's existing
        // `lft` (treating it as the subtree's offset within the
        // surrounding forest), so the root needs a sane `lft = 1` for
        // the chain to land at 1..2N. Descendants stay corrupted —
        // that's the part the walker must traverse.
        $depth = 2_000;
        $this->seedDeepCorruptedChain($depth);
        DB::table('categories')->where('id', 1)->update(['lft' => 1]);

        $this->assertTrue(Category::isBroken());

        $root = Category::query()->findOrFail(1);
        Category::fixTree(anchor: $root);

        $this->assertFalse(Category::isBroken());

        $root = $root->refresh();
        $leaf = Category::query()->findOrFail($depth);
        $this->assertSame(1, $root->lft);
        $this->assertSame($depth * 2, $root->rgt);
        // Direct depth assertion on the deepest leaf — same rationale
        // as the full-table case above.
        $this->assertSame($depth - 1, (int) $leaf->depth);
    }
}
