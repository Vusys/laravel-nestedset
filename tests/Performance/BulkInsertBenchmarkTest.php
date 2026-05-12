<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;

/**
 * Phase M v2 benchmarks: `bulkInsertTree()` vs the naive
 * `appendToNode->save()` per-row loop.
 *
 * v2 keeps full Eloquent semantics (events, mutators, casts,
 * mass-assignment, hydrated returns) so the wins are smaller than
 * v1's events-bypassing version — but the per-row gap-shifts and
 * the per-row aggregate-ancestor UPDATEs are both gone. Expected
 * ~10× over the naive path on MySQL/MariaDB, with the gap widening
 * at larger N because the naive path is O(N²).
 *
 * Local-only run (slow):
 *   vendor/bin/phpunit --testsuite Performance --filter BulkInsertBenchmark
 *
 * Naive baseline is capped to N=1000 — at N=10K it takes several
 * minutes on remote DBs.
 */
final class BulkInsertBenchmarkTest extends PerformanceTestCase
{
    public function test_bulk_insert_tree_balanced_fanout(): void
    {
        foreach ($this->scales() as $scale) {
            DB::table('areas')->delete();
            $root = new Area(['name' => 'root', 'tickets' => 0]);
            $root->saveAsRoot();
            $root = $root->refresh();

            $tree = $this->buildTreeArray($scale - 1, fanout: 10);

            $this->bench(
                "bulkInsertTree balanced fanout, N={$scale}",
                static function () use ($tree, $root): void {
                    Area::bulkInsertTree($tree, appendTo: $root);
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    public function test_naive_append_to_node_per_row(): void
    {
        // Cap at N=1000 — at 10K the naive loop runs for minutes on
        // every backend (O(N²) gap-shift cost). This benchmark exists
        // mainly to anchor what v2 is faster *than*.
        $scales = array_filter($this->scales(), static fn (int $n): bool => $n <= 1_000);

        foreach ($scales as $scale) {
            DB::table('areas')->delete();
            $root = new Area(['name' => 'root', 'tickets' => 0]);
            $root->saveAsRoot();
            $root = $root->refresh();

            $tree = $this->buildTreeArray($scale - 1, fanout: 10);

            $this->bench(
                "naive appendToNode-per-row, N={$scale}",
                static function () use ($tree, $root): void {
                    self::insertNaively($tree, $root);
                },
            );
        }

        $this->assertBenchmarksRan();
    }

    /**
     * Builds a `bulkInsertTree`-shaped nested array with $count nodes
     * under a notional root, each parent fanning out to $fanout
     * children until $count is exhausted. Returns the children-array
     * for the root.
     *
     * @return list<array<string, mixed>>
     */
    private function buildTreeArray(int $count, int $fanout): array
    {
        if ($count <= 0) {
            return [];
        }

        // BFS layout: lay out by levels, then convert to the nested
        // shape with parent → children.
        $nodes = [];
        for ($i = 0; $i < $count; $i++) {
            $nodes[$i] = ['name' => "n{$i}", 'tickets' => $i % 100];
        }

        // Children of node i are at indexes (i*fanout)+1 .. (i*fanout)+fanout
        // — same shape as TreeShapes::balancedFanout for fixture parity.
        $build = static function (int $i) use (&$build, &$nodes, $fanout, $count): array {
            $node = $nodes[$i];
            $children = [];
            for ($k = 1; $k <= $fanout; $k++) {
                $childIdx = $i * $fanout + $k;
                if ($childIdx < $count) {
                    $children[] = $build($childIdx);
                }
            }
            if ($children !== []) {
                $node['children'] = $children;
            }

            return $node;
        };

        // Top-level: just node 0 expanded.
        return [$build(0)];
    }

    /**
     * The path bulkInsertTree replaces — recursive per-row save under
     * a parent. Each save() triggers a makeGap CASE WHEN UPDATE that
     * shifts every post-insertion-point row, hence O(N²).
     *
     * @param  list<array<string, mixed>>  $tree
     */
    private static function insertNaively(array $tree, Area $parent): void
    {
        foreach ($tree as $branch) {
            $children = [];
            if (isset($branch['children']) && is_array($branch['children'])) {
                /** @var list<array<string, mixed>> $children */
                $children = $branch['children'];
                unset($branch['children']);
            }

            /** @var array<string, mixed> $branch */
            $node = new Area($branch);
            $node->appendToNode($parent)->save();

            if ($children !== []) {
                self::insertNaively($children, $node);
            }
        }
    }
}
