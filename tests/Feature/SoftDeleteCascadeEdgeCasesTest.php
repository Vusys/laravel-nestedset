<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Monster;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Targeted edge cases for soft-delete cascade and its interaction with
 * aggregate maintenance. The basic scenarios (single-level cascade,
 * mixed timestamps with two depths) are covered in
 * {@see DeletionTest}; this file goes after the cases the existing
 * coverage doesn't reach:
 *
 *   1. Three levels of nested timestamps — `restore` should only
 *      bring back rows trashed *in the same operation*, not earlier
 *      ones.
 *   2. `forceDelete` on a soft-deleted row — aggregate hooks must not
 *      double-decrement the row's contribution.
 *   3. Cascade soft-delete inside `withDeferredAggregateMaintenance` —
 *      no per-row aggregate work runs inside the closure, but the
 *      final `fixAggregates` must settle the ancestor chain.
 *   4. Cascade respects scope (Category is unscoped, but Monster sits
 *      next to ScopeIsolationFuzzerTest's scope coverage so this is
 *      mostly belt-and-braces).
 */
final class SoftDeleteCascadeEdgeCasesTest extends TestCase
{
    /**
     * Seeds a deep chain Root > A > AA > AAA so each level can be
     * trashed with a distinct timestamp.
     */
    private function seedThreeLevelChain(): void
    {
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 8, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',   'lft' => 3, 'rgt' => 6, 'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'AAA',  'lft' => 4, 'rgt' => 5, 'depth' => 3, 'parent_id' => 3],
        ]);

        $this->syncSequence('categories');
    }

    // ================================================================
    // Three-level nested timestamp scenario
    // ================================================================

    public function test_three_levels_of_nested_timestamps_preserve_individual_marks(): void
    {
        $this->seedThreeLevelChain();

        // Trash AAA, AA, A each with a distinct timestamp.
        $aaa = Category::query()->findOrFail(4);
        $aaa->delete();
        $aaaTs = $aaa->refresh()->deleted_at;

        Sleep::sleep(1);
        $aa = Category::query()->findOrFail(3);
        $aa->delete();
        $aaTs = $aa->refresh()->deleted_at;

        Sleep::sleep(1);
        $a = Category::query()->findOrFail(2);
        $a->delete();
        $aTs = $a->refresh()->deleted_at;

        // Every level kept its original timestamp — the cascade
        // skips rows whose deleted_at is already set.
        $this->assertSame((string) $aaaTs, (string) Category::withTrashed()->findOrFail(4)->deleted_at);
        $this->assertSame((string) $aaTs, (string) Category::withTrashed()->findOrFail(3)->deleted_at);
        $this->assertSame((string) $aTs, (string) Category::withTrashed()->findOrFail(2)->deleted_at);

        // Three distinct timestamps, ordered.
        $this->assertNotSame((string) $aaaTs, (string) $aaTs);
        $this->assertNotSame((string) $aaTs, (string) $aTs);
    }

    public function test_restore_on_outer_only_restores_rows_with_matching_timestamp(): void
    {
        $this->seedThreeLevelChain();

        $aaa = Category::query()->findOrFail(4);
        $aaa->delete();

        Sleep::sleep(1);
        $aa = Category::query()->findOrFail(3);
        $aa->delete();

        Sleep::sleep(1);
        $a = Category::query()->findOrFail(2);
        $a->delete();
        $aTs = $a->refresh()->deleted_at;

        // Restore A. Per the cascade contract, only descendants with
        // deleted_at = A's timestamp come back — AA and AAA have their
        // own (earlier) timestamps and must stay trashed.
        $a->restore();

        $this->assertNull(Category::query()->findOrFail(2)->deleted_at, 'A is restored');

        $aaAfter = Category::withTrashed()->findOrFail(3);
        $aaaAfter = Category::withTrashed()->findOrFail(4);
        $this->assertNotNull($aaAfter->deleted_at, 'AA must remain trashed (different timestamp)');
        $this->assertNotNull($aaaAfter->deleted_at, 'AAA must remain trashed (different timestamp)');
        $this->assertNotSame((string) $aTs, (string) $aaAfter->deleted_at);
    }

    public function test_inner_restore_unwinds_only_its_own_level(): void
    {
        // Mirrors the README claim: "only descendants trashed in the
        // same operation are restored". Trash three levels in three
        // operations, then restore from the innermost back outward.
        $this->seedThreeLevelChain();

        $aaa = Category::query()->findOrFail(4);
        $aaa->delete();
        $aaaTs = $aaa->refresh()->deleted_at;

        Sleep::sleep(1);
        $aa = Category::query()->findOrFail(3);
        $aa->delete();

        Sleep::sleep(1);
        $a = Category::query()->findOrFail(2);
        $a->delete();

        // Restore innermost first: AAA. It has no descendants.
        $aaa = Category::withTrashed()->findOrFail(4);
        $aaa->restore();
        $this->assertNull(Category::query()->findOrFail(4)->deleted_at);

        // Restoring AAA shouldn't unmark AA (different timestamp) — it
        // already wasn't touched by this restore, but verify.
        $this->assertNotNull(Category::withTrashed()->findOrFail(3)->deleted_at);

        // Re-trash AAA to set the stage for restoring AA next.
        $aaa = Category::query()->findOrFail(4);
        $aaa->delete();

        // Now restore AA. Marker = AA's timestamp. AAA has its NEW
        // timestamp (set in the line above), which is different. So
        // AAA must NOT come back when AA is restored.
        $aa = Category::withTrashed()->findOrFail(3);
        $aa->restore();

        $this->assertNull(Category::query()->findOrFail(3)->deleted_at, 'AA restored');
        $this->assertNotNull(
            Category::withTrashed()->findOrFail(4)->deleted_at,
            'AAA stays trashed — its timestamp does not match AA\'s',
        );
        // AAA's new timestamp was overwritten only if cascade had a
        // bug — confirm it's later than AAA's original timestamp.
        $aaaNewTs = Category::withTrashed()->findOrFail(4)->deleted_at;
        $this->assertNotSame((string) $aaaTs, (string) $aaaNewTs);
    }

    // ================================================================
    // forceDelete on already-soft-deleted rows
    // ================================================================

    public function test_force_delete_of_soft_deleted_row_does_not_double_decrement_aggregates(): void
    {
        // Tree: Root > A > B (leaf). Soft-delete B, then forceDelete B.
        // B's contribution to the root's stored aggregates should only
        // be removed once — once on the soft delete (the cascade /
        // delete hook decrements the ancestor chain). The second
        // delete event must NOT decrement again.
        $root = new Monster(['name' => 'Root', 'type' => 'fire', 'base_power' => 10, 'level' => 2]);
        $root->saveAsRoot();
        $root->refresh();

        $a = new Monster(['name' => 'A', 'type' => 'fire', 'base_power' => 5, 'level' => 3]);
        $a->appendToNode($root)->save();
        $a->refresh();

        $b = new Monster(['name' => 'B', 'type' => 'water', 'base_power' => 4, 'level' => 4]);
        $b->appendToNode($a)->save();
        $b->refresh();

        // Baseline: Root.weighted_power = sum(10*2 + 5*3 + 4*4) = 20 + 15 + 16 = 51.
        $this->assertSame(51, (int) $root->refresh()->weighted_power);

        // Soft-delete B — contribution -16 should be removed from Root and A.
        $b->delete();
        $root->refresh();
        $a->refresh();
        $this->assertSame(35, (int) $root->weighted_power, 'after soft delete: Root = 20 + 15 = 35');
        $this->assertSame(15, (int) $a->weighted_power, 'after soft delete: A = 15');

        // Force-delete the already-soft-deleted B. Stored aggregates
        // must NOT decrement a second time.
        $bTrashed = Monster::withTrashed()->where('id', $b->getKey())->firstOrFail();
        $bTrashed->forceDelete();

        $root->refresh();
        $a->refresh();
        $this->assertSame(35, (int) $root->weighted_power, 'Root stayed at 35 after force-delete of already-trashed B');
        $this->assertSame(15, (int) $a->weighted_power, 'A stayed at 15');

        // Sanity check: fresh recompute agrees with stored values.
        $this->assertSame(
            $this->asInt($root->freshAggregate('weighted_power')),
            (int) $root->weighted_power,
            'stored weighted_power must equal fresh recompute on Root',
        );
        $this->assertSame(
            $this->asInt($a->freshAggregate('weighted_power')),
            (int) $a->weighted_power,
            'stored weighted_power must equal fresh recompute on A',
        );
    }

    // ================================================================
    // Cascade soft-delete inside deferred maintenance
    // ================================================================

    public function test_cascade_soft_delete_inside_deferred_maintenance_settles_aggregates(): void
    {
        // Build: Root > A > [A1, A2]; Root > B.
        // Then inside withDeferredAggregateMaintenance, soft-delete A
        // (which cascades to A1 and A2). At end-of-block, fixAggregates
        // must reconcile Root's stored aggregates.
        $root = new Monster(['name' => 'Root', 'type' => 'fire', 'base_power' => 10, 'level' => 2]);
        $root->saveAsRoot();
        $root->refresh();

        $a = new Monster(['name' => 'A', 'type' => 'fire', 'base_power' => 5, 'level' => 5]);
        $a->appendToNode($root)->save();
        $a->refresh();

        $a1 = new Monster(['name' => 'A1', 'type' => 'fire', 'base_power' => 2, 'level' => 3]);
        $a1->appendToNode($a)->save();

        $a2 = new Monster(['name' => 'A2', 'type' => 'fire', 'base_power' => 1, 'level' => 7]);
        $a2->appendToNode($a->refresh())->save();

        $b = new Monster(['name' => 'B', 'type' => 'fire', 'base_power' => 3, 'level' => 4]);
        $b->appendToNode($root->refresh())->save();

        // Baseline: Root sums 20 + 25 + 6 + 7 + 12 = 70.
        $this->assertSame(70, (int) $root->refresh()->weighted_power);

        Monster::withDeferredAggregateMaintenance(static function () use (&$a): void {
            $a = Monster::query()->where('name', 'A')->firstOrFail();
            $a->delete(); // cascade marks A, A1, A2
        }, anchor: $root);

        $root->refresh();
        // After delete: Root's live subtree = Root (20) + B (12) = 32.
        $this->assertSame(32, (int) $root->weighted_power, 'cascade soft-delete inside deferred wrapper');

        // Stored == fresh after the wrapper exits.
        $this->assertSame(
            $this->asInt($root->freshAggregate('weighted_power')),
            (int) $root->weighted_power,
        );
    }

    public function test_soft_then_force_delete_inside_same_deferred_window_does_not_double_decrement(): void
    {
        // Root > A > B. Inside one deferral, soft-delete B then load it
        // via withTrashed and forceDelete it. The $alreadyTrashed guard
        // in NodeTrait::bootNodeTrait must still fire — and the
        // post-deferral fixAggregates must reflect a single removal of
        // B's contribution, not double-decrement.
        $root = new Monster(['name' => 'Root', 'type' => 'fire', 'base_power' => 10, 'level' => 2]);
        $root->saveAsRoot();
        $root->refresh();

        $a = new Monster(['name' => 'A', 'type' => 'fire', 'base_power' => 5, 'level' => 3]);
        $a->appendToNode($root)->save();
        $a->refresh();

        $b = new Monster(['name' => 'B', 'type' => 'water', 'base_power' => 4, 'level' => 4]);
        $b->appendToNode($a)->save();
        $b->refresh();

        // Baseline: Root inclusive = 10*2 + 5*3 + 4*4 = 20 + 15 + 16 = 51.
        $this->assertSame(51, (int) $root->refresh()->weighted_power);

        Monster::withDeferredAggregateMaintenance(static function () use ($b): void {
            $b->delete();

            $trashed = Monster::withTrashed()->where('id', $b->getKey())->firstOrFail();
            $trashed->forceDelete();
        }, anchor: $root);

        // Single net removal of B (-16): 51 - 16 = 35 on Root, 15 on A.
        $this->assertSame(35, (int) $root->refresh()->weighted_power, 'Root reflects single removal of B (no double-decrement)');
        $this->assertSame(15, (int) $a->refresh()->weighted_power, 'A reflects single removal of B');

        // B must be gone from the table entirely (force deleted, not just trashed).
        $this->assertSame(0, Monster::withTrashed()->where('id', $b->getKey())->count(),
            'forceDelete inside the deferral persisted',
        );

        // Stored == fresh after the wrapper exits.
        $this->assertSame(
            $this->asInt($root->freshAggregate('weighted_power')),
            (int) $root->weighted_power,
        );
        $this->assertSame(
            $this->asInt($a->freshAggregate('weighted_power')),
            (int) $a->weighted_power,
        );
    }

    // ================================================================
    // Restore of soft-deleted subtree puts contribution back
    // ================================================================

    public function test_restore_of_cascade_soft_deleted_subtree_restores_aggregates(): void
    {
        $root = new Monster(['name' => 'Root', 'type' => 'fire', 'base_power' => 10, 'level' => 2]);
        $root->saveAsRoot();
        $root->refresh();

        $a = new Monster(['name' => 'A', 'type' => 'fire', 'base_power' => 5, 'level' => 5]);
        $a->appendToNode($root)->save();
        $a->refresh();

        $a1 = new Monster(['name' => 'A1', 'type' => 'fire', 'base_power' => 2, 'level' => 3]);
        $a1->appendToNode($a)->save();

        $a->refresh()->delete();

        $root->refresh();
        $this->assertSame(20, (int) $root->weighted_power, 'after delete: only Root remains in the subtree');

        $a = Monster::withTrashed()->where('name', 'A')->firstOrFail();
        $a->restore();

        $root->refresh();
        // Restore should bring back A and A1's contributions: 20 + 25 + 6 = 51.
        $this->assertSame(51, (int) $root->weighted_power);

        $this->assertSame(
            $this->asInt($root->freshAggregate('weighted_power')),
            (int) $root->weighted_power,
            'stored == fresh after restore',
        );
    }

    // ================================================================
    // Small helpers
    // ================================================================

    private function asInt(mixed $value): int
    {
        if (! is_numeric($value)) {
            $this->fail('expected numeric, got '.var_export($value, true));
        }

        return (int) $value;
    }
}
