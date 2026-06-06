<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Performance\Fixtures\TreeShapes;

/**
 * Insertion benchmarks: how does `appendToNode` scale with tree size?
 * Specifically tests appending a leaf to the existing root after the
 * tree is already at the target size. SUM/COUNT/AVG delta is O(depth);
 * we expect roughly flat times across scales for balanced trees.
 */
final class InsertionBenchmarkTest extends PerformanceTestCase
{
    #[Test]
    public function append_leaf_to_root_in_balanced_tree(): void
    {
        foreach ($this->scales() as $scale) {
            // Truncate before each scale (PerformanceTestCase doesn't reset between cases)
            DB::table('areas')->delete();

            TreeShapes::balancedFanout('areas', nodes: $scale, fanout: 10);

            $root = Area::query()->where('id', 1)->firstOrFail();

            $this->bench(
                "appendToNode leaf in balanced tree, N={$scale}",
                function () use ($root): void {
                    $leaf = new Area(['name' => 'new-leaf', 'tickets' => 5]);
                    $leaf->appendToNode($root)->save();
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    #[Test]
    public function append_leaf_to_root_in_deep_chain(): void
    {
        // Deep chain bounds the depth dimension. Append at the end —
        // worst case for depth.
        foreach ($this->scales() as $scale) {
            DB::table('areas')->delete();

            // Chain scales pathologically — cap at 1000 to keep CI sane.
            if ($scale > 1000) {
                continue;
            }

            TreeShapes::deepChain('areas', nodes: $scale);

            $deepestNode = Area::query()->orderByDesc('depth')->firstOrFail();

            $this->bench(
                "appendToNode at depth={$scale}, N={$scale}",
                function () use ($deepestNode): void {
                    $leaf = new Area(['name' => 'deeper', 'tickets' => 5]);
                    $leaf->appendToNode($deepestNode)->save();
                },
            );
        }

        $this->assertBenchmarksRan();
    }
}
