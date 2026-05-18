<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Performance\Fixtures;

use Illuminate\Support\Facades\DB;

/**
 * Tree-shape generators for the filtered/listener-aggregate fixture
 * tables (`branches`, `monsters`). Mirrors {@see TreeShapes} for `areas`,
 * but seeds the table-specific base + aggregate columns to zero so the
 * subsequent benchmark mutation runs against a steady-state-seeded shape.
 *
 * Each generator returns the root id.
 */
final class AggregateTreeShapes
{
    // ----------------------------------------------------------------
    // branches: filtered aggregates (equality + raw-SQL filters)
    // ----------------------------------------------------------------

    public static function branchesBalancedFanout(int $nodes, int $fanout = 10): int
    {
        $structure = self::buildBalancedStructure($nodes, $fanout);
        $rows = self::assignBoundsDfs($structure);

        self::bulkInsertBranches($rows);

        return 1;
    }

    public static function branchesDeepChain(int $nodes): int
    {
        $rows = [];
        for ($i = 1; $i <= $nodes; $i++) {
            $rows[] = [
                'id' => $i,
                'lft' => $i,
                'rgt' => 2 * $nodes - $i + 1,
                'depth' => $i - 1,
                'parent_id' => $i === 1 ? null : $i - 1,
            ];
        }

        self::bulkInsertBranches($rows);

        return 1;
    }

    public static function branchesWideShallow(int $directChildren): int
    {
        $rows = [];
        $rows[] = [
            'id' => 1,
            'lft' => 1,
            'rgt' => 2 * ($directChildren + 1),
            'depth' => 0,
            'parent_id' => null,
        ];
        for ($i = 0; $i < $directChildren; $i++) {
            $rows[] = [
                'id' => $i + 2,
                'lft' => 2 + 2 * $i,
                'rgt' => 3 + 2 * $i,
                'depth' => 1,
                'parent_id' => 1,
            ];
        }

        self::bulkInsertBranches($rows);

        return 1;
    }

    // ----------------------------------------------------------------
    // monsters: listener aggregates (Sum + Min/Max via PHP recompute)
    // ----------------------------------------------------------------

    public static function monstersBalancedFanout(int $nodes, int $fanout = 10): int
    {
        $structure = self::buildBalancedStructure($nodes, $fanout);
        $rows = self::assignBoundsDfs($structure);

        self::bulkInsertMonsters($rows);

        return 1;
    }

    public static function monstersDeepChain(int $nodes): int
    {
        $rows = [];
        for ($i = 1; $i <= $nodes; $i++) {
            $rows[] = [
                'id' => $i,
                'lft' => $i,
                'rgt' => 2 * $nodes - $i + 1,
                'depth' => $i - 1,
                'parent_id' => $i === 1 ? null : $i - 1,
            ];
        }

        self::bulkInsertMonsters($rows);

        return 1;
    }

    public static function monstersWideShallow(int $directChildren): int
    {
        $rows = [];
        $rows[] = [
            'id' => 1,
            'lft' => 1,
            'rgt' => 2 * ($directChildren + 1),
            'depth' => 0,
            'parent_id' => null,
        ];
        for ($i = 0; $i < $directChildren; $i++) {
            $rows[] = [
                'id' => $i + 2,
                'lft' => 2 + 2 * $i,
                'rgt' => 3 + 2 * $i,
                'depth' => 1,
                'parent_id' => 1,
            ];
        }

        self::bulkInsertMonsters($rows);

        return 1;
    }

    // ----------------------------------------------------------------
    // shared structure builders (mirror TreeShapes)
    // ----------------------------------------------------------------

