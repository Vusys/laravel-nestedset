<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Performance\Fixtures\TreeShapes;

/**
 * `withFreshAggregates()` benchmarks: the read-fresh path.
 *
 * The pre-v0.7.0 implementation added one correlated sub-query per
 * requested aggregate as a SELECT expression. For N result rows × K
 * aggregates that's N×K inner-subtree scans. v0.7.0 collapses these
 * into a single LEFT JOIN LATERAL on backends that support it (PG,
 * MySQL 8.0.14+, MariaDB 10.3+), so each outer row pays one inner
 * scan and gets K aggregates out of it.
 *
 * Two variants:
 *   - "all declared" — every user-facing aggregate (5 columns)
 *   - "single column" — only tickets_total (1 column)
 *
 * The all-declared variant is the case where LATERAL pays off most;
 * single-column should be roughly flat between shapes.
 */
final class WithFreshAggregatesBenchmarkTest extends PerformanceTestCase
{
    public function test_with_fresh_aggregates_all_declared(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('areas')->delete();
            TreeShapes::balancedFanout('areas', nodes: $scale, fanout: 10);

            $this->bench(
                "withFreshAggregates (5 declared columns), N={$scale}",
                function (): void {
                    Area::query()->withFreshAggregates()->get();
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    public function test_with_fresh_aggregates_single_column(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('areas')->delete();
            TreeShapes::balancedFanout('areas', nodes: $scale, fanout: 10);

            $this->bench(
                "withFreshAggregates (1 SUM column), N={$scale}",
                function (): void {
                    Area::query()->withFreshAggregates(['tickets_total'])->get();
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    public function test_with_fresh_aggregates_adhoc_aggregate(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('areas')->delete();
            TreeShapes::balancedFanout('areas', nodes: $scale, fanout: 10);

            $this->bench(
                "withFreshAggregates (1 ad-hoc SUM), N={$scale}",
                function (): void {
                    Area::query()->withFreshAggregates([
                        'subtree_tickets' => Aggregate::sum('tickets'),
                    ])->get();
                },
            );
        }

        $this->assertBenchmarksRan();
    }
}
