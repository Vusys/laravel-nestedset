<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Performance\Fixtures\TreeShapes;

/**
 * `fixAggregates` benchmarks: the canonical "long batch" operation.
 * Two variants:
 *
 *   - "intact" — every row's stored value already matches the
 *     computed value. Expect ~ N round-trips for the SELECT alone,
 *     zero UPDATEs. Floor cost of the operation.
 *   - "fully drifted" — every row's stored values are reset to 0/
 *     NULL. Worst case: every row triggers an UPDATE.
 *
 * Phase L's bulk-UPDATE optimization should show its biggest impact
 * on the "fully drifted" variant.
 */
final class FixAggregatesBenchmarkTest extends PerformanceTestCase
{
    #[Test]
    public function fix_aggregates_on_intact_tree(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('areas')->delete();
            TreeShapes::balancedFanout('areas', nodes: $scale, fanout: 10);

            $this->bench(
                "fixAggregates on intact tree, N={$scale}",
                function (): void {
                    Area::fixAggregates();
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    #[Test]
    public function fix_aggregates_on_fully_drifted_tree(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('areas')->delete();
            TreeShapes::balancedFanout('areas', nodes: $scale, fanout: 10);

            // Wipe every stored aggregate so every row drifts.
            DB::table('areas')->update([
                'tickets_total' => 0,
                'tickets_count_all' => 0,
                'tickets_avg' => null,
                'tickets_min' => null,
                'tickets_max' => null,
                'tickets_avg__sum' => 0,
                'tickets_avg__count' => 0,
            ]);

            $this->bench(
                "fixAggregates on fully drifted tree, N={$scale}",
                function (): void {
                    Area::fixAggregates();
                },
            );
        }

        $this->assertBenchmarksRan();
    }
}
