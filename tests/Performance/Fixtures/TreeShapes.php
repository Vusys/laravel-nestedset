<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance\Fixtures;

use Illuminate\Support\Facades\DB;

/**
 * Generators for parameterised tree fixtures. Seeds rows via raw
 * bulk inserts (not the package's mutation API) so the benchmark's
 * setUp is itself fast — we measure the package's operations against
 * pre-built trees, not the cost of building them.
 *
 * Each generator computes lft/rgt/depth/parent_id for every row up-front
 * and bulk-inserts in chunks. Stored aggregate columns are seeded to
 * their correct values where applicable so benchmarks measure
 * post-init steady-state, not initial drift.
 */
final class TreeShapes
{
    /**
     * Single chain: 1 → 2 → 3 → … → N. Depth = N, fanout = 1.
     * Worst case for ancestor-chain length; best case for subtree
     * width per node (always 1).
     *
     * @return int the root id
     */
    public static function deepChain(string $table, int $nodes, int $ticketsPerNode = 10): int
    {
        $rows = [];

        for ($i = 1; $i <= $nodes; $i++) {
            $rows[] = [
                'id' => $i,
                'name' => "n{$i}",
                'tickets' => $ticketsPerNode,
                'lft' => $i,
                'rgt' => 2 * $nodes - $i + 1,
                'depth' => $i - 1,
                'parent_id' => $i === 1 ? null : $i - 1,
            ];
        }

        self::bulkInsertWithAggregates($table, $rows, $ticketsPerNode);

        return 1;
    }

    /**
     * Balanced tree: every non-leaf has exactly $fanout children, until
     * $nodes rows have been placed. Depth ≈ log_{fanout}(N) — the most
     * representative shape for production hierarchies.
     *
     * @return int the root id
     */
    public static function balancedFanout(
        string $table,
        int $nodes,
        int $fanout = 10,
        int $ticketsPerNode = 10,
    ): int {
        // Build the tree structure breadth-first first, recording each
        // node's depth + parent. Then compute lft/rgt depth-first.
        $structure = self::buildBalancedStructure($nodes, $fanout);
        $rows = self::assignBoundsDfs($structure, $ticketsPerNode);

        self::bulkInsertWithAggregates($table, $rows, $ticketsPerNode);

        return 1;
    }

    /**
     * @return list<array{id: int, parent_id: ?int, depth: int, children: list<int>}>
     */
    private static function buildBalancedStructure(int $nodes, int $fanout): array
    {
        $structure = [];
        $structure[] = ['id' => 1, 'parent_id' => null, 'depth' => 0, 'children' => []];

        $next = 2;
        $cursor = 0; // index into $structure of the parent currently accepting children

        while ($next <= $nodes && $cursor < count($structure)) {
            $parentIdx = $cursor;
            $parent = &$structure[$parentIdx];

            for ($k = 0; $k < $fanout && $next <= $nodes; $k++) {
                $structure[] = [
                    'id' => $next,
                    'parent_id' => $parent['id'],
                    'depth' => $parent['depth'] + 1,
                    'children' => [],
                ];
                $parent['children'][] = $next;
                $next++;
            }

            unset($parent);
            $cursor++;
        }

        return $structure;
    }

    /**
     * @param  list<array{id: int, parent_id: ?int, depth: int, children: list<int>}>  $structure
     * @return list<array{id: int, name: string, tickets: int, lft: int, rgt: int, depth: int, parent_id: ?int}>
     */
    private static function assignBoundsDfs(array $structure, int $ticketsPerNode): array
    {
        $byId = [];
        foreach ($structure as $node) {
            $byId[$node['id']] = $node;
        }

        $rows = [];
        $counter = 0;

        $walk = function (int $id) use (&$walk, &$rows, &$counter, $byId, $ticketsPerNode): void {
            $counter++;
            $lft = $counter;

            foreach ($byId[$id]['children'] as $childId) {
                $walk($childId);
            }

            $counter++;
            $rgt = $counter;

            $rows[$id] = [
                'id' => $id,
                'name' => "n{$id}",
                'tickets' => $ticketsPerNode,
                'lft' => $lft,
                'rgt' => $rgt,
                'depth' => $byId[$id]['depth'],
                'parent_id' => $byId[$id]['parent_id'],
            ];
        };

        $walk(1);

        // Re-sort by id so the bulk insert is monotonic.
        ksort($rows);

        return array_values($rows);
    }

