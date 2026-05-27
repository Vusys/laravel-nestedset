<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use RuntimeException;
use Vusys\NestedSet\NodeBounds;

/**
 * Performs atomic tree mutations against a single table.
 *
 * All multi-step operations use a single CASE WHEN UPDATE where possible
 * to avoid intermediate states that violate the nested-set invariant.
 *
 * Scope: when $scope is non-empty, every internal write is constrained
 * to rows matching those [column => value] pairs. Read methods
 * (getPlainNodeData/getNodeData) intentionally ignore scope — callers
 * pass an id that uniquely identifies a row and are responsible for
 * picking the right model context.
 */
final readonly class TreeMutationBuilder
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
    // Gap management
    // ----------------------------------------------------------------

    /**
     * Opens a gap of $size at position $at, shifting all nodes whose
     * lft or rgt >= $at upward by $size.
     */
    public function makeGap(int $at, int $size): void
    {
        $this->scoped()->update([
            $this->lft => new TreeExpression(
                "CASE WHEN {$this->lft} >= {$at} THEN {$this->lft} + {$size} ELSE {$this->lft} END"
            ),
            $this->rgt => new TreeExpression(
                "CASE WHEN {$this->rgt} >= {$at} THEN {$this->rgt} + {$size} ELSE {$this->rgt} END"
            ),
        ]);
    }

    /**
     * Closes a gap of $size at position $at, shifting all nodes whose
     * lft or rgt > $at downward by $size.
     */
    public function closeGap(int $at, int $size): void
    {
        $this->scoped()->update([
            $this->lft => new TreeExpression(
                "CASE WHEN {$this->lft} > {$at} THEN {$this->lft} - {$size} ELSE {$this->lft} END"
            ),
            $this->rgt => new TreeExpression(
                "CASE WHEN {$this->rgt} > {$at} THEN {$this->rgt} - {$size} ELSE {$this->rgt} END"
            ),
        ]);
    }

    // ----------------------------------------------------------------
    // Node insertion
    // ----------------------------------------------------------------

    /**
     * Returns the position data for a new leaf node inserted at $insertAt.
     * Callers must call makeGap() first to create room, then set these
     * values on the new model before saving.
     *
     * @return array{lft: int, rgt: int, depth: int, parent_id: int|string|null}
     */
    public function insertNode(int $insertAt, int $newDepth, int|string|null $newParentId): array
    {
        return [
            'lft' => $insertAt,
            'rgt' => $insertAt + 1,
            'depth' => $newDepth,
            'parent_id' => $newParentId,
        ];
    }

    // ----------------------------------------------------------------
    // Node movement
    // ----------------------------------------------------------------

    /**
     * Moves the subtree rooted at $from to $position in the *original*
     * coordinate space, adjusting depth by $depthDelta. Single CASE WHEN
     * UPDATE — no intermediate state visible on disk.
     *
     * $position is the lft value the subtree would land at if no other
     * rows shifted; pass parent->rgt for appendToNode, sibling->rgt + 1
     * for insertAfterNode, sibling->lft for insertBeforeNode.
     *
     * Algorithm credit: the kalnoy/nestedset move-with-CASE-WHEN trick
     * (compute the [from, to] band that all moves stay within, then split
     * "the moved subtree" from "everything else in the band").
     *
     * depth is first in the SET clause so MySQL evaluates it against the
     * pre-update lft (left-to-right SET semantics).
     */
    public function moveNode(NodeBounds $from, int $position, int $depthDelta): void
    {
        $lft = $from->lft;
        $rgt = $from->rgt;
        $height = $rgt - $lft + 1;

        if ($lft < $position && $position <= $rgt) {
            throw new \LogicException('Cannot move node into itself.');
        }

        $boundFrom = min($lft, $position);
        $boundTo = max($rgt, $position - 1);

        $distance = $boundTo - $boundFrom + 1 - $height;

        if ($distance === 0 && $depthDelta === 0) {
            return;
        }

        // Subtree shifts by ±distance; bystanders shift by ∓height (filling
        // or making room as the subtree moves through them).
        if ($position > $lft) {
            $subtreeShift = $distance;        // forward
            $bystanderShift = -$height;
        } else {
            $subtreeShift = -$distance;       // backward
            $bystanderShift = $height;
        }

        $this->scoped()->update([
            $this->depth => new TreeExpression(
                "CASE WHEN {$this->lft} BETWEEN {$lft} AND {$rgt} "
                ."THEN {$this->depth} + {$depthDelta} "
                ."ELSE {$this->depth} END"
            ),
            $this->lft => new TreeExpression(
                $this->shiftCase($this->lft, $lft, $rgt, $boundFrom, $boundTo, $subtreeShift, $bystanderShift),
            ),
            $this->rgt => new TreeExpression(
                $this->shiftCase($this->rgt, $lft, $rgt, $boundFrom, $boundTo, $subtreeShift, $bystanderShift),
            ),
        ]);
    }

    private function shiftCase(
        string $col,
        int $lft,
        int $rgt,
        int $boundFrom,
        int $boundTo,
        int $subtreeShift,
        int $bystanderShift,
    ): string {
        $subtree = $subtreeShift >= 0 ? "+ {$subtreeShift}" : '- '.abs($subtreeShift);
        $bystander = $bystanderShift >= 0 ? "+ {$bystanderShift}" : '- '.abs($bystanderShift);

        return 'CASE '
            ."WHEN {$col} BETWEEN {$lft} AND {$rgt} THEN {$col} {$subtree} "
            ."WHEN {$col} BETWEEN {$boundFrom} AND {$boundTo} THEN {$col} {$bystander} "
            ."ELSE {$col} END";
    }

    // ----------------------------------------------------------------
    // Node data retrieval
    // ----------------------------------------------------------------

    /**
     * @param  bool  $lockForUpdate  When true and the backend supports
     *                               row locking, the SELECT acquires a
     *                               FOR UPDATE lock that serialises
     *                               concurrent readers of the same row.
     *                               Required for the parent/sibling read
     *                               in appendToNode / prependToNode /
     *                               insertBefore / insertAfter — without
     *                               it, two concurrent appenders read the
     *                               same parent.rgt and insert at the
     *                               same slot, producing duplicate-lft
     *                               corruption. SQLite is single-writer
     *                               so the flag is a no-op there.
     * @return array{lft: int, rgt: int, depth: int, parent_id: int|string|null}
     */
    public function getPlainNodeData(int|string $id, bool $lockForUpdate = false): array
    {
        $query = $this->connection->table($this->table)
            ->select([$this->lft, $this->rgt, $this->parentId, $this->depth])
            ->where($this->idCol, $id);

        if ($lockForUpdate && $this->connection->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $row = $query->first();

        if ($row === null) {
            throw new RuntimeException("Node {$id} not found.");
        }

        $parentId = $row->{$this->parentId} ?? null;
        if ($parentId !== null && ! is_int($parentId) && ! is_string($parentId)) {
            $parentId = (string) $parentId;
        }

        return [
            'lft' => (int) $row->{$this->lft},
            'rgt' => (int) $row->{$this->rgt},
            'depth' => (int) $row->{$this->depth},
            'parent_id' => $parentId,
        ];
    }

    public function getNodeData(int|string $id, bool $lockForUpdate = false): NodeBounds
    {
        $data = $this->getPlainNodeData($id, $lockForUpdate);

        return new NodeBounds(
            lft: $data['lft'],
            rgt: $data['rgt'],
            depth: $data['depth'],
        );
    }

    /**
     * Returns a fresh query builder against the target table, pre-constrained
     * to this builder's scope. Every mutation goes through here so cross-scope
     * leakage is impossible.
     */
    private function scoped(): Builder
    {
        $query = $this->connection->table($this->table);

        foreach ($this->scope as $column => $value) {
            $query->where($column, '=', $value);
        }

        return $query;
    }
}
