<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Branch;
use Vusys\NestedSet\Tests\Fixtures\Models\Monster;
use Vusys\NestedSet\Tests\Performance\Fixtures\AggregateTreeShapes;
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

    #[Test]
    public function wide_shallow_fix_aggregates(): void
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

    #[Test]
    public function wide_shallow_with_fresh_aggregates(): void
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

    #[Test]
    public function wide_shallow_append_one_more_child(): void
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

    #[Test]
    public function left_leaning_fix_aggregates(): void
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

    #[Test]
    public function left_leaning_fix_tree_recursion_probe(): void
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

    #[Test]
    public function fragmented_forest_fix_aggregates(): void
    {
        DB::table('areas')->delete();
        TreeShapes::fragmentedForest('areas');

        $this->bench(
            'fragmentedForest fixAggregates (intact, full table)',
            fn (): AggregateFixResult => Area::fixAggregates(),
        );
        $this->assertBenchmarksRan();
    }

    #[Test]
    public function fragmented_forest_with_fresh_aggregates(): void
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

    #[Test]
    public function deep_chain_10k_fix_aggregates(): void
    {
        DB::table('areas')->delete();
        TreeShapes::deepChain('areas', nodes: 10_000);

        $this->bench(
            'deepChain fixAggregates (intact), N=10000',
            fn (): AggregateFixResult => Area::fixAggregates(),
        );
        $this->assertBenchmarksRan();
    }

    #[Test]
    public function deep_chain_10k_fix_tree_recursion_limit(): void
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

    // ----------------------------------------------------------------
    // raw-filter chain recompute — exercises the new
    // applyRawFilterChainRecompute path against shapes where the cost
    // is dominated by chain depth (deepChain) or per-ancestor subtree
    // size (wideShallow). The cost-per-save is O(depth × subtree-size).
    // ----------------------------------------------------------------

    #[Test]
    public function deep_chain_raw_filter_source_update(): void
    {
        // Deep chain stresses chain length — recompute touches every
        // ancestor (D = N). Inner subquery per ancestor is small
        // (1..N-i). Net cost ≈ O(N) per ancestor × N ancestors = O(N²).
        foreach ([100, 1_000] as $n) {
            DB::table('branches')->delete();
            AggregateTreeShapes::branchesDeepChain(nodes: $n);

            $leaf = Branch::query()->orderByDesc('depth')->orderBy('id')->firstOrFail();

            $this->bench(
                "deepChain raw-filter source-update at leaf, N={$n}",
                function () use ($leaf): void {
                    $leaf->tickets = 42;
                    $leaf->save();
                },
            );
        }
        $this->assertBenchmarksRan();
    }

    #[Test]
    public function wide_shallow_raw_filter_source_update(): void
    {
        // Wide-shallow: depth = 1 but the root's subtree size = N. The
        // recompute touches one ancestor (root) but the inner subquery
        // scans N rows. Useful counterpart to deepChain.
        foreach ([100, 1_000, 10_000] as $n) {
            DB::table('branches')->delete();
            AggregateTreeShapes::branchesWideShallow(directChildren: $n);

            $leaf = Branch::query()->whereNotNull('parent_id')->orderBy('id')->firstOrFail();

            $this->bench(
                "wideShallow raw-filter source-update at leaf, children={$n}",
                function () use ($leaf): void {
                    $leaf->tickets = 42;
                    $leaf->save();
                },
            );
        }
        $this->assertBenchmarksRan();
    }

    #[Test]
    public function wide_shallow_raw_filter_fix_aggregates(): void
    {
        foreach ([100, 1_000, 10_000] as $n) {
            DB::table('branches')->delete();
            AggregateTreeShapes::branchesWideShallow(directChildren: $n);

            $this->bench(
                "wideShallow Branch::fixAggregates() (raw filter), children={$n}",
                fn (): AggregateFixResult => Branch::fixAggregates(),
            );
        }
        $this->assertBenchmarksRan();
    }

    // ----------------------------------------------------------------
    // listener Min/Max PHP-side chain recompute — measures the cost
    // of loading every in-scope node into Eloquent + iterating in PHP
    // for each affected ancestor when an extremum is lost.
    // ----------------------------------------------------------------

    #[Test]
    public function deep_chain_listener_min_recompute(): void
    {
        // Each ancestor's contribution cache + bounds check runs in PHP
        // — pure-O(N²) on the deep-chain shape because the topmost
        // ancestor covers the full table.
        foreach ([100, 1_000] as $n) {
            DB::table('monsters')->delete();
            AggregateTreeShapes::monstersDeepChain(nodes: $n);

            // Force a Min recompute: drop one leaf below the seeded
            // uniform level, then raise it past the new minimum.
            $leaf = Monster::query()->orderByDesc('depth')->orderBy('id')->firstOrFail();
            $leaf->level = 1;
            $leaf->save();
            $leaf->refresh();

            $this->bench(
                "deepChain listener Min recompute at leaf, N={$n}",
                function () use ($leaf): void {
                    $leaf->level = 9;
                    $leaf->save();
                },
            );
        }
        $this->assertBenchmarksRan();
    }

    #[Test]
    public function wide_shallow_listener_min_delete(): void
    {
        foreach ([100, 1_000, 10_000] as $n) {
            DB::table('monsters')->delete();
            AggregateTreeShapes::monstersWideShallow(directChildren: $n);

            // Set one child's level to 1 so it's the unique Min;
            // deleting it forces the root's recompute.
            $candidate = Monster::query()->whereNotNull('parent_id')->orderBy('id')->firstOrFail();
            $candidate->level = 1;
            $candidate->save();
            $candidate->refresh();

            $this->bench(
                "wideShallow listener Min delete of extremum holder, children={$n}",
                function () use ($candidate): void {
                    $candidate->delete();
                },
            );
        }
        $this->assertBenchmarksRan();
    }

    #[Test]
    public function wide_shallow_listener_fix_aggregates(): void
    {
        // ListenerMaintenance::fixListenerAggregatesPhp is O(N²) — guard against silent
        // regressions in that bound.
        foreach ([100, 1_000] as $n) {
            DB::table('monsters')->delete();
            AggregateTreeShapes::monstersWideShallow(directChildren: $n);

            $this->bench(
                "wideShallow Monster::fixAggregates() (listener O(N^2)), children={$n}",
                fn (): AggregateFixResult => Monster::fixAggregates(),
            );
        }
        $this->assertBenchmarksRan();
    }
}
