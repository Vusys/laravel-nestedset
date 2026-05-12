<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;

/**
 * Bulk-inserts a tree of nodes in one statement (or a small number of
 * chunks), without going through the per-row appendToNode → makeGap →
 * INSERT cycle. The per-row path costs O(N) per insertion (the
 * makeGap CASE WHEN UPDATE shifts every post-insertion-point row), so
 * inserting N nodes one-by-one is O(N²) — minutes for a one-time seed.
 *
 * Algorithm:
 *
 *   1. Walk the nested input depth-first to count nodes and produce a
 *      flat row list with provisional lft/rgt/depth/parent_id values.
 *      lft/rgt are relative — they'll be offset to their final values
 *      once we know where the subtree goes.
 *
 *   2. Pre-allocate a contiguous block of IDs starting at MAX(id) + 1.
 *      This lets us wire parent_id without round-tripping to the DB to
 *      learn auto-increment values. PostgreSQL's sequence is advanced
 *      at the end via `setval()` so subsequent inserts don't collide.
 *
 *   3. Open a gap at the insertion point if appending under an
 *      existing parent. Skip when seeding fresh roots.
 *
 *   4. Offset every row's lft/rgt by the insertion-point lft and
 *      depth by the parent's depth + 1, then bulk INSERT in chunks.
 *
 * Aggregate maintenance is the caller's responsibility — typically a
 * fixAggregates() pass after the bulk operation.
 *
 * Scope: the caller passes scope-column values inherited from $appendTo
 * (or empty for unscoped models). Every inserted row gets the same
 * scope values; mixing scopes in a single bulkInsertTree call is not
 * supported.
 */
