<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Tests\Fixtures\Models\Monster;
use Vusys\NestedSet\Tests\Performance\Fixtures\AggregateTreeShapes;

/**
 * Listener aggregate maintenance has two cost shapes:
 *
 *  - Sum/Count listeners: PHP-side delta (one contribution call per
 *    save) + one UPDATE on the ancestor chain. Comparable to the cheap
 *    delta path for SQL aggregates.
 *  - Min/Max listeners with the stored extremum invalidated: ancestor
 *    chain recompute in PHP — two SELECTs (ancestors + bounded-box
 *    nodes) and one UPDATE per ancestor row, with `contribution()`
 *    called once per (definition × node) and cached.
 *
 * Monster declares both: weighted_power (Sum), fire_count (Sum),
 * half_weighted_power (Sum-of-floats), weakest_level (Min). These
 * benchmarks pin those cost shapes.
 */
final class ListenerMaintenanceBenchmarkTest extends PerformanceTestCase
{
    public function test_listener_sum_source_update_at_leaf(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('monsters')->delete();
            AggregateTreeShapes::monstersBalancedFanout(nodes: $scale, fanout: 10);

            $leaf = Monster::query()->orderByDesc('depth')->orderBy('id')->firstOrFail();

            $this->bench(
                "listener Sum source-update at leaf (PHP delta + UPDATE), N={$scale}",
                function () use ($leaf): void {
                    $leaf->base_power = 99;
                    $leaf->save();
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    public function test_listener_min_recompute_when_extremum_lost(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('monsters')->delete();
            AggregateTreeShapes::monstersBalancedFanout(nodes: $scale, fanout: 10);

            // Seed every level to 2; pick a leaf and drop its level to 1
            // so it becomes the new min for every ancestor.
            $leaf = Monster::query()->orderByDesc('depth')->orderBy('id')->firstOrFail();
            $leaf->level = 1;
            $leaf->save();
            $leaf->refresh();

            // Now raise it past the new min — invalidates every ancestor's
            // stored extremum and forces a PHP-side chain recompute.
            $this->bench(
                "listener Min recompute on extremum loss at leaf, N={$scale}",
                function () use ($leaf): void {
                    $leaf->level = 9;
                    $leaf->save();
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    public function test_listener_insert_leaf(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('monsters')->delete();
            AggregateTreeShapes::monstersBalancedFanout(nodes: $scale, fanout: 10);

            $deepNonLeaf = Monster::query()
                ->orderByDesc('depth')
                ->whereRaw('rgt > lft + 1')
                ->firstOrFail();

            $this->bench(
                "listener insert under deep parent, N={$scale}",
                function () use ($deepNonLeaf): void {
                    $child = new Monster([
                        'name' => 'new',
                        'type' => 'fire',
                        'base_power' => 5,
                        'level' => 3,
                    ]);
                    $child->appendToNode($deepNonLeaf)->save();
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    public function test_listener_delete_leaf_with_min_recompute(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('monsters')->delete();
            AggregateTreeShapes::monstersBalancedFanout(nodes: $scale, fanout: 10);

            $leaf = Monster::query()->orderByDesc('depth')->orderBy('id')->firstOrFail();

            $this->bench(
                "listener delete leaf (Min recompute path), N={$scale}",
                function () use ($leaf): void {
                    $leaf->delete();
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    public function test_fix_aggregates_listener_columns(): void
    {
        // fixListenerAggregatesPhp is O(N²) by design (per-node scan
        // of the full in-scope set). Smaller scales than the SQL
        // fixAggregates bench so this doesn't dominate the CI runtime
        // for typical perf runs.
        foreach (array_filter($this->scales(), fn (int $n): bool => $n <= 1_000) as $scale) {
            DB::table('monsters')->delete();
            AggregateTreeShapes::monstersBalancedFanout(nodes: $scale, fanout: 10);

            $this->bench(
                "Monster::fixAggregates() (listener, O(N^2)), N={$scale}",
                fn (): AggregateFixResult => Monster::fixAggregates(),
            );
        }

        $this->assertBenchmarksRan();
    }
}
