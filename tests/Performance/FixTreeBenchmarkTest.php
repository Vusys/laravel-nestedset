<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Performance\Fixtures\TreeShapes;
use Vusys\NestedSet\TreeFixResult;

/**
 * `fixTree` benchmarks: the structural-repair counterpart to
 * `fixAggregates`. Phase S replaced the per-row UPDATE loop in
 * `TreeRepairBuilder::rebuildTree` / `rebuildSubtree` with chunked
 * `UPDATE … SET col = CASE id WHEN … END WHERE id IN (…)` so a 10K-
 * row rebuild becomes ~20 statements instead of 10,000.
 *
 * Both shapes (deep chain and balanced fanout) are exercised because
 * the per-row pattern affected every shape equally.
 */
final class FixTreeBenchmarkTest extends PerformanceTestCase
{
    #[Test]
    public function fix_tree_on_balanced_fanout(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('areas')->delete();
            TreeShapes::balancedFanout('areas', nodes: $scale, fanout: 10);

            // Drift lft/rgt/depth so fixTree has work to do (parent_id
            // stays correct — that's the authoritative column).
            DB::table('areas')->update(['lft' => 0, 'rgt' => 0, 'depth' => 0]);

            $this->bench(
                "fixTree on balanced fanout (drifted), N={$scale}",
                fn (): TreeFixResult => Area::fixTree(),
            );
        }

        $this->assertBenchmarksRan();
    }

    #[Test]
    public function fix_tree_on_deep_chain(): void
    {
        // Cap at N=1000 by default — N=10K is in the pathological
        // suite, opt-in via PATHOLOGICAL=1. Per-row mode used to take
        // tens of seconds at 10K; chunked CASE-WHEN should land it
        // well under 1s.
        $scales = array_filter($this->scales(), static fn (int $n): bool => $n <= 1_000);

        foreach ($scales as $scale) {
            DB::table('areas')->delete();
            TreeShapes::deepChain('areas', nodes: $scale);
            DB::table('areas')->update(['lft' => 0, 'rgt' => 0, 'depth' => 0]);

            $this->bench(
                "fixTree on deep chain (drifted), N={$scale}",
                fn (): TreeFixResult => Area::fixTree(),
            );
        }

        $this->assertBenchmarksRan();
    }
}
