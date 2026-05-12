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
     * @return array{lft: int, rgt: int, depth: int, parent_id: int|null}
     */
    public function insertNode(int $insertAt, int $newDepth, ?int $newParentId): array
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
     * Moves the subtree rooted at $from so that its lft starts at $targetLft
     * in the final state, adjusting depth by $depthDelta. Uses a single
     * CASE WHEN UPDATE for atomicity — no intermediate gap state on disk.
     *
     * $targetLft is the desired final lft of the subtree root (not a
     * pre-removal position). For a forward move of a leaf from lft=3 to
     * end up at lft=5, pass targetLft=5.
     *
     * depth is updated first in the SET clause to ensure MySQL evaluates it
     * against the original lft value (MySQL processes SET left-to-right with
     * updated values of preceding columns).
     */
    public function moveNode(NodeBounds $from, int $targetLft, int $depthDelta): void
    {
        $size = $from->rgt - $from->lft + 1;
        $fromLft = $from->lft;
        $fromRgt = $from->rgt;

        if ($targetLft === $fromLft && $depthDelta === 0) {
            return;
        }

        if ($targetLft > $fromLft) {
            // Moving forward.
            // Subtree shifts right by (targetLft - fromLft).
            // Nodes in (fromRgt, targetLft + size) fill the gap left by the subtree.
            $subtreeOffset = $targetLft - $fromLft;
            $fillEnd = $targetLft + $size;  // exclusive

            // depth MUST come before lft/rgt so MySQL evaluates it against original lft.
            $this->scoped()->update([
                $this->depth => new TreeExpression("
                    CASE
                        WHEN {$this->lft} BETWEEN {$fromLft} AND {$fromRgt}
                            THEN {$this->depth} + {$depthDelta}
                        ELSE {$this->depth}
                    END
                "),
                $this->lft => new TreeExpression("
                    CASE
                        WHEN {$this->lft} BETWEEN {$fromLft} AND {$fromRgt}
                            THEN {$this->lft} + {$subtreeOffset}
                        WHEN {$this->lft} > {$fromRgt} AND {$this->lft} < {$fillEnd}
                            THEN {$this->lft} - {$size}
                        ELSE {$this->lft}
                    END
                "),
                $this->rgt => new TreeExpression("
                    CASE
                        WHEN {$this->rgt} BETWEEN {$fromLft} AND {$fromRgt}
                            THEN {$this->rgt} + {$subtreeOffset}
                        WHEN {$this->rgt} > {$fromRgt} AND {$this->rgt} < {$fillEnd}
                            THEN {$this->rgt} - {$size}
                        ELSE {$this->rgt}
                    END
                "),
            ]);
        } else {
            // Moving backward.
            // Subtree shifts left by (targetLft - fromLft) — a negative offset.
            // Nodes in [targetLft, fromLft) shift right by $size to fill the vacated space.
            $subtreeOffset = $targetLft - $fromLft;

            $this->scoped()->update([
                $this->depth => new TreeExpression("
                    CASE
                        WHEN {$this->lft} BETWEEN {$fromLft} AND {$fromRgt}
                            THEN {$this->depth} + {$depthDelta}
                        ELSE {$this->depth}
                    END
                "),
                $this->lft => new TreeExpression("
                    CASE
                        WHEN {$this->lft} BETWEEN {$fromLft} AND {$fromRgt}
                            THEN {$this->lft} + {$subtreeOffset}
                        WHEN {$this->lft} >= {$targetLft} AND {$this->lft} < {$fromLft}
                            THEN {$this->lft} + {$size}
                        ELSE {$this->lft}
                    END
                "),
                $this->rgt => new TreeExpression("
                    CASE
                        WHEN {$this->rgt} BETWEEN {$fromLft} AND {$fromRgt}
                            THEN {$this->rgt} + {$subtreeOffset}
                        WHEN {$this->rgt} >= {$targetLft} AND {$this->rgt} < {$fromLft}
                            THEN {$this->rgt} + {$size}
                        ELSE {$this->rgt}
                    END
                "),
            ]);
        }
    }

    // ----------------------------------------------------------------
    // Node data retrieval
    // ----------------------------------------------------------------

    /**
     * @return array{lft: int, rgt: int, depth: int, parent_id: int|null}
     */
    public function getPlainNodeData(int $id): array
    {
        $row = $this->connection->table($this->table)
            ->select([$this->lft, $this->rgt, $this->parentId, $this->depth])
            ->where('id', $id)
            ->first();

        if ($row === null) {
            throw new RuntimeException("Node {$id} not found.");
        }

        return [
            'lft' => (int) $row->{$this->lft},
            'rgt' => (int) $row->{$this->rgt},
            'depth' => (int) $row->{$this->depth},
            'parent_id' => $row->{$this->parentId} !== null
                ? (int) $row->{$this->parentId}
                : null,
        ];
    }

    public function getNodeData(int $id): NodeBounds
    {
        $data = $this->getPlainNodeData($id);

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
