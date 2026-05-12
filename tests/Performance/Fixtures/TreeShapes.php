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
     * Wide-shallow: one root with $directChildren leaf children all at
     * depth 1. Worst case for any operation that groups by parent_id
     * (the root has N children), for sibling reordering, and for the
     * MIN/MAX recompute walking the full sibling set. Best case for
     * depth-bounded operations (everything's at depth ≤ 1).
     *
     * Total nodes = 1 (root) + $directChildren.
     *
     * @return int the root id
     */
    public static function wideShallow(string $table, int $directChildren, int $ticketsPerNode = 10): int
    {
        $rows = [];
        $rows[] = [
            'id' => 1,
            'name' => 'root',
            'tickets' => $ticketsPerNode,
            'lft' => 1,
            'rgt' => 2 * ($directChildren + 1),
            'depth' => 0,
            'parent_id' => null,
        ];
        for ($i = 0; $i < $directChildren; $i++) {
            $rows[] = [
                'id' => $i + 2,
                'name' => "c{$i}",
                'tickets' => $ticketsPerNode,
                'lft' => 2 + 2 * $i,
                'rgt' => 3 + 2 * $i,
                'depth' => 1,
                'parent_id' => 1,
            ];
        }

        self::bulkInsertWithAggregates($table, $rows, $ticketsPerNode);

        return 1;
    }

    /**
     * Left-leaning binary: every non-leaf has exactly two children
     * (left, right). The right child is always a leaf; the left child
     * is the next non-leaf. Net effect — half the tree is one long
     * chain on the left side, plus N/2 small siblings hanging off it.
     *
     * Combines depth pressure (the spine is ~N/2 deep) with sibling
     * width pressure (every spine node has a real sibling to consider
     * in extremum queries).
     *
     * @return int the root id
     */
    public static function leftLeaning(string $table, int $nodes, int $ticketsPerNode = 10): int
    {
        // Build parallel lists (id-indexed) for parent, depth, children.
        // Keeping each property in its own narrow-typed array lets the
        // type system reason about every access, where one mixed-shape
        // associative array would land on `mixed` for every read.
        /** @var array<int, int|null> $parent */
        $parent = [1 => null];
        /** @var array<int, int> $depth */
        $depth = [1 => 0];
        /** @var array<int, list<int>> $children */
        $children = [1 => []];

        $spineHead = 1;
        $nextId = 2;
        while ($nextId <= $nodes) {
            $leftId = $nextId++;
            $parent[$leftId] = $spineHead;
            $depth[$leftId] = $depth[$spineHead] + 1;
            $children[$leftId] = [];
            $children[$spineHead][] = $leftId;

            if ($nextId > $nodes) {
                break;
            }

            $rightId = $nextId++;
            $parent[$rightId] = $spineHead;
            $depth[$rightId] = $depth[$spineHead] + 1;
            $children[$rightId] = [];
            $children[$spineHead][] = $rightId;

            // Continue down the left child.
            $spineHead = $leftId;
        }

        $structure = [];
        foreach ($parent as $id => $parentId) {
            $structure[] = [
                'id' => $id,
                'parent_id' => $parentId,
                'depth' => $depth[$id],
                'children' => $children[$id],
            ];
        }

        $rows = self::assignBoundsDfs($structure, $ticketsPerNode);
        self::bulkInsertWithAggregates($table, $rows, $ticketsPerNode);

        return 1;
    }

    /**
     * Fragmented forest: many singletons + a few small trees + one
     * larger tree, sharing one table. Tests that forest-traversal
     * code paths don't degrade when scope/tree size varies wildly.
     *
     * Returns the root id of the largest tree (useful for anchored
     * tests).
     */
    public static function fragmentedForest(string $table, int $ticketsPerNode = 10): int
    {
        // 100 singletons.
        $rootIds = self::flatRoots($table, treeCount: 100, nodesPerTree: 1, fanout: 1, ticketsPerNode: $ticketsPerNode);

        // 10 small trees (100 nodes each).
        self::flatRoots($table, treeCount: 10, nodesPerTree: 100, fanout: 10, ticketsPerNode: $ticketsPerNode);

        // 1 larger tree (1000 nodes).
        $largeRoots = self::flatRoots($table, treeCount: 1, nodesPerTree: 1000, fanout: 10, ticketsPerNode: $ticketsPerNode);

        return $largeRoots[0] ?? ($rootIds[0] ?? 1);
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
