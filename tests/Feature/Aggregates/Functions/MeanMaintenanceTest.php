<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Functions;

use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Exceptions\AggregateSourceConstraintViolationException;
use Vusys\NestedSet\Tests\Fixtures\Models\MeanArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * M4 maintenance tests: geometric mean and harmonic mean stay correct as
 * rows are inserted, updated, moved, and deleted.
 *
 * GeometricMean display rides on `__sum_log` (= Σ LN(x) for x > 0) and
 * `__count` (count of positive contributors). HarmonicMean display rides
 * on `__sum_recip` (= Σ 1/x for x ≠ 0) and `__count` (count of
 * non-null contributors).
 */
final class MeanMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    private function asFloat(mixed $value): float
    {
        if ($value === null || ! is_numeric($value)) {
            $this->fail('Expected numeric, got '.get_debug_type($value));
        }

        return (float) $value;
    }

    // ── Geometric mean ────────────────────────────────────────────────

    public function test_geometric_mean_of_single_positive_root_equals_itself(): void
    {
        $root = new MeanArea(['name' => 'Root', 'value' => 4.0]);
        $root->saveAsRoot();
        $root->refresh();

        $this->assertEqualsWithDelta(4.0, $this->asFloat($root->value_gmean), 0.0001);
    }

    public function test_geometric_mean_of_three_values(): void
    {
        // Geometric mean of 2, 8, 32: (2 · 8 · 32)^(1/3) = 512^(1/3) = 8.
        $root = new MeanArea(['name' => 'Root', 'value' => 2.0]);
        $root->saveAsRoot();

        $a = new MeanArea(['name' => 'A', 'value' => 8.0]);
        $a->appendToNode($root)->save();

        $b = new MeanArea(['name' => 'B', 'value' => 32.0]);
        $b->appendToNode($root->refresh())->save();

        $root->refresh();
        $this->assertEqualsWithDelta(8.0, $this->asFloat($root->value_gmean), 0.0001);

        // A (inclusive) → geometric mean of 8 alone = 8.
        $a->refresh();
        $this->assertEqualsWithDelta(8.0, $this->asFloat($a->value_gmean), 0.0001);
    }

    public function test_geometric_mean_updates_when_descendant_value_changes(): void
    {
        // Root=2, A=8. Geom mean = sqrt(16) = 4.
        $root = new MeanArea(['name' => 'Root', 'value' => 2.0]);
        $root->saveAsRoot();

        $a = new MeanArea(['name' => 'A', 'value' => 8.0]);
        $a->appendToNode($root)->save();

        $root->refresh();
        $this->assertEqualsWithDelta(4.0, $this->asFloat($root->value_gmean), 0.0001);

        // Change A to 32 → geom mean of (2, 32) = sqrt(64) = 8.
        $a->refresh()->update(['value' => 32.0]);
        $root->refresh();

        $this->assertEqualsWithDelta(8.0, $this->asFloat($root->value_gmean), 0.0001);
    }

    public function test_deleting_descendant_updates_geometric_mean(): void
    {
        $root = new MeanArea(['name' => 'Root', 'value' => 8.0]);
        $root->saveAsRoot();

        $a = new MeanArea(['name' => 'A', 'value' => 2.0]);
        $a->appendToNode($root)->save();

        // Geom mean of (8, 2) = sqrt(16) = 4.
        $root->refresh();
        $this->assertEqualsWithDelta(4.0, $this->asFloat($root->value_gmean), 0.0001);

        // Delete A → geom mean of (8) = 8.
        $a->refresh()->delete();
        $root->refresh();

        $this->assertEqualsWithDelta(8.0, $this->asFloat($root->value_gmean), 0.0001);
    }

    public function test_fix_aggregates_matches_delta_maintained_geometric_mean(): void
    {
        $root = new MeanArea(['name' => 'Root', 'value' => 2.0]);
        $root->saveAsRoot();

        foreach ([4.0, 8.0, 16.0] as $i => $v) {
            $node = new MeanArea(['name' => 'C'.$i, 'value' => $v]);
            $node->appendToNode($root->refresh())->save();
        }

        $root->refresh();
        $deltaMaintained = $this->asFloat($root->value_gmean);

        MeanArea::fixAggregates($root);
        $root->refresh();

        $this->assertEqualsWithDelta($deltaMaintained, $this->asFloat($root->value_gmean), 0.0001);
    }

    public function test_fix_aggregates_repairs_corrupted_geometric_mean(): void
    {
        $root = new MeanArea(['name' => 'Root', 'value' => 4.0]);
        $root->saveAsRoot();

        $a = new MeanArea(['name' => 'A', 'value' => 16.0]);
        $a->appendToNode($root)->save();

        MeanArea::query()->where('id', $root->refresh()->getKey())->update(['value_gmean' => 999.9]);

        MeanArea::fixAggregates($root);
        $root->refresh();

        // Geom mean of (4, 16) = sqrt(64) = 8.
        $this->assertEqualsWithDelta(8.0, $this->asFloat($root->value_gmean), 0.0001);
    }

    // ── Harmonic mean ─────────────────────────────────────────────────

    public function test_harmonic_mean_of_single_nonzero_root_equals_itself(): void
    {
        $root = new MeanArea(['name' => 'Root', 'value' => 6.0]);
        $root->saveAsRoot();
        $root->refresh();

        $this->assertEqualsWithDelta(6.0, $this->asFloat($root->value_hmean), 0.0001);
    }

    public function test_harmonic_mean_of_three_values(): void
    {
        // Harmonic mean of 2, 3, 6: 3 / (1/2 + 1/3 + 1/6) = 3 / 1 = 3.
        $root = new MeanArea(['name' => 'Root', 'value' => 2.0]);
        $root->saveAsRoot();

        $a = new MeanArea(['name' => 'A', 'value' => 3.0]);
        $a->appendToNode($root)->save();

        $b = new MeanArea(['name' => 'B', 'value' => 6.0]);
        $b->appendToNode($root->refresh())->save();

        $root->refresh();
        $this->assertEqualsWithDelta(3.0, $this->asFloat($root->value_hmean), 0.0001);
    }

    public function test_harmonic_mean_updates_when_descendant_value_changes(): void
    {
        // Root=2, A=3 → HM = 2 / (1/2 + 1/3) = 2 / (5/6) = 12/5 = 2.4.
        $root = new MeanArea(['name' => 'Root', 'value' => 2.0]);
        $root->saveAsRoot();

        $a = new MeanArea(['name' => 'A', 'value' => 3.0]);
        $a->appendToNode($root)->save();

        $root->refresh();
        $this->assertEqualsWithDelta(12 / 5, $this->asFloat($root->value_hmean), 0.0001);

        // Change A to 6 → HM of (2, 6) = 2 / (1/2 + 1/6) = 2 / (2/3) = 3.
        $a->refresh()->update(['value' => 6.0]);
        $root->refresh();

        $this->assertEqualsWithDelta(3.0, $this->asFloat($root->value_hmean), 0.0001);
    }

    public function test_deleting_descendant_updates_harmonic_mean(): void
    {
        $root = new MeanArea(['name' => 'Root', 'value' => 2.0]);
        $root->saveAsRoot();

        $a = new MeanArea(['name' => 'A', 'value' => 6.0]);
        $a->appendToNode($root)->save();

        // HM of (2, 6) = 2 / (1/2 + 1/6) = 2 / (2/3) = 3.
        $root->refresh();
        $this->assertEqualsWithDelta(3.0, $this->asFloat($root->value_hmean), 0.0001);

        // Delete A → HM of (2) alone = 2.
        $a->refresh()->delete();
        $root->refresh();

        $this->assertEqualsWithDelta(2.0, $this->asFloat($root->value_hmean), 0.0001);
    }

    public function test_fix_aggregates_matches_delta_maintained_harmonic_mean(): void
    {
        $root = new MeanArea(['name' => 'Root', 'value' => 2.0]);
        $root->saveAsRoot();

        foreach ([3.0, 6.0, 12.0] as $i => $v) {
            $node = new MeanArea(['name' => 'C'.$i, 'value' => $v]);
            $node->appendToNode($root->refresh())->save();
        }

        $root->refresh();
        $deltaMaintained = $this->asFloat($root->value_hmean);

        MeanArea::fixAggregates($root);
        $root->refresh();

        $this->assertEqualsWithDelta($deltaMaintained, $this->asFloat($root->value_hmean), 0.0001);
    }

    public function test_fix_aggregates_repairs_corrupted_harmonic_mean(): void
    {
        $root = new MeanArea(['name' => 'Root', 'value' => 2.0]);
        $root->saveAsRoot();

        $a = new MeanArea(['name' => 'A', 'value' => 6.0]);
        $a->appendToNode($root)->save();

        MeanArea::query()->where('id', $root->refresh()->getKey())->update(['value_hmean' => 999.9]);

        MeanArea::fixAggregates($root);
        $root->refresh();

        // HM of (2, 6) = 3.
        $this->assertEqualsWithDelta(3.0, $this->asFloat($root->value_hmean), 0.0001);
    }

    // ── Constraint validation ─────────────────────────────────────────

    public function test_geometric_mean_throws_on_non_positive_insert(): void
    {
        $this->expectException(AggregateSourceConstraintViolationException::class);

        $root = new MeanArea(['name' => 'Root', 'value' => -1.0]);
        $root->saveAsRoot();
    }

    public function test_geometric_mean_throws_on_zero_insert(): void
    {
        $this->expectException(AggregateSourceConstraintViolationException::class);

        $root = new MeanArea(['name' => 'Root', 'value' => 0.0]);
        $root->saveAsRoot();
    }

    public function test_geometric_mean_throws_on_non_positive_update(): void
    {
        $root = new MeanArea(['name' => 'Root', 'value' => 5.0]);
        $root->saveAsRoot();

        $this->expectException(AggregateSourceConstraintViolationException::class);
        $root->refresh()->update(['value' => -3.0]);
    }

    public function test_geometric_mean_null_source_does_not_throw(): void
    {
        // NULL source is allowed — the row simply contributes nothing.
        $root = new MeanArea(['name' => 'Root', 'value' => null]);
        $root->saveAsRoot();
        $root->refresh();

        $this->assertNull($root->value_gmean);
    }

    public function test_harmonic_mean_throws_on_zero_insert(): void
    {
        $this->expectException(AggregateSourceConstraintViolationException::class);

        $root = new MeanArea(['name' => 'Root', 'value' => 0.0]);
        $root->saveAsRoot();
    }

    // ── Structural moves ──────────────────────────────────────────────
    //
    // Means are maintained via the additive companion path: every move
    // re-routes the log-sum / recip-sum contribution of the moved
    // subtree from the old ancestor chain to the new one. These tests
    // mirror StructuralMutationMaintenanceTest's coverage for SUM/AVG,
    // adapted to the multiplicative semantics of the means.

    public function test_geometric_mean_subtracts_when_moving_descendant_to_a_different_parent_subtree(): void
    {
        // Build two siblings under one root:
        //   Root(2)
        //   ├── A(8)
        //   └── B(32)
        // Root inclusive subtree {2, 8, 32} → geom mean = 8.
        $root = new MeanArea(['name' => 'Root', 'value' => 2.0]);
        $root->saveAsRoot();

        $a = new MeanArea(['name' => 'A', 'value' => 8.0]);
        $a->appendToNode($root)->save();

        $b = new MeanArea(['name' => 'B', 'value' => 32.0]);
        $b->appendToNode($root->refresh())->save();

        $root->refresh();
        $this->assertEqualsWithDelta(8.0, $this->asFloat($root->value_gmean), 0.0001);

        // Move B underneath A — the {2, 8, 32} multiset across the
        // whole tree is unchanged, so Root's inclusive geom mean stays 8.
        // A's inclusive subtree gains {32}: from {8} to {8, 32} →
        // geom mean = sqrt(256) = 16.
        $b->refresh()->moveTo($a->refresh());
        $b->save();

        $root->refresh();
        $a->refresh();

        $this->assertEqualsWithDelta(8.0, $this->asFloat($root->value_gmean), 0.0001, 'root mean unchanged when moving within own subtree');
        $this->assertEqualsWithDelta(16.0, $this->asFloat($a->value_gmean), 0.0001, 'A picks up B as a new descendant');
    }

    public function test_geometric_mean_subtracts_from_old_ancestor_chain_on_make_root(): void
    {
        // Root(2) → A(8) → A1(32). Root inclusive {2, 8, 32} → 8.
        $root = new MeanArea(['name' => 'Root', 'value' => 2.0]);
        $root->saveAsRoot();

        $a = new MeanArea(['name' => 'A', 'value' => 8.0]);
        $a->appendToNode($root)->save();

        $a1 = new MeanArea(['name' => 'A1', 'value' => 32.0]);
        $a1->appendToNode($a->refresh())->save();

        $root->refresh();
        $this->assertEqualsWithDelta(8.0, $this->asFloat($root->value_gmean), 0.0001);

        // Promote A (carrying A1) to its own tree. Old root inclusive
        // collapses to {2} → geom mean = 2. New A tree inclusive is
        // {8, 32} → geom mean = 16.
        $a->refresh()->makeRoot()->save();

        $root->refresh();
        $a->refresh();

        $this->assertEqualsWithDelta(2.0, $this->asFloat($root->value_gmean), 0.0001, 'old root keeps only its own value');
        $this->assertEqualsWithDelta(16.0, $this->asFloat($a->value_gmean), 0.0001, 'new root sees its own subtree {8, 32}');
    }

    public function test_harmonic_mean_subtracts_when_moving_descendant_to_a_different_parent_subtree(): void
    {
        // Build two siblings under one root:
        //   Root(2)
        //   ├── A(3)
        //   └── B(6)
        // Root inclusive {2, 3, 6} → HM = 3 / (1/2 + 1/3 + 1/6) = 3.
        $root = new MeanArea(['name' => 'Root', 'value' => 2.0]);
        $root->saveAsRoot();

        $a = new MeanArea(['name' => 'A', 'value' => 3.0]);
        $a->appendToNode($root)->save();

        $b = new MeanArea(['name' => 'B', 'value' => 6.0]);
        $b->appendToNode($root->refresh())->save();

        $root->refresh();
        $this->assertEqualsWithDelta(3.0, $this->asFloat($root->value_hmean), 0.0001);

        // Move B underneath A. Root multiset unchanged → HM = 3 still.
        // A inclusive becomes {3, 6} → HM = 2 / (1/3 + 1/6) = 2 / (1/2) = 4.
        $b->refresh()->moveTo($a->refresh());
        $b->save();

        $root->refresh();
        $a->refresh();

        $this->assertEqualsWithDelta(3.0, $this->asFloat($root->value_hmean), 0.0001);
        $this->assertEqualsWithDelta(4.0, $this->asFloat($a->value_hmean), 0.0001);
    }

    public function test_harmonic_mean_subtracts_from_old_ancestor_chain_on_make_root(): void
    {
        // Root(2) → A(3) → A1(6). Root inclusive {2, 3, 6} → HM = 3.
        $root = new MeanArea(['name' => 'Root', 'value' => 2.0]);
        $root->saveAsRoot();

        $a = new MeanArea(['name' => 'A', 'value' => 3.0]);
        $a->appendToNode($root)->save();

        $a1 = new MeanArea(['name' => 'A1', 'value' => 6.0]);
        $a1->appendToNode($a->refresh())->save();

        $root->refresh();
        $this->assertEqualsWithDelta(3.0, $this->asFloat($root->value_hmean), 0.0001);

        // Promote A. Old root collapses to {2} → HM = 2. New A tree
        // {3, 6} → HM = 2 / (1/3 + 1/6) = 4.
        $a->refresh()->makeRoot()->save();

        $root->refresh();
        $a->refresh();

        $this->assertEqualsWithDelta(2.0, $this->asFloat($root->value_hmean), 0.0001);
        $this->assertEqualsWithDelta(4.0, $this->asFloat($a->value_hmean), 0.0001);
    }

    public function test_means_unchanged_when_reordering_siblings_under_same_parent(): void
    {
        // Reordering siblings under the same parent leaves every
        // ancestor's inclusive subtree set unchanged — both means
        // should be no-ops on the structural side.
        $root = new MeanArea(['name' => 'Root', 'value' => 2.0]);
        $root->saveAsRoot();

        $a = new MeanArea(['name' => 'A', 'value' => 4.0]);
        $a->appendToNode($root)->save();

        $b = new MeanArea(['name' => 'B', 'value' => 8.0]);
        $b->appendToNode($root->refresh())->save();

        $root->refresh();
        $gmBefore = $this->asFloat($root->value_gmean);
        $hmBefore = $this->asFloat($root->value_hmean);

        // Swap A and B by moving A after B.
        $a->refresh()->moveAfter($b->refresh());
        $a->save();

        $root->refresh();
        $this->assertEqualsWithDelta($gmBefore, $this->asFloat($root->value_gmean), 0.0001, 'geometric mean unchanged by sibling reorder');
        $this->assertEqualsWithDelta($hmBefore, $this->asFloat($root->value_hmean), 0.0001, 'harmonic mean unchanged by sibling reorder');
    }
}