final readonly class TreeBulkInsertBuilder
{
    /**
     * @param  array<string, mixed>  $scope
     */
    public function __construct(
        private Connection $connection,
        private string $table,
        private string $lftCol,
        private string $rgtCol,
        private string $depthCol,
        private string $parentIdCol,
        private string $keyName,
        private array $scope = [],
    ) {}

    /**
     * Inserts a nested tree under the given anchor (or as new roots when
     * `$anchorRgt` is null). Returns the list of inserted ids in DFS
     * pre-order (matching the order of the input).
     *
     * @param  list<array<string, mixed>>  $tree
     * @return list<int>
     */
    public function insertTree(
        array $tree,
        ?int $anchorRgt,
        int $anchorDepth,
        ?int $anchorParentId,
    ): array {
        if ($tree === []) {
            return [];
        }

        // Pre-allocate a contiguous block of IDs starting at MAX(id) + 1.
        // We do this before any INSERTs so children can refer to their
        // parent's id without a round trip.
        $rawMax = $this->scopedTable()->max($this->keyName);
        $startId = (is_numeric($rawMax) ? (int) $rawMax : 0) + 1;

        // Walk the input, assigning ids + relative bounds + parent_id +
        // depth. Produces parallel arrays — keeping each per-row datum
        // in its own list keeps the static-analysis types narrow and
        // sidesteps the array-shape inference that PHPStan widens to
        // `array|int|null` when everything sits in one heterogeneous
        // associative array.
        /** @var list<array<string, mixed>> $userAttrsList */
        $userAttrsList = [];
        /** @var list<int> $idList */
        $idList = [];
        /** @var list<int> $lftList */
        $lftList = [];
        /** @var list<int> $rgtList */
        $rgtList = [];
        /** @var list<int> $depthList */
        $depthList = [];
        /** @var list<int|null> $parentIdList */
        $parentIdList = [];

        $nextId = $startId;
        $nextBound = 1;

        $walker = function (
            array $branches,
            int $depthHere,
            ?int $parentIdHere,
        ) use (
            &$walker,
            &$userAttrsList,
            &$idList,
            &$lftList,
            &$rgtList,
            &$depthList,
            &$parentIdList,
            &$nextId,
            &$nextBound,
        ): void {
            foreach ($branches as $branch) {
                if (! is_array($branch)) {
                    throw new InvalidArgumentException(
                        'bulkInsertTree: every node must be an associative array.',
                    );
                }

                $children = [];
                /** @var array<string, mixed> $attrs */
                $attrs = $branch;

                if (array_key_exists('children', $attrs)) {
                    $rawChildren = $attrs['children'];
                    unset($attrs['children']);
                    if (! is_array($rawChildren)) {
                        throw new InvalidArgumentException(
                            'bulkInsertTree: "children" must be an array.',
                        );
                    }
                    /** @var list<array<string, mixed>> $children */
                    $children = array_values($rawChildren);
                }

                $id = $nextId++;
                $lft = $nextBound++;
                $thisIndex = count($idList);

                $userAttrsList[] = $attrs;
                $idList[] = $id;
                $lftList[] = $lft;
                $depthList[] = $depthHere;
                $parentIdList[] = $parentIdHere;
                $rgtList[] = 0; // placeholder, fixed up after recursion

                $walker($children, $depthHere + 1, $id);

                $rgtList[$thisIndex] = $nextBound++;
            }
        };

        $walker($tree, $anchorDepth, $anchorParentId);

        $totalNodes = count($idList);
        $gapSize = 2 * $totalNodes;

        // Open the gap. If we're inserting fresh roots, anchor lft = next
        // free lft, i.e. (max(rgt) of existing rows) + 1; nothing shifts.
        if ($anchorRgt !== null) {
            $this->makeGap($anchorRgt, $gapSize);
            $boundsOffset = $anchorRgt - 1; // anchor.rgt was = parent.rgt; gap opens at that lft
        } else {
            // No anchor — seeding new roots into the same forest.
            $rawMaxRgt = $this->scopedTable()->max($this->rgtCol);
            $existingMaxRgt = is_numeric($rawMaxRgt) ? (int) $rawMaxRgt : 0;
            $boundsOffset = $existingMaxRgt;
        }

        $finalRows = [];

        foreach ($idList as $i => $id) {
            $userAttrs = $userAttrsList[$i];

            // User-provided lft/rgt/depth/parent_id would be silently
            // overwritten — flag the misuse rather than masking it.
            foreach ([$this->lftCol, $this->rgtCol, $this->depthCol, $this->parentIdCol] as $reserved) {
                if (array_key_exists($reserved, $userAttrs)) {
                    throw new InvalidArgumentException(sprintf(
                        'bulkInsertTree: row attribute "%s" is reserved — the package computes nested-set columns.',
                        $reserved,
                    ));
                }
            }

            if (array_key_exists($this->keyName, $userAttrs)) {
                throw new InvalidArgumentException(sprintf(
                    'bulkInsertTree: row attribute "%s" is reserved — ids are pre-allocated by the package.',
                    $this->keyName,
                ));
            }

            $finalRows[] = $userAttrs + [
                $this->keyName => $id,
                $this->lftCol => $lftList[$i] + $boundsOffset,
                $this->rgtCol => $rgtList[$i] + $boundsOffset,
                $this->depthCol => $depthList[$i],
                $this->parentIdCol => $parentIdList[$i],
                ...$this->scope,
            ];
        }

        $insertedIds = $idList;

        foreach (array_chunk($finalRows, 500) as $chunk) {
            $this->connection->table($this->table)->insert($chunk);
        }

        $this->syncSequence($startId + $totalNodes - 1);

        return $insertedIds;
    }

    /**
     * Single CASE WHEN UPDATE that shifts everything at lft/rgt >= $at
     * upward by $size — same pattern as TreeMutationBuilder::makeGap()
     * but inlined to keep this builder self-contained. (Constructing a
     * TreeMutationBuilder requires the same plumbing; copy is cheap.)
     */
    private function makeGap(int $at, int $size): void
    {
        $this->scopedTable()->update([
            $this->lftCol => new TreeExpression(
                "CASE WHEN {$this->lftCol} >= {$at} THEN {$this->lftCol} + {$size} ELSE {$this->lftCol} END",
            ),
            $this->rgtCol => new TreeExpression(
                "CASE WHEN {$this->rgtCol} >= {$at} THEN {$this->rgtCol} + {$size} ELSE {$this->rgtCol} END",
            ),
        ]);
    }

    /**
     * PostgreSQL's id SEQUENCE is independent of explicit-id inserts.
     * After we hand out ids 1..N ourselves, subsequent default-insert
     * paths (Eloquent::save() on a new node) pull `nextval()` which can
     * collide with our ids. Bump the sequence past our highest hand-out.
     *
     * MySQL/MariaDB auto-increment advances past explicit-id inserts
     * automatically. SQLite uses ROWID, also auto-advancing. Both can
     * skip this step.
     */
    private function syncSequence(int $maxAssignedId): void
    {
        if ($this->connection->getDriverName() !== 'pgsql') {
            return;
        }

        $this->connection->statement(
            'SELECT setval(pg_get_serial_sequence(?, ?), ?)',
            [$this->table, $this->keyName, $maxAssignedId],
        );
    }

    private function scopedTable(): Builder
    {
        $q = $this->connection->table($this->table);

        foreach ($this->scope as $col => $value) {
            $q->where($col, '=', $value);
        }

        return $q;
    }
}
