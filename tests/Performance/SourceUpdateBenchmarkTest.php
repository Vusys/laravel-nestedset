<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Performance\Fixtures\TreeShapes;

/**
 * Source-column update benchmarks: SUM/COUNT/AVG ride the cheap delta
 * path; MIN/MAX may need a recompute SELECT depending on whether the
 * change crossed an extremum.
 */
final class SourceUpdateBenchmarkTest extends PerformanceTestCase
{
    public function test_source_update_at_leaf_with_no_extremum_change(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('areas')->delete();
            TreeShapes::balancedFanout('areas', nodes: $scale, fanout: 10);

            // Pick a deep leaf — touches the maximum number of ancestors.
            $leaf = Area::query()->orderByDesc('depth')->orderBy('id')->firstOrFail();

            $this->bench(
                "source-update at leaf (no extremum cross), N={$scale}",
                function () use ($leaf): void {
                    // All seeded with tickets=10; changing to 11 won't move MIN or MAX
                    // (every ancestor's stored min/max also = 10 — wait, 11 > 10, so MAX
                    // cheap-delta fires). Still no recompute SELECT — that only fires when
                    // a value DECREASES across an extremum. This is the cheap-delta case.
                    $leaf->tickets = 11;
                    $leaf->save();
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    public function test_source_update_at_leaf_invalidating_max(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('areas')->delete();
            TreeShapes::balancedFanout('areas', nodes: $scale, fanout: 10);

            $leaf = Area::query()->orderByDesc('depth')->orderBy('id')->firstOrFail();

            // First raise the leaf above the uniform max so its descent across
            // the original max is meaningful in the next mutation.
            $leaf->tickets = 1000;
            $leaf->save();
            $leaf->refresh();

            $this->bench(
                "source-update at leaf (invalidates MAX, triggers recompute), N={$scale}",
                function () use ($leaf): void {
                    $leaf->tickets = 5;
                    $leaf->save();
                },
            );
        }

        $this->assertBenchmarksRan();
    }
}
