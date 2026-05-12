<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;

/**
 * `bulkInsertTree` vs the per-row append baseline.
 *
 * The per-row baseline calls `appendToNode`+`save` once per node. The
 * gap-shift CASE-WHEN UPDATE inside makeGap() is O(N_after_X) so the
 * total cost is O(N²) — this benchmark is capped at scales where the
 * baseline finishes within a CI-tolerable time. At N=10K the per-row
 * shape takes minutes on every backend.
 *
 * The bulk path:
 *  - one gap-open per call (for `appendTo`) or zero (for fresh roots)
 *  - one bulk INSERT (chunked at 500)
 *  - one fixAggregates() pass at the end
 */
final class BulkInsertBenchmarkTest extends PerformanceTestCase
{
    /**
     * Bulk-insert a balanced fanout=10 tree as new roots. We only test
     * up to N=10K — at 100K the chunk-by-500 INSERT works fine but the
     * extra DB chatter dominates the wall-clock for what is fundamentally
     * an N×INSERT operation; the algorithmic win was at N²→N.
     */
    public function test_bulk_insert_tree_as_roots(): void
    {
        foreach ([100, 1_000, 10_000] as $scale) {
            if ($scale > $this->maxScale()) {
                break;
            }
            DB::table('areas')->delete();

            $tree = $this->buildBalancedFanoutInput(nodes: $scale, fanout: 10);

            $this->bench(
                "bulkInsertTree as roots, N={$scale}",
                function () use ($tree): void {
                    Area::bulkInsertTree($tree);
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    /**
     * Same workload via N × `appendToNode`. Capped at 1K because at 10K
     * the per-row shape takes well over a minute even on fast hardware —
     * the regression we're showing only needs the shape, not the tail.
     */
    public function test_per_row_append_baseline(): void
    {
        foreach ([100, 1_000] as $scale) {
            if ($scale > $this->maxScale()) {
                break;
            }
            DB::table('areas')->delete();

            $tree = $this->buildBalancedFanoutInput(nodes: $scale, fanout: 10);

            $this->bench(
                "N x appendToNode baseline, N={$scale}",
                function () use ($tree): void {
                    $this->insertTreePerRow($tree, parent: null);
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    /**
     * Generates the same balanced tree shape TreeShapes::balancedFanout
     * produces, but in nested-array form so it can feed bulkInsertTree.
     *
     * @return list<array<string, mixed>>
     */
    private function buildBalancedFanoutInput(int $nodes, int $fanout = 10, int $ticketsPerNode = 10): array
    {
        $remaining = $nodes - 1; // root is the first node
        if ($remaining < 0) {
            return [];
        }

        $build = function (int &$remaining, int $fanout, int $ticketsPerNode) use (&$build): array {
            $children = [];
            for ($k = 0; $k < $fanout && $remaining > 0; $k++) {
                $remaining--;
                $children[] = [
                    'name' => 'n',
                    'tickets' => $ticketsPerNode,
                    'children' => $build($remaining, $fanout, $ticketsPerNode),
                ];
            }

            return $children;
        };

        return [[
            'name' => 'root',
            'tickets' => $ticketsPerNode,
            'children' => $build($remaining, $fanout, $ticketsPerNode),
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $tree
     */
    private function insertTreePerRow(array $tree, ?Area $parent): void
    {
        foreach ($tree as $node) {
            $children = [];
            if (isset($node['children']) && is_array($node['children'])) {
                /** @var list<array<string, mixed>> $children */
                $children = array_values($node['children']);
                unset($node['children']);
            }

            $area = new Area([
                'name' => is_string($node['name'] ?? null) ? $node['name'] : 'n',
                'tickets' => is_int($node['tickets'] ?? null) ? $node['tickets'] : 0,
            ]);

            if (! $parent instanceof Area) {
                $area->saveAsRoot();
            } else {
                $area->appendToNode($parent)->save();
            }

            $area = $area->refresh();
            $this->insertTreePerRow($children, $area);
        }
    }
}
