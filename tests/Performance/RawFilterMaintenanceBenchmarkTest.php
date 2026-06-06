<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Tests\Fixtures\Models\Branch;
use Vusys\NestedSet\Tests\Performance\Fixtures\AggregateTreeShapes;

/**
 * Raw-filter chain recompute is the genuinely new per-mutation cost
 * the package picks up with raw-SQL filters. The package can't produce
 * a signed delta (the predicate is opaque), so it bulk-recomputes the
 * column across the affected ancestor chain — one SELECT + N UPDATEs.
 *
 * These benchmarks isolate that cost shape so a future refactor doesn't
 * regress it unnoticed. Compare against the equivalent unfiltered SUM
 * benchmark in SourceUpdateBenchmarkTest, which rides the cheap delta
 * path (single UPDATE).
 */
final class RawFilterMaintenanceBenchmarkTest extends PerformanceTestCase
{
    #[Test]
    public function source_update_at_leaf_triggers_chain_recompute(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('branches')->delete();
            AggregateTreeShapes::branchesBalancedFanout(nodes: $scale, fanout: 10);

            // Pick a deep leaf — touches the longest ancestor chain.
            $leaf = Branch::query()->orderByDesc('depth')->orderBy('id')->firstOrFail();

            $this->bench(
                "raw-filter source-update at leaf (chain recompute), N={$scale}",
                function () use ($leaf): void {
                    // Bump tickets — `active = 1` is a watched column for
                    // the raw filter; tickets is the SUM source. The
                    // recompute fires.
                    $leaf->tickets = 11;
                    $leaf->save();
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    #[Test]
    public function watch_column_update_triggers_chain_recompute(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('branches')->delete();
            AggregateTreeShapes::branchesBalancedFanout(nodes: $scale, fanout: 10);

            $leaf = Branch::query()->orderByDesc('depth')->orderBy('id')->firstOrFail();

            $this->bench(
                "raw-filter active flip at leaf (chain recompute), N={$scale}",
                function () use ($leaf): void {
                    $leaf->active = $leaf->active === 1 ? 0 : 1;
                    $leaf->save();
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    #[Test]
    public function unrelated_save_skips_recompute(): void
    {
        // Negative-space benchmark: prove that saves not touching a
        // watched column don't fire the recompute. If this regresses
        // (gets noticeably slower) it means we're recomputing when we
        // shouldn't.
        foreach ($this->scales() as $scale) {
            DB::table('branches')->delete();
            AggregateTreeShapes::branchesBalancedFanout(nodes: $scale, fanout: 10);

            $leaf = Branch::query()->orderByDesc('depth')->orderBy('id')->firstOrFail();

            $this->bench(
                "raw-filter name-only update at leaf (no recompute), N={$scale}",
                function () use ($leaf): void {
                    $leaf->name = 'renamed-'.$leaf->name;
                    $leaf->save();
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    #[Test]
    public function insert_leaf_triggers_chain_recompute(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('branches')->delete();
            AggregateTreeShapes::branchesBalancedFanout(nodes: $scale, fanout: 10);

            $deepNonLeaf = Branch::query()
                ->orderByDesc('depth')
                ->whereRaw('rgt > lft + 1')
                ->firstOrFail();

            $this->bench(
                "raw-filter insert under deep parent, N={$scale}",
                function () use ($deepNonLeaf): void {
                    $child = new Branch(['name' => 'new', 'tickets' => 7, 'active' => 1]);
                    $child->appendToNode($deepNonLeaf)->save();
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    #[Test]
    public function delete_leaf_triggers_chain_recompute(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('branches')->delete();
            AggregateTreeShapes::branchesBalancedFanout(nodes: $scale, fanout: 10);

            $leaf = Branch::query()->orderByDesc('depth')->orderBy('id')->firstOrFail();

            $this->bench(
                "raw-filter delete leaf (chain recompute), N={$scale}",
                function () use ($leaf): void {
                    $leaf->delete();
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    #[Test]
    public function fix_aggregates_with_raw_filter(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('branches')->delete();
            AggregateTreeShapes::branchesBalancedFanout(nodes: $scale, fanout: 10);

            $this->bench(
                "Branch::fixAggregates() (raw filter + exclusive cols), N={$scale}",
                fn (): AggregateFixResult => Branch::fixAggregates(),
            );
        }

        $this->assertBenchmarksRan();
    }
}