    /**
     * Forest: many small balanced trees that share a table. Closer to
     * the "forest mode" production target (per-tenant trees, comment
     * threads per post, etc.). $nodes is per-tree; total rows is
     * $nodes × $trees.
     *
     * @return list<int> the root ids
     */
    public static function flatRoots(
        string $table,
        int $treeCount,
        int $nodesPerTree,
        int $fanout = 10,
        int $ticketsPerNode = 10,
    ): array {
        // Re-uses balancedFanout per tree but offsets ids and lft/rgt
        // so the same table can hold all of them. This is the unscoped
        // model — for scoped models the bounds-offsetting wouldn't be
        // necessary, but the package currently treats unscoped
        // multi-root tables as a forest in one lft/rgt space.
        $rootIds = [];
        $idOffset = 0;
        $boundsOffset = 0;

        for ($t = 0; $t < $treeCount; $t++) {
            $structure = self::buildBalancedStructure($nodesPerTree, $fanout);
            $rows = self::assignBoundsDfs($structure, $ticketsPerNode);

            foreach ($rows as &$row) {
                $row['id'] += $idOffset;
                $row['lft'] += $boundsOffset;
                $row['rgt'] += $boundsOffset;
                if ($row['parent_id'] !== null) {
                    $row['parent_id'] += $idOffset;
                }
            }
            unset($row);

            self::bulkInsertWithAggregates($table, $rows, $ticketsPerNode);
            $rootIds[] = 1 + $idOffset;

            $idOffset += $nodesPerTree;
            $boundsOffset += 2 * $nodesPerTree;
        }

        return $rootIds;
    }

    /**
     * @param  list<array{id: int, name: string, tickets: int, lft: int, rgt: int, depth: int, parent_id: ?int}>  $rows
     */
    private static function bulkInsertWithAggregates(
        string $table,
        array $rows,
        int $ticketsPerNode,
    ): void {
        // Compute correct stored aggregates per row up front so benches
        // start in steady state.
        $enriched = [];
        foreach ($rows as $row) {
            $subtreeSize = (int) (($row['rgt'] - $row['lft'] + 1) / 2);
            $enriched[] = [
                ...$row,
                'tickets_total' => $subtreeSize * $ticketsPerNode,
                'tickets_count_all' => $subtreeSize,
                'tickets_avg' => $ticketsPerNode,
                'tickets_min' => $ticketsPerNode,
                'tickets_max' => $ticketsPerNode,
                'tickets_avg__sum' => 0,
                'tickets_avg__count' => $subtreeSize,
            ];
        }

        // Chunk inserts to keep packet sizes sane.
        foreach (array_chunk($enriched, 500) as $chunk) {
            DB::table($table)->insert($chunk);
        }

        self::syncSequence($table);
        self::refreshStatistics($table);
    }

    /**
     * After a bulk seed the query planner's statistics are stale —
     * PostgreSQL auto-vacuums eventually but not within a benchmark
     * run; MySQL/MariaDB only re-analyse based on row-change ratios
     * that bulk inserts can sit under; SQLite never auto-analyses.
     *
     * Production tables typically have current stats so the planner
     * picks the right index; benchmarking against stale stats
     * understates the package's real-world performance. Force a
     * refresh so the measurement reflects the realistic case.
     */
    private static function refreshStatistics(string $table): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        match ($driver) {
            'pgsql' => $connection->statement("ANALYZE \"{$table}\""),
            'mysql', 'mariadb' => $connection->statement("ANALYZE TABLE `{$table}`"),
            'sqlite' => $connection->statement("ANALYZE \"{$table}\""),
            default => null,
        };
    }

    /**
     * Raw bulk inserts with explicit `id` values leave PostgreSQL's
     * SEQUENCE untouched — subsequent Eloquent INSERTs pull id=1 and
     * collide with the seeded rows. MySQL/MariaDB auto-increment
     * silently advances past explicit ids, and SQLite uses ROWID so
     * the issue doesn't arise. PG needs an explicit `setval`.
     */
    private static function syncSequence(string $table): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $rawMax = DB::table($table)->max('id');
        $maxId = is_numeric($rawMax) ? (int) $rawMax : 0;

        if ($maxId === 0) {
            return;
        }

        $connection->statement(
            "SELECT setval(pg_get_serial_sequence(?, 'id'), ?)",
            [$table, $maxId],
        );
    }
}
