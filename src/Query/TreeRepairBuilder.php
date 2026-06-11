<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Vusys\NestedSet\Exceptions\UnplacedNodeException;
use Vusys\NestedSet\Query\Aggregates\Maintenance\AggregateDiffer;
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
     * @return array{invalid_bounds: int, duplicate_lft: int, duplicate_rgt: int, orphans: int, parent_bounds_mismatch: int, depth_mismatch: int, bounds_out_of_range: int}
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

        // The bounds disagree with parent_id (the source of truth): a
        // child whose bounds are not strictly inside its (existing)
        // parent's bounds. This is the exact raw-edit corruption that
        // leaves lft/rgt internally consistent but contradicting the
        // parent walk — invisible to the bounds-only checks above.
        $parentBoundsMismatch = (int) $this->parentBoundsMismatchQuery()->count();

        // depth drift: a child whose stored depth isn't parent.depth + 1,
        // or a root whose depth isn't 0. Orphans are excluded (their
        // parent is missing — counted above).
        $depthMismatch = (int) $this->depthMismatchQuery()->count();

        // Broken 1..2N permutation: a placed row whose lft/rgt falls
        // outside the valid range for the scope's placed-row count. Catches
        // cross-column collisions and partially-placed rows (e.g. X(0,1))
        // that slip past `lft >= rgt` and the per-column duplicate checks.
        $boundsOutOfRange = $this->countBoundsOutOfRange();

        return [
            'invalid_bounds' => $invalidBounds,
            'duplicate_lft' => $duplicateLft,
            'duplicate_rgt' => $duplicateRgt,
            'orphans' => $orphans,
            'parent_bounds_mismatch' => $parentBoundsMismatch,
            'depth_mismatch' => $depthMismatch,
            'bounds_out_of_range' => $boundsOutOfRange,
        ];
    }

    /**
     * Child rows whose bounds are not strictly inside their (existing)
     * parent's bounds. Inner join on parent_id + scope, so orphans (no
     * matching parent) are excluded — they're reported separately.
     */
    private function parentBoundsMismatchQuery(): Builder
    {
        $scopeColumns = array_keys($this->scope);

        $query = $this->connection->table("{$this->table} as child")
            ->join("{$this->table} as parent", function ($join) use ($scopeColumns): void {
                $join->on("parent.{$this->idCol}", '=', "child.{$this->parentId}");
                foreach ($scopeColumns as $column) {
                    $join->on("parent.{$column}", '=', "child.{$column}");
                }
            })
            ->whereNotNull("child.{$this->parentId}")
            ->where(function ($q): void {
                $q->whereColumn("child.{$this->lft}", '<=', "parent.{$this->lft}")
                    ->orWhereColumn("child.{$this->rgt}", '>=', "parent.{$this->rgt}");
            });

        foreach ($this->scope as $column => $value) {
            $query->where("child.{$column}", '=', $value);
        }

        return $query;
    }

    /**
     * Rows whose stored depth disagrees with the structure: a root
     * (parent_id IS NULL) not at depth 0, or a child not at
     * parent.depth + 1. Orphans fall through the left join (parent id is
     * null but parent_id is not) and are excluded.
     */
    private function depthMismatchQuery(): Builder
    {
        $scopeColumns = array_keys($this->scope);

        $query = $this->connection->table("{$this->table} as child")
            ->leftJoin("{$this->table} as parent", function ($join) use ($scopeColumns): void {
                $join->on("parent.{$this->idCol}", '=', "child.{$this->parentId}");
                foreach ($scopeColumns as $column) {
                    $join->on("parent.{$column}", '=', "child.{$column}");
                }
            })
            ->where(function ($q): void {
                $q->where(function ($r): void {
                    $r->whereNull("child.{$this->parentId}")
                        ->where("child.{$this->depth}", '!=', 0);
                })->orWhere(function ($r): void {
                    $r->whereNotNull("parent.{$this->idCol}")
                        ->whereRaw("child.{$this->depth} <> parent.{$this->depth} + 1");
                });
            });

        foreach ($this->scope as $column => $value) {
            $query->where("child.{$column}", '=', $value);
        }

        return $query;
    }

    /**
     * Counts rows whose bounds break the `1..2N` permutation in a
     * gap-tolerant way:
     *
     *  - a placed row with a bound below 1 (e.g. a stray `0`), and
     *  - cross-column collisions: a value that is simultaneously some
     *    row's lft and another row's rgt.
     *
     * In a valid nested set every lft/rgt value is distinct and >= 1, so
     * the lft and rgt value sets are disjoint — even for a sparse (gapped
     * but otherwise valid) tree, which a strict `max(rgt) == 2N` density
     * check would wrongly flag. Fully-unplaced rows (lft = rgt = 0) are a
     * legitimate transient state and are excluded.
     */
    private function countBoundsOutOfRange(): int
    {
        $belowRange = (int) $this->scoped()
            ->where(function ($q): void {
                // Exclude fully-unplaced rows (lft = rgt = 0).
                $q->where($this->lft, '!=', 0)->orWhere($this->rgt, '!=', 0);
            })
            ->where(function ($q): void {
                $q->where($this->lft, '<', 1)->orWhere($this->rgt, '<', 1);
            })
            ->count();

        return $belowRange + (int) $this->crossColumnCollisionQuery()->count();
    }

    /**
     * Pairs of distinct rows in the same scope where one row's lft equals
     * another's rgt — impossible in a valid nested set, where the two
     * value sets are disjoint. Restricted to lft >= 1 so unplaced rows
     * (lft = 0) don't self-collide.
     */
    private function crossColumnCollisionQuery(): Builder
    {
        $scopeColumns = array_keys($this->scope);

        $query = $this->connection->table("{$this->table} as a")
            ->join("{$this->table} as b", function ($join) use ($scopeColumns): void {
                $join->on("a.{$this->lft}", '=', "b.{$this->rgt}");
                $join->on("a.{$this->idCol}", '!=', "b.{$this->idCol}");
                foreach ($scopeColumns as $column) {
                    $join->on("a.{$column}", '=', "b.{$column}");
                }
            })
            ->where("a.{$this->lft}", '>=', 1);

        foreach ($this->scope as $column => $value) {
            $query->where("a.{$column}", '=', $value);
        }

        return $query;
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
    public function rebuildTree(): int
    {
        $rows = $this->scoped()
            ->select([$this->idCol, $this->parentId])
            // Order by (lft, id): lft preserves whatever deliberate sibling
            // order the tree already has (insertBeforeNode/up()/reorder),
            // so fixTree() on a healthy tree is order-preserving rather than
            // reverting everything to PK order. The id tiebreak keeps it
            // deterministic across backends (PG heap order is unstable) and
            // makes garbage/zero lft values degrade gracefully to id order.
            ->orderBy($this->lft)
            ->orderBy($this->idCol)
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

        return count($positions);
    }

    /**
     * Rebuilds only the subtree rooted at $rootId, without touching
     * other trees in the table (safe for multi-tree / forest tables).
     *
     * If the subtree's row count (from parent_id) no longer matches the
     * band already reserved between the root's lft and rgt — e.g. because
     * descendants were added or removed via parent_id without the matching
     * makeGap/closeGap — surrounding rows are shifted by the size delta
     * before the new positions are written. Without the shift the rebuilt
     * subtree's tail would overlap whichever sibling sits at rgt + 1.
     */
    public function rebuildSubtree(int|string $rootId): int
    {
        $all = $this->scoped()
            ->select([$this->idCol, $this->parentId])
            // (lft, id) order: preserve deliberate sibling order on a healthy
            // subtree, id-tiebreak for determinism and garbage-lft fallback.
            ->orderBy($this->lft)
            ->orderBy($this->idCol)
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
            ->select([$this->lft, $this->rgt, $this->depth])
            ->where($this->idCol, $rootId)
            ->first();

        // Missing-anchor guard: an anchored fixTree() whose anchor row is
        // gone (hard delete, scope move) must not fall through to a
        // startLft=1 rebuild — that writes the orphaned subtree over lft 1,
        // colliding with the live root and *creating* corruption. Mirrors
        // AggregateRepair::fixAggregatesChunk, which refuses the same case.
        if ($rootRow === null) {
            throw new \RuntimeException(sprintf(
                'fixTree: anchor id %s not found — was the row deleted? '
                .'Refusing to rebuild its subtree over lft 1 and corrupt the live tree. '
                .'Run an unanchored fixTree() to rebuild from the roots.',
                (string) $rootId,
            ));
        }

        $startLft = (int) $rootRow->{$this->lft};
        $startDepth = (int) $rootRow->{$this->depth};
        $reservedRgt = (int) $rootRow->{$this->rgt};

        $newSize = count($inSubtree) * 2;
        $reservedSize = $reservedRgt - $startLft + 1;

        // An unplaced anchor (lft = 0, or any non-positive lft) has no
        // valid startLft to rebuild from — using its lft would write
        // bounds starting at 0 that collide with the real root. A
        // placed-but-corrupt anchor (lft >= 1, bad rgt) is still a valid
        // repair target: the rebuild writes from its real lft.
        if ($startLft < 1) {
            throw new UnplacedNodeException(sprintf(
                'Cannot fixTree() anchored at an unplaced node (id=%s, lft=%d, rgt=%d). '
                .'Place it in a tree first, or run an unanchored fixTree() to rebuild from the roots.',
                (string) $rootId,
                $startLft,
                $reservedRgt,
            ));
        }

        // Only shift surroundings when the root has a real position
        // (band >= 2). A corrupt-but-placed anchor (band < 2) skips the
        // shift and just rewrites its own subtree from startLft.
        $delta = $reservedSize >= 2 ? $newSize - $reservedSize : 0;

        $positions = $this->walkAssignPositions([$rootId], $children, $startLft, $startDepth);

        $this->connection->transaction(function () use ($positions, $delta, $reservedRgt): void {
            if ($delta !== 0) {
                $mutator = new TreeMutationBuilder(
                    connection: $this->connection,
                    table: $this->table,
                    lft: $this->lft,
                    rgt: $this->rgt,
                    parentId: $this->parentId,
                    depth: $this->depth,
                    scope: $this->scope,
                    idCol: $this->idCol,
                );

                if ($delta > 0) {
                    $mutator->makeGap($reservedRgt + 1, $delta);
                } else {
                    $mutator->closeGap($reservedRgt, -$delta);
                }
            }

            $this->bulkWritePositions($positions);
        });

        return count($positions);
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

            // Skip a node already entered on this walk. The children map is
            // built from parent_id, which can contain a cycle (A⇄B) even
            // after collectSubtree's visited guard de-dupes membership — the
            // back-edge survives as a child entry and would otherwise spin
            // here forever. Dropping the re-entry yields a valid nesting.
            if (isset($positions[$task['id']])) {
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
     * Same pattern the aggregate-repair path uses (see
     * {@see AggregateDiffer}); the only
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
        $nodesUpdated = $rootId !== null
            ? $this->rebuildSubtree($rootId)
            : $this->rebuildTree();

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

        $result = [];
        $queue = [$rootId];
        /** @var array<int|string, true> $visited */
        $visited = [];

        while ($queue !== []) {
            $id = array_pop($queue);
            // A parent_id cycle (e.g. A⇄B) would otherwise re-enqueue the
            // same ids forever and OOM. fixTree is the documented recovery
            // for cycles and scoped models can only call it anchored, so it
            // must survive exactly this corruption. The visited guard turns
            // the walk into a spanning tree — back-edges are dropped.
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $result[] = $id;

            // Push reversed so the LIFO pops children in their natural
            // (id) order — keeps sibling ordering identical to the prior
            // implementation, which the rebuilt lft/rgt depend on.
            foreach (array_reverse($childrenByParent[$id] ?? []) as $childId) {
                $queue[] = $childId;
            }
        }

        return $result;
    }
}
