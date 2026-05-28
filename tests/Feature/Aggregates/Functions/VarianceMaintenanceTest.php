<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Functions;

use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\MetricArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * M1 maintenance tests: Variance and Stddev (both population and sample)
 * stay correct as nodes are inserted, updated, moved, and deleted.
 *
 * The display columns ride on three delta-maintained companions (Sum,
 * SumSq, Count) — same machinery as AVG, with one extra Sum-of-squares
 * companion that's auto-promoted as a SUM with a Square source
 * transform.
 *
 * The textbook `E[X²] − E[X]²` form is used everywhere; tests check
 * within a small floating-point delta to stay backend-agnostic
 * (different SQL backends round decimal division slightly differently).
 */
final class VarianceMaintenanceTest extends TestCase
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

    // ----------------------------------------------------------------
    // Single-row subtree edge cases
    // ----------------------------------------------------------------

    public function test_root_alone_has_zero_population_variance_and_null_sample_variance(): void
    {
        $root = new MetricArea(['name' => 'Root', 'tickets' => 42]);
        $root->saveAsRoot();
        $root->refresh();

        // Population variance of a single value is 0; sample is undefined (n-1 = 0).
        $this->assertEqualsWithDelta(0.0, $this->asFloat($root->tickets_variance), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->asFloat($root->tickets_stddev), 0.0001);
        $this->assertNull($root->tickets_variance_samp);
        $this->assertNull($root->tickets_stddev_samp);
    }

    public function test_motivating_example_tree(): void
    {
        // Root(100) > A(50) > A1(50); Root > B(25).
        $root = new MetricArea(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new MetricArea(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a1 = new MetricArea(['name' => 'A1', 'tickets' => 50]);
        $a1->appendToNode($a->refresh())->save();

        $b = new MetricArea(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();

        $root->refresh();
        $a->refresh();
        $a1->refresh();
        $b->refresh();

        // Inclusive population variance over root's subtree: values are
        // [100, 50, 50, 25]. Mean = 56.25. Variance =
        // ((100-56.25)^2 + (50-56.25)^2 + (50-56.25)^2 + (25-56.25)^2) / 4
        //   = (1914.0625 + 39.0625 + 39.0625 + 976.5625) / 4
        //   = 742.1875
        $this->assertEqualsWithDelta(742.1875, $this->asFloat($root->tickets_variance), 0.01);
        $this->assertEqualsWithDelta(sqrt(742.1875), $this->asFloat($root->tickets_stddev), 0.01);

        // Sample variance: same numerator, divided by (n-1) = 3
        //   = 989.5833…
        $this->assertEqualsWithDelta(989.5833, $this->asFloat($root->tickets_variance_samp), 0.01);
        $this->assertEqualsWithDelta(sqrt(989.5833), $this->asFloat($root->tickets_stddev_samp), 0.01);

        // A's subtree: [50, 50] → variance = 0, stddev = 0 (both variants)
        $this->assertEqualsWithDelta(0.0, $this->asFloat($a->tickets_variance), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->asFloat($a->tickets_variance_samp), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->asFloat($a->tickets_stddev), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->asFloat($a->tickets_stddev_samp), 0.0001);

        // Leaf A1 and B: variance_samp NULL (n=1), variance 0
        $this->assertEqualsWithDelta(0.0, $this->asFloat($a1->tickets_variance), 0.0001);
        $this->assertNull($a1->tickets_variance_samp);
        $this->assertEqualsWithDelta(0.0, $this->asFloat($b->tickets_variance), 0.0001);
        $this->assertNull($b->tickets_variance_samp);
    }

    // ----------------------------------------------------------------
    // Updates: source column changes propagate to companions and display
    // ----------------------------------------------------------------

    public function test_source_update_propagates_to_variance_and_stddev_columns(): void
    {
        $root = new MetricArea(['name' => 'Root', 'tickets' => 10]);
        $root->saveAsRoot();

        $a = new MetricArea(['name' => 'A', 'tickets' => 20]);
        $a->appendToNode($root)->save();

        $b = new MetricArea(['name' => 'B', 'tickets' => 30]);
        $b->appendToNode($root->refresh())->save();

        // Update A: 20 → 200. New values under root: [10, 200, 30].
        // Mean = 80. Variance_pop = ((10-80)^2 + (200-80)^2 + (30-80)^2) / 3
        //   = (4900 + 14400 + 2500) / 3 = 7266.6666…
        $a->refresh()->update(['tickets' => 200]);
        $root->refresh();

        $this->assertEqualsWithDelta(7266.6667, $this->asFloat($root->tickets_variance), 0.01);
        $this->assertEqualsWithDelta(sqrt(7266.6667), $this->asFloat($root->tickets_stddev), 0.01);

        // Sample: ((10-80)^2 + (200-80)^2 + (30-80)^2) / 2 = 10900
        $this->assertEqualsWithDelta(10900.0, $this->asFloat($root->tickets_variance_samp), 0.05);
    }

    // ----------------------------------------------------------------
    // Deletion: removed contributions roll out of ancestor companions
    // ----------------------------------------------------------------

    public function test_descendant_delete_re_computes_variance(): void
    {
        $root = new MetricArea(['name' => 'Root', 'tickets' => 10]);
        $root->saveAsRoot();

        $a = new MetricArea(['name' => 'A', 'tickets' => 30]);
        $a->appendToNode($root)->save();

        $b = new MetricArea(['name' => 'B', 'tickets' => 50]);
        $b->appendToNode($root->refresh())->save();

        // Delete B. Remaining: [10, 30]. Mean = 20. Variance_pop = 100.
        $b->refresh()->delete();
        $root->refresh();

        $this->assertEqualsWithDelta(100.0, $this->asFloat($root->tickets_variance), 0.01);
        $this->assertEqualsWithDelta(10.0, $this->asFloat($root->tickets_stddev), 0.01);
        // Sample: 200 (= squared-deviation-sum 200 / 1)
        $this->assertEqualsWithDelta(200.0, $this->asFloat($root->tickets_variance_samp), 0.01);
    }

    public function test_all_descendants_deleted_leaves_root_with_self_only(): void
    {
        $root = new MetricArea(['name' => 'Root', 'tickets' => 99]);
        $root->saveAsRoot();

        $child = new MetricArea(['name' => 'Child', 'tickets' => 11]);
        $child->appendToNode($root)->save();

        $child->refresh()->delete();
        $root->refresh();

        // Single-row subtree: pop variance 0; sample variance NULL.
        $this->assertEqualsWithDelta(0.0, $this->asFloat($root->tickets_variance), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->asFloat($root->tickets_stddev), 0.0001);
        $this->assertNull($root->tickets_variance_samp);
        $this->assertNull($root->tickets_stddev_samp);
    }

    // ----------------------------------------------------------------
    // fixAggregates: full recompute over source data matches delta-maintained value
    // ----------------------------------------------------------------

    public function test_fix_aggregates_reproduces_delta_maintained_variance(): void
    {
        $root = new MetricArea(['name' => 'Root', 'tickets' => 10]);
        $root->saveAsRoot();

        $values = [5, 7, 12, 18, 22];
        foreach ($values as $i => $value) {
            $node = new MetricArea(['name' => 'Child'.$i, 'tickets' => $value]);
            $node->appendToNode($root->refresh())->save();
        }

        $root->refresh();
        $deltaMaintained = $this->asFloat($root->tickets_variance);
        $deltaMaintainedSamp = $this->asFloat($root->tickets_variance_samp);

        // Force a recompute from source via fixAggregates, then verify
        // the recomputed value matches what delta maintenance produced.
        MetricArea::fixAggregates($root);

        $root->refresh();
        $this->assertEqualsWithDelta($deltaMaintained, $this->asFloat($root->tickets_variance), 0.01);
        $this->assertEqualsWithDelta($deltaMaintainedSamp, $this->asFloat($root->tickets_variance_samp), 0.01);
    }

    public function test_fix_aggregates_repairs_corrupted_display_column(): void
    {
        $root = new MetricArea(['name' => 'Root', 'tickets' => 10]);
        $root->saveAsRoot();

        $a = new MetricArea(['name' => 'A', 'tickets' => 30]);
        $a->appendToNode($root)->save();

        // Corrupt the stored variance directly to simulate drift.
        MetricArea::query()->where('id', $root->refresh()->getKey())->update([
            'tickets_variance' => 999.9,
            'tickets_stddev' => 999.9,
        ]);

        MetricArea::fixAggregates($root);
        $root->refresh();

        // Values [10, 30]. Pop variance = 100; stddev = 10.
        $this->assertEqualsWithDelta(100.0, $this->asFloat($root->tickets_variance), 0.01);
        $this->assertEqualsWithDelta(10.0, $this->asFloat($root->tickets_stddev), 0.01);
    }

    public function test_aggregate_errors_is_silent_on_correctly_maintained_chain_tree(): void
    {
        // Chain-shape (every parent has exactly one child) takes the
        // PHP chain-fold fast path in selectStoredAndComputed*().
        // Regression guard: without applying the SumSq companion's
        // `Square` source transform inside the chain fold, the fast
        // path would fold raw x and flag the (correctly-maintained)
        // stored SumSq column as drifted.
        $values = [3, 7, 11, 5, 17];
        $previous = null;
        foreach ($values as $i => $value) {
            $node = new MetricArea(['name' => 'Node'.$i, 'tickets' => $value]);
            if (! $previous instanceof MetricArea) {
                $node->saveAsRoot();
            } else {
                $node->appendToNode($previous->refresh())->save();
            }
            $previous = $node;
        }

        // No drift after a clean delta-maintained build.
        $errors = MetricArea::countErrors();

        $this->assertSame(0, array_sum($errors), 'aggregateErrors() flagged drift on a clean chain: '.json_encode($errors));
    }
}