    /**
     * Builds a balanced tree as parallel typed arrays so PHPStan can
     * narrow every read. (A single nested-shape associative array
     * would land on `mixed` at every offset access — same pattern as
     * {@see TreeShapes::leftLeaning()}.)
     *
     * @return list<array{id: int, parent_id: ?int, depth: int, children: list<int>}>
     */
    private static function buildBalancedStructure(int $nodes, int $fanout): array
    {
        /** @var array<int, int|null> $parent */
        $parent = [1 => null];
        /** @var array<int, int> $depth */
        $depth = [1 => 0];
        /** @var array<int, list<int>> $children */
        $children = [1 => []];

        $queue = [1];
        $nextId = 2;

        while ($queue !== [] && $nextId <= $nodes) {
            $parentId = array_shift($queue);
            for ($c = 0; $c < $fanout && $nextId <= $nodes; $c++) {
                $parent[$nextId] = $parentId;
                $depth[$nextId] = $depth[$parentId] + 1;
                $children[$nextId] = [];
                $children[$parentId][] = $nextId;
                $queue[] = $nextId;
                $nextId++;
            }
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

        return $structure;
    }

    /**
     * @param  list<array{id: int, parent_id: ?int, depth: int, children: list<int>}>  $structure
     * @return list<array{id: int, lft: int, rgt: int, depth: int, parent_id: ?int}>
     */
    private static function assignBoundsDfs(array $structure): array
    {
        $byId = [];
        foreach ($structure as $node) {
            $byId[$node['id']] = $node;
        }

        $bounds = [];
        $cursor = 0;

        $visit = function (int $id) use (&$visit, &$cursor, &$bounds, $byId): void {
            $cursor++;
            $lft = $cursor;
            foreach ($byId[$id]['children'] as $childId) {
                $visit($childId);
            }
            $cursor++;
            $rgt = $cursor;

            $bounds[$id] = [
                'id' => $id,
                'lft' => $lft,
                'rgt' => $rgt,
                'depth' => $byId[$id]['depth'],
                'parent_id' => $byId[$id]['parent_id'],
            ];
        };

        $visit(1);

        // Sort by id for stable ordering on insert.
        ksort($bounds);

        return array_values($bounds);
    }

    /**
     * @param  list<array{id: int, lft: int, rgt: int, depth: int, parent_id: ?int}>  $rows
     */
    private static function bulkInsertBranches(array $rows): void
    {
        $now = date('Y-m-d H:i:s');
        $enriched = [];
        foreach ($rows as $row) {
            $enriched[] = [
                ...$row,
                'name' => 'b'.$row['id'],
                'tickets' => 10,
                // Half-and-half active flag — distributes raw filter
                // matches across the tree so the recompute does
                // non-trivial work.
                'active' => $row['id'] % 2,
                // Aggregates seeded to 0; first fixAggregates() (or the
                // bench's measured mutation) brings them to truth.
                'tickets_total' => 0,
                'descendants_total' => 0,
                'descendants_count' => 0,
                'descendants_max' => null,
                'active_tickets_total' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($enriched, 500) as $chunk) {
            DB::table('branches')->insert($chunk);
        }

        self::syncSequence('branches');
        self::refreshStatistics('branches');
    }

    /**
     * @param  list<array{id: int, lft: int, rgt: int, depth: int, parent_id: ?int}>  $rows
     */
    private static function bulkInsertMonsters(array $rows): void
    {
        $now = date('Y-m-d H:i:s');
        $types = ['fire', 'water', null];
        $enriched = [];
        foreach ($rows as $row) {
            $enriched[] = [
                ...$row,
                'name' => 'm'.$row['id'],
                'type' => $types[$row['id'] % 3],
                'base_power' => 10,
                'level' => 2,
                'weighted_power' => 0,
                'fire_count' => 0,
                'half_weighted_power' => 0,
                'weakest_level' => null,
                'weighted_avg' => null,
                'weighted_avg__sum' => 0,
                'weighted_avg__count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($enriched, 500) as $chunk) {
            DB::table('monsters')->insert($chunk);
        }

        self::syncSequence('monsters');
        self::refreshStatistics('monsters');
    }

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
