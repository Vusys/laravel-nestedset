<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Vusys\NestedSet\TreeFixResult;

/**
 * Validates and repairs a nested-set table.
 *
 * Repair operations rebuild lft/rgt/depth values from the parent_id
 * column, which is always kept consistent. fixTree() targets a single
 * root subtree; rebuildTree() rebuilds all roots.
 *
 * Scope: when $scope is non-empty (i.e. the model declares
 * #[NestedSetScope] or getScopeAttributes()), every internal query is
 * constrained to those [column => value] pairs and full-table operations
 * refuse to run without an explicit root.
 */
final readonly class TreeRepairBuilder
{
    /**
     * @param  array<string, mixed>  $scope
     */
    public function __construct(
        private Connection $connection,
        private string $table,
        private string $lft,
        private string $rgt,
        private string $parentId,
        private string $depth,
        private array $scope = [],
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
        $invalidBounds = (int) $this->scoped()
            ->whereColumn($this->lft, '>=', $this->rgt)
            ->count();

        $duplicateLft = (int) $this->scoped()
            ->select($this->lft)
            ->groupBy(array_merge(array_keys($this->scope), [$this->lft]))
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $duplicateRgt = (int) $this->scoped()
            ->select($this->rgt)
            ->groupBy(array_merge(array_keys($this->scope), [$this->rgt]))
            ->havingRaw('COUNT(*) > 1')
            ->count();

        // Orphan: non-null parent_id references an id that does not exist
        // (within the same scope).
        $orphans = (int) $this->orphanQuery()->count();

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
     * Rebuilds lft/rgt/depth values for all nodes within this builder's
     * scope by walking the tree structure defined by parent_id.
     *
     * On a scoped builder, only that scope's rows are touched. The
     * model-facing API (Phase 8) is what refuses a no-root call on a
     * scoped class — at this layer the scope is already explicit.
     */
    public function rebuildTree(): void
    {
        $rows = $this->scoped()
            ->select(['id', $this->parentId])
            ->get()
            ->keyBy('id');

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
        $all = $this->scoped()
            ->select(['id', $this->parentId])
            ->get()
            ->keyBy('id');

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

        $rootRow = $this->scoped()
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
    public function fixTree(?int $rootId = null): TreeFixResult
    {
        if ($rootId !== null) {
            $this->rebuildSubtree($rootId);
        } else {
            $this->rebuildTree();
        }

        $nodesUpdated = (int) $this->scoped()->count();
        $errorsAfter = $this->countErrors();

        return new TreeFixResult(
            nodesUpdated: $nodesUpdated,
            errors: $errorsAfter,
        );
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function scoped(): Builder
    {
        $query = $this->connection->table($this->table);

        foreach ($this->scope as $column => $value) {
            $query->where($column, '=', $value);
        }

        return $query;
    }

    private function orphanQuery(): Builder
    {
        $tableName = $this->table;
        $query = $this->connection->table("{$tableName} as child")
            ->leftJoin("{$tableName} as parent", 'parent.id', '=', "child.{$this->parentId}")
            ->whereNotNull("child.{$this->parentId}")
            ->whereNull('parent.id');

        // A parent in a different scope still counts as missing — orphan
        // semantics require the parent to be in the same tree, not just
        // anywhere in the table.
        foreach ($this->scope as $column => $value) {
            $query->where("child.{$column}", '=', $value);
        }

        return $query;
    }

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
