<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Performance\Fixtures\TreeShapes;
use Vusys\NestedSet\TreeFixResult;

/**
 * Pathological tree shapes — opt-in via `PATHOLOGICAL=1` because they
 * deliberately stress code paths that the representative
 * (balancedFanout) shape doesn't exercise. Slow on purpose.
 *
 * Three shapes covered:
 *
 *   1. **wideShallow** — 1 root with $directChildren leaves at depth 1.
 *      Stresses GROUP-BY-parent_id, sibling reordering, big-fanout
 *      MIN/MAX recompute, and bulk gap-shift over wide sibling sets.
 *
 *   2. **leftLeaning** — binary tree where every left child is itself
 *      a non-leaf and every right child is a leaf. Half the tree is
 *      one spine; the spine drives recursion depth in
 *      `TreeRepairBuilder::rebuildTree()` and the package's chained
 *      ancestor walks.
 *
 *   3. **fragmentedForest** — 100 singletons + 10 × 100 + 1 × 1000.
 *      Stresses repair-walk overhead and ensures forest operations
 *      don't degrade with uneven scope.
 *
 * Plus a re-run of **deepChain** at N=10000 (`deepChain_10k`), which
 * the standard suite caps at lower N — explicitly probes PHP's
 * recursion-stack limit inside the package's rebuild walker.
 *
 * Local run:
 *     PATHOLOGICAL=1 vendor/bin/phpunit \
 *         --testsuite Performance \
 *         --filter PathologicalShapesBenchmarkTest
 *
 * CI: apply the `run-pathological` label to a PR.
 */
final class PathologicalShapesBenchmarkTest extends PerformanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('PATHOLOGICAL') !== '1') {
            $this->markTestSkipped('Set PATHOLOGICAL=1 (or apply the `run-pathological` label in CI) to run.');
        }
    }

    // ----------------------------------------------------------------
    // wideShallow: 1 root × N leaves
    // ----------------------------------------------------------------

    public function test_wide_shallow_fix_aggregates(): void
    {
        foreach ([100, 1_000, 10_000] as $n) {
            DB::table('areas')->delete();
            TreeShapes::wideShallow('areas', directChildren: $n);

            $this->bench(
                "wideShallow fixAggregates (intact), children={$n}",
                fn (): AggregateFixResult => Area::fixAggregates(),
            );
        }
        $this->assertBenchmarksRan();
    }

    public function test_wide_shallow_with_fresh_aggregates(): void
    {
        foreach ([100, 1_000, 10_000] as $n) {
            DB::table('areas')->delete();
            TreeShapes::wideShallow('areas', directChildren: $n);

            $this->bench(
                "wideShallow withFreshAggregates 5-decl, children={$n}",
                fn () => Area::query()->withFreshAggregates()->get(),
            );
        }
        $this->assertBenchmarksRan();
    }

    public function test_wide_shallow_append_one_more_child(): void
    {
        foreach ([100, 1_000, 10_000] as $n) {
            DB::table('areas')->delete();
            TreeShapes::wideShallow('areas', directChildren: $n);
            $root = Area::query()->whereNull('parent_id')->firstOrFail();

            $this->bench(
                "wideShallow appendToNode one more child, existing={$n}",
                function () use ($root): void {
                    (new Area(['name' => 'extra', 'tickets' => 1]))
                        ->appendToNode($root)
                        ->save();
                },
            );
        }
        $this->assertBenchmarksRan();
    }

    // ----------------------------------------------------------------
    // leftLeaning: spine ≈ N/2 deep
    // ----------------------------------------------------------------

    public function test_left_leaning_fix_aggregates(): void
    {
        // Capped at N=1000 (spine ≈ 500 deep). Higher N risks the
        // recursive PHP walker in TreeRepairBuilder hitting stack
        // limits — see deep_chain_10k for a deliberate probe of that.
        foreach ([100, 1_000] as $n) {
            DB::table('areas')->delete();
            TreeShapes::leftLeaning('areas', nodes: $n);

            $this->bench(
                "leftLeaning fixAggregates (intact), N={$n}",
                fn (): AggregateFixResult => Area::fixAggregates(),
            );
        }
        $this->assertBenchmarksRan();
    }

    public function test_left_leaning_fix_tree_recursion_probe(): void
    {
        // Smaller scale — fixTree's walker is the deepest-recursion
        // path in the package.
        foreach ([100, 1_000] as $n) {
            DB::table('areas')->delete();
            TreeShapes::leftLeaning('areas', nodes: $n);

            // Pre-drift lft/rgt so the fix has work to do; parent_id
            // is the authoritative column and stays correct.
            DB::table('areas')->update(['lft' => 0, 'rgt' => 0, 'depth' => 0]);

            $this->bench(
                "leftLeaning fixTree (drifted), N={$n}",
                fn (): TreeFixResult => Area::fixTree(),
            );
        }
        $this->assertBenchmarksRan();
    }

    // ----------------------------------------------------------------
    // fragmentedForest: 100 + 10×100 + 1×1000
    // ----------------------------------------------------------------

    public function test_fragmented_forest_fix_aggregates(): void
    {
        DB::table('areas')->delete();
        TreeShapes::fragmentedForest('areas');

        $this->bench(
            'fragmentedForest fixAggregates (intact, full table)',
            fn (): AggregateFixResult => Area::fixAggregates(),
        );
        $this->assertBenchmarksRan();
    }

    public function test_fragmented_forest_with_fresh_aggregates(): void
    {
        DB::table('areas')->delete();
        TreeShapes::fragmentedForest('areas');

        $this->bench(
            'fragmentedForest withFreshAggregates 5-decl',
            fn () => Area::query()->withFreshAggregates()->get(),
        );
        $this->assertBenchmarksRan();
    }

    // ----------------------------------------------------------------
    // deepChain at N=10000 — explicit stack-depth probe
    // ----------------------------------------------------------------

    public function test_deep_chain_10k_fix_aggregates(): void
    {
        DB::table('areas')->delete();
        TreeShapes::deepChain('areas', nodes: 10_000);

        $this->bench(
            'deepChain fixAggregates (intact), N=10000',
            fn (): AggregateFixResult => Area::fixAggregates(),
        );
        $this->assertBenchmarksRan();
    }

    public function test_deep_chain_10k_fix_tree_recursion_limit(): void
    {
        // The most stressful test in this file: rebuildTree's walker
        // is a recursive PHP closure. At depth 10K we're a few hundred
        // KB into PHP's stack — typically fine, but exactly the kind
        // of code where a future change might tip it over.
        DB::table('areas')->delete();
        TreeShapes::deepChain('areas', nodes: 10_000);

        DB::table('areas')->update(['lft' => 0, 'rgt' => 0, 'depth' => 0]);

        $this->bench(
            'deepChain fixTree (drifted), N=10000',
            fn (): TreeFixResult => Area::fixTree(),
        );
        $this->assertBenchmarksRan();
    }
}
