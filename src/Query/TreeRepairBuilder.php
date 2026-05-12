<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Illuminate\Database\Connection;
use Vusys\NestedSet\TreeFixResult;

/**
 * Validates and repairs a nested-set table.
 *
 * Repair operations rebuild lft/rgt/depth values from the parent_id
 * column, which is always kept consistent. fixTree() targets a single
 * root subtree; rebuildTree() rebuilds all roots.
 */
final readonly class TreeRepairBuilder
{
    public function __construct(
        private Connection $connection,
        private string $table,
        private string $lft,
        private string $rgt,
        private string $parentId,
        private string $depth,
    ) {}

    // ----------------------------------------------------------------
    // Validation
    // ----------------------------------------------------------------

    /**
     * Returns counts of known tree invariant violations.
     *
     * @return array{invalid_bounds: int, duplicate_lft: int, duplicate_rgt: int, orphans: int}
     */
    public function countErrors(): array
    {
        $invalidBounds = (int) $this->connection->table($this->table)
            ->whereColumn($this->lft, '>=', $this->rgt)
            ->count();

        $duplicateLft = (int) $this->connection->table($this->table)
            ->select($this->lft)
            ->groupBy($this->lft)
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $duplicateRgt = (int) $this->connection->table($this->table)
            ->select($this->rgt)
            ->groupBy($this->rgt)
            ->havingRaw('COUNT(*) > 1')
            ->count();

        // Orphan: non-null parent_id references an id that does not exist.
        $tableName = $this->table;
        $orphans = (int) $this->connection->table("{$tableName} as child")
            ->leftJoin("{$tableName} as parent", 'parent.id', '=', "child.{$this->parentId}")
            ->whereNotNull("child.{$this->parentId}")
            ->whereNull('parent.id')
            ->count();

        return [
            'invalid_bounds' => $invalidBounds,
            'duplicate_lft' => $duplicateLft,
            'duplicate_rgt' => $duplicateRgt,
            'orphans' => $orphans,
        ];
    }

    public function getTotalErrors(): int
    {
        return array_sum($this->countErrors());
    }

    public function isBroken(): bool
    {
        return $this->getTotalErrors() > 0;
    }

    // ----------------------------------------------------------------
    // Repair
    // ----------------------------------------------------------------

    /**
     * Rebuilds lft/rgt/depth values for all nodes in the table by
     * walking the tree structure defined by parent_id.
     */
    public function rebuildTree(): void
    {
        // Load all nodes indexed by id.
        $rows = $this->connection->table($this->table)
            ->select(['id', $this->parentId])
            ->get()
            ->keyBy('id');

        // Build children map: parent_id => [child_id, ...]
        /** @var array<int|string, list<int>> $children */
        $children = [];
        $roots = [];

        foreach ($rows as $id => $row) {
            if ($row->{$this->parentId} === null) {
                $roots[] = (int) $id;
            } else {
                $children[(int) $row->{$this->parentId}][] = (int) $id;
            }
        }

        // Walk and assign lft/rgt/depth via DFS.
        $counter = 1;

        /** @var array<int, array{lft: int, rgt: int, depth: int}> $positions */
        $positions = [];

        $walk = static function (int $nodeId, int $d) use (&$walk, &$counter, &$positions, $children): void {
            $positions[$nodeId] = ['lft' => $counter, 'rgt' => 0, 'depth' => $d];
            $counter++;

            foreach ($children[$nodeId] ?? [] as $childId) {
                $walk($childId, $d + 1);
            }

            $positions[$nodeId]['rgt'] = $counter;
            $counter++;
        };

        foreach ($roots as $rootId) {
            $walk($rootId, 0);
        }

        // Persist in a single transaction.
        $this->connection->transaction(function () use ($positions): void {
            foreach ($positions as $id => $pos) {
                $this->connection->table($this->table)
                    ->where('id', $id)
                    ->update([
                        $this->lft => $pos['lft'],
                        $this->rgt => $pos['rgt'],
                        $this->depth => $pos['depth'],
                    ]);
            }
        });
    }

    /**
     * Rebuilds only the subtree rooted at $rootId, without touching
     * other trees in the table (safe for multi-tree / forest tables).
     */
    public function rebuildSubtree(int $rootId): void
    {
        $all = $this->connection->table($this->table)
            ->select(['id', $this->parentId])
            ->get()
            ->keyBy('id');

        // Collect all nodes in the subtree.
        $inSubtree = [];
        $this->collectSubtree($rootId, $all->all(), $inSubtree);

        /** @var array<int|string, list<int>> $children */
        $children = [];

        foreach ($inSubtree as $id) {
            $row = $all[$id] ?? null;

            if ($row === null) {
                continue;
            }

            $pid = $row->{$this->parentId};

            if ($pid !== null && in_array((int) $pid, $inSubtree, true)) {
                $children[(int) $pid][] = $id;
            }
        }

        // Determine the root's current lft (preserve position in the table).
        $rootRow = $this->connection->table($this->table)
            ->select([$this->lft, $this->depth])
            ->where('id', $rootId)
            ->first();

        $startLft = $rootRow !== null ? (int) $rootRow->{$this->lft} : 1;
        $startDepth = $rootRow !== null ? (int) $rootRow->{$this->depth} : 0;

        $counter = $startLft;

        /** @var array<int, array{lft: int, rgt: int, depth: int}> $positions */
        $positions = [];

        $walk = static function (int $nodeId, int $d) use (&$walk, &$counter, &$positions, $children): void {
            $positions[$nodeId] = ['lft' => $counter, 'rgt' => 0, 'depth' => $d];
            $counter++;

            foreach ($children[$nodeId] ?? [] as $childId) {
                $walk($childId, $d + 1);
            }

            $positions[$nodeId]['rgt'] = $counter;
            $counter++;
        };

        $walk($rootId, $startDepth);

        $this->connection->transaction(function () use ($positions): void {
            foreach ($positions as $id => $pos) {
                $this->connection->table($this->table)
                    ->where('id', $id)
                    ->update([
                        $this->lft => $pos['lft'],
                        $this->rgt => $pos['rgt'],
                        $this->depth => $pos['depth'],
                    ]);
            }
        });
    }

    /**
     * Fixes the tree by rebuilding all lft/rgt/depth values and returns
     * a result describing what was corrected.
     */
    public function fixTree(): TreeFixResult
    {
        $this->rebuildTree();

        $nodesUpdated = (int) $this->connection->table($this->table)->count();
        $errorsAfter = $this->countErrors();

        return new TreeFixResult(
            nodesUpdated: $nodesUpdated,
            errors: $errorsAfter,
        );
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * @param  array<int|string, object>  $all
     * @param  list<int>  $result
     */
    private function collectSubtree(int $rootId, array $all, array &$result): void
    {
        $result[] = $rootId;

        foreach ($all as $id => $row) {
            if ((int) ($row->{$this->parentId} ?? -1) === $rootId) {
                $this->collectSubtree((int) $id, $all, $result);
            }
        }
    }
}
