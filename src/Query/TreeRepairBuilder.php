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
        private string $idCol = 'id',
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
            ->select([$this->idCol, $this->parentId])
            ->get()
            ->keyBy($this->idCol);

        /** @var array<int|string, list<int|string>> $children */
        $children = [];
        /** @var list<int|string> $roots */
        $roots = [];

        foreach ($rows as $id => $row) {
            $pid = $row->{$this->parentId};
            if ($pid === null) {
                $roots[] = $id;
            } else {
                $children[$pid][] = $id;
            }
        }

        $positions = $this->walkAssignPositions($roots, $children, startLft: 1, startDepth: 0);

        $this->connection->transaction(function () use ($positions): void {
            $this->bulkWritePositions($positions);
        });
    }

    /**
     * Rebuilds only the subtree rooted at $rootId, without touching
     * other trees in the table (safe for multi-tree / forest tables).
     */
    public function rebuildSubtree(int|string $rootId): void
    {
        $all = $this->scoped()
            ->select([$this->idCol, $this->parentId])
            ->get()
            ->keyBy($this->idCol);

        $inSubtree = $this->collectSubtree($rootId, $all->all());

        /** @var array<int|string, list<int|string>> $children */
        $children = [];
        $inSubtreeSet = array_flip($inSubtree);

        foreach ($inSubtree as $id) {
            $row = $all[$id] ?? null;

            if ($row === null) {
                continue;
            }

            $pid = $row->{$this->parentId};

            if ($pid !== null && isset($inSubtreeSet[$pid])) {
                $children[$pid][] = $id;
            }
        }

        $rootRow = $this->scoped()
            ->select([$this->lft, $this->depth])
            ->where($this->idCol, $rootId)
            ->first();

        $startLft = $rootRow !== null ? (int) $rootRow->{$this->lft} : 1;
        $startDepth = $rootRow !== null ? (int) $rootRow->{$this->depth} : 0;

        $positions = $this->walkAssignPositions([$rootId], $children, $startLft, $startDepth);

        $this->connection->transaction(function () use ($positions): void {
            $this->bulkWritePositions($positions);
        });
    }

    /**
     * Iterative DFS that assigns sequential lft/rgt/depth coordinates
     * to every node reachable from `$roots` via `$children`. The
     * iterative-stack shape (mirrored from {@see HasBulkInsert::bulkInsertPlan})
     * keeps fixTree on a deeply-chained corrupted tree from blowing
     * PHP's call stack — recursion bottomed out around xdebug's 256
     * default and PHP's ~10K frame ceiling, both reachable in real
     * "tall and skinny" corruption shapes.
     *
     * @param  list<int|string>  $roots
     * @param  array<int|string, list<int|string>>  $children
     * @return array<int|string, array{lft: int, rgt: int, depth: int}>
     */
    private function walkAssignPositions(array $roots, array $children, int $startLft, int $startDepth): array
    {
        /** @var array<int|string, array{lft: int, rgt: int, depth: int}> $positions */
        $positions = [];
        $counter = $startLft;

        /** @var list<array{type: 'enter', id: int|string, depth: int}|array{type: 'exit', id: int|string}> $tasks */
        $tasks = [];
        foreach (array_reverse($roots) as $rootId) {
            $tasks[] = ['type' => 'enter', 'id' => $rootId, 'depth' => $startDepth];
        }

        while ($tasks !== []) {
            $task = array_pop($tasks);

            if ($task['type'] === 'exit') {
                $entry = $positions[$task['id']];
                $entry['rgt'] = $counter++;
                $positions[$task['id']] = $entry;

                continue;
            }

            $positions[$task['id']] = ['lft' => $counter++, 'rgt' => 0, 'depth' => $task['depth']];

            // Queue exit before children so it pops after every descendant.
            $tasks[] = ['type' => 'exit', 'id' => $task['id']];

            // Push children reversed so the first child pops first — same
            // visit order as the previous recursive implementation.
            foreach (array_reverse($children[$task['id']] ?? []) as $childId) {
                $tasks[] = ['type' => 'enter', 'id' => $childId, 'depth' => $task['depth'] + 1];
            }
        }

        return $positions;
    }

    /**
     * Writes the rebuilt lft/rgt/depth values as chunked bulk UPDATEs
     * — one `UPDATE … SET col = CASE id WHEN … END WHERE id IN (…)`
     * per chunk, three CASE expressions (lft / rgt / depth) per
     * statement. Replaces the per-row UPDATE loop this method used
     * to run, which was the dominant cost on large rebuilds —
     * 10K rows = 10K round-trips, multi-second wall-clock on every
     * backend. With chunkSize=500 a 10K rebuild becomes ~20 UPDATEs.
     *
     * Same pattern Phase Q applied to TreeAggregateBuilder; the only
     * difference is the columns being CASE-d.
     *
     * @param  array<int|string, array{lft: int, rgt: int, depth: int}>  $positions
     * @param  int<1, max>  $chunkSize
     */
    private function bulkWritePositions(array $positions, int $chunkSize = 500): void
    {
        if ($positions === []) {
            return;
        }

        /** @var list<int|string> $ids */
        $ids = array_keys($positions);

        foreach (array_chunk($ids, $chunkSize) as $idChunk) {
            $lftCase = "CASE {$this->idCol}";
            $rgtCase = "CASE {$this->idCol}";
            $depthCase = "CASE {$this->idCol}";
            $lftBindings = [];
            $rgtBindings = [];
            $depthBindings = [];

            foreach ($idChunk as $id) {
                $pos = $positions[$id];
                $lftCase .= ' WHEN ? THEN ?';
                $rgtCase .= ' WHEN ? THEN ?';
                $depthCase .= ' WHEN ? THEN ?';
                $lftBindings[] = $id;
                $lftBindings[] = $pos['lft'];
                $rgtBindings[] = $id;
                $rgtBindings[] = $pos['rgt'];
                $depthBindings[] = $id;
                $depthBindings[] = $pos['depth'];
            }
            $lftCase .= " ELSE {$this->lft} END";
            $rgtCase .= " ELSE {$this->rgt} END";
            $depthCase .= " ELSE {$this->depth} END";

            $idPlaceholders = implode(', ', array_fill(0, count($idChunk), '?'));
            $idBindings = $idChunk;

            // Scope predicates (`tenant_id = ?` etc.) live alongside the
            // id-IN list — keeps the update inside the scope this
            // builder was constructed for.
            $scopeClause = '';
            $scopeBindings = [];
            foreach ($this->scope as $col => $value) {
                $scopeClause .= " AND {$col} = ?";
                $scopeBindings[] = $value;
            }

            $sql = "UPDATE {$this->table} "
                ."SET {$this->lft} = ({$lftCase}), "
                ."{$this->rgt} = ({$rgtCase}), "
                ."{$this->depth} = ({$depthCase}) "
                ."WHERE {$this->idCol} IN ({$idPlaceholders}){$scopeClause}";

            $bindings = [
                ...$lftBindings,
                ...$rgtBindings,
                ...$depthBindings,
                ...$idBindings,
                ...$scopeBindings,
            ];

            $this->connection->update($sql, $bindings);
        }
    }

    /**
     * Fixes the tree by rebuilding all lft/rgt/depth values and returns
     * a result describing what was corrected.
     */
    public function fixTree(int|string|null $rootId = null): TreeFixResult
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
        $scopeColumns = array_keys($this->scope);

        // The JOIN itself must equate scope columns across child and
        // parent — otherwise a child whose parent_id matches a row in
        // a DIFFERENT scope would join successfully, mask the orphan,
        // and let countErrors() under-report corruption.
        $query = $this->connection->table("{$tableName} as child")
            ->leftJoin("{$tableName} as parent", function ($join) use ($scopeColumns): void {
                $join->on("parent.{$this->idCol}", '=', "child.{$this->parentId}");
                foreach ($scopeColumns as $column) {
                    $join->on("parent.{$column}", '=', "child.{$column}");
                }
            })
            ->whereNotNull("child.{$this->parentId}")
            ->whereNull("parent.{$this->idCol}");

        // Restrict the outer (child) side to this scope so the count
        // only includes orphans in the same partition the caller asked
        // to repair.
        foreach ($this->scope as $column => $value) {
            $query->where("child.{$column}", '=', $value);
        }

        return $query;
    }

    /**
     * Returns every id reachable from $rootId by walking parent_id pointers
     * in $all. Iterative BFS so a deep chain of ids doesn't recurse.
     *
     * PK values are passed through as-is — PHP's array-key coercion folds
     * numeric strings to int automatically, so int-keyed models (whose
     * driver may surface ids as strings) and string-keyed models (UUID/
     * ULID) both index correctly in `$childrenByParent`.
     *
     * @param  array<int|string, object>  $all
     * @return list<int|string>
     */
    private function collectSubtree(int|string $rootId, array $all): array
    {
        /** @var array<int|string, list<int|string>> $childrenByParent */
        $childrenByParent = [];
        foreach ($all as $id => $row) {
            $pid = $row->{$this->parentId} ?? null;
            if ($pid !== null) {
                $childrenByParent[$pid][] = $id;
            }
        }

        $result = [$rootId];
        $queue = [$rootId];

        while ($queue !== []) {
            $id = array_pop($queue);
            foreach ($childrenByParent[$id] ?? [] as $childId) {
                $result[] = $childId;
                $queue[] = $childId;
            }
        }

        return $result;
    }
}
