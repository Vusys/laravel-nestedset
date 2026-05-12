<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\PendingOperation;
use Vusys\NestedSet\Position;
use Vusys\NestedSet\Query\TreeMutationBuilder;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Mutating tree API on the model: appendToNode/prependToNode/
 * insertBeforeNode/insertAfterNode/makeRoot/up/down.
 *
 * Each call records a {@see PendingOperation} on the instance and returns
 * $this — the actual write is deferred to the next save() (and dispatched
 * from the `saving` event hooked in NodeTrait::bootNodeTrait).
 *
 * @mixin Model
 * @mixin HasNestedSet
 */
trait HasTreeMutation
{
    protected ?PendingOperation $vusysPending = null;

    public function appendToNode(HasNestedSet $parent): static
    {
        $this->vusysPending = new PendingOperation('appendTo', $parent);

        return $this;
    }

    public function prependToNode(HasNestedSet $parent): static
    {
        $this->vusysPending = new PendingOperation('prependTo', $parent);

        return $this;
    }

    public function insertBeforeNode(HasNestedSet $sibling): static
    {
        $this->vusysPending = new PendingOperation('sibling', $sibling, Position::Before);

        return $this;
    }

    public function insertAfterNode(HasNestedSet $sibling): static
    {
        $this->vusysPending = new PendingOperation('sibling', $sibling, Position::After);

        return $this;
    }

    public function makeRoot(): static
    {
        $this->vusysPending = new PendingOperation('root');

        return $this;
    }

    public function saveAsRoot(): bool
    {
        return $this->makeRoot()->save();
    }

    /**
     * Move this node one position up among its siblings (toward smaller lft).
     */
    public function up(): bool
    {
        $sibling = $this->prevSibling();

        if ($sibling === null) {
            return false;
        }

        return $this->insertBeforeNode($sibling)->save();
    }

    /**
     * Move this node one position down among its siblings (toward larger lft).
     */
    public function down(): bool
    {
        $sibling = $this->nextSibling();

        if ($sibling === null) {
            return false;
        }

        return $this->insertAfterNode($sibling)->save();
    }

    /**
     * Dispatches whatever the user queued via appendToNode/etc. Called from
     * the model's `saving` event in NodeTrait::bootNodeTrait.
     *
     * @internal
     */
    public function callPendingAction(): void
    {
        if ($this->vusysPending === null) {
            return;
        }

        $op = $this->vusysPending;
        $this->vusysPending = null;

        if ($op->node !== null) {
            $target = $this->requireModelNode($op);
            NestedSetScopeResolver::assertSameScope($this, $target);
        }

        match ($op->action) {
            'appendTo' => $this->actAppendTo($this->requireModelNode($op)),
            'prependTo' => $this->actPrependTo($this->requireModelNode($op)),
            'sibling' => $this->actSibling($this->requireModelNode($op), $op->position),
            'root' => $this->actMakeRoot(),
            default => throw new LogicException("Unknown pending action: {$op->action}"),
        };

        $this->markMoved();
    }

    /**
     * Narrows PendingOperation::$node (`?HasNestedSet`) to `Model&HasNestedSet`.
     * Persisted nodes are always Models in real usage; the interface is
     * widened only so unit-test stubs can exist without a database.
     */
    private function requireModelNode(PendingOperation $op): Model&HasNestedSet
    {
        $node = $op->node;

        if (! $node instanceof HasNestedSet || ! $node instanceof Model) {
            throw new LogicException("Pending action {$op->action} requires a Model target node.");
        }

        return $node;
    }

    private function actAppendTo(Model&HasNestedSet $parent): void
    {
        $parentBounds = $this->freshBoundsOf($parent);
        $position = $parentBounds->rgt;
        $newDepth = $parentBounds->depth + 1;
        $newParentId = $this->intKey($parent);

        $this->positionAt($position, $newDepth, $newParentId);
    }

    private function actPrependTo(Model&HasNestedSet $parent): void
    {
        $parentBounds = $this->freshBoundsOf($parent);
        $position = $parentBounds->lft + 1;
        $newDepth = $parentBounds->depth + 1;
        $newParentId = $this->intKey($parent);

        $this->positionAt($position, $newDepth, $newParentId);
    }

    private function actSibling(Model&HasNestedSet $sibling, Position $position): void
    {
        $bounds = $this->freshBoundsOf($sibling);
        $insertAt = $position === Position::Before ? $bounds->lft : $bounds->rgt + 1;
        $newDepth = $bounds->depth;
        $newParentId = $sibling->getParentId();

        $this->positionAt($insertAt, $newDepth, $newParentId);
    }

    private function actMakeRoot(): void
    {
        $rawMax = $this->newQuery()->getQuery()->max($this->getRgtName());
        $maxRgt = is_numeric($rawMax) ? (int) $rawMax : 0;

        // Position at maxRgt + 1 places this node at the end of the table;
        // moveNode handles the gap-fill behind it.
        $this->positionAt($maxRgt + 1, 0, null);
    }

    /**
     * Core position write: places this node at $position in original
     * coordinates with $newDepth / $newParentId. For new (unsaved) nodes
     * this makes a gap and sets the attributes; for existing nodes it
     * issues an atomic moveNode UPDATE, then re-reads the node's resulting
     * bounds so Eloquent's dirty tracking is accurate.
     */
    private function positionAt(int $position, int $newDepth, ?int $newParentId): void
    {
        $mutator = $this->newTreeMutator();

        if (! $this->exists) {
            $mutator->makeGap($position, 2);

            $this->setAttribute($this->getLftName(), $position);
            $this->setAttribute($this->getRgtName(), $position + 1);
            $this->setAttribute($this->getDepthName(), $newDepth);
            $this->setAttribute($this->getParentIdName(), $newParentId);

            return;
        }

        $from = $this->getBounds();
        $depthDelta = $newDepth - $from->depth;

        $mutator->moveNode($from, $position, $depthDelta);

        // Re-read this node's new lft/rgt/depth — moveNode shifts many rows
        // at once via CASE WHEN, so we can't derive them locally without
        // duplicating the algorithm.
        $newBounds = $mutator->getPlainNodeData($this->intKey($this));

        $this->setAttribute($this->getLftName(), $newBounds['lft']);
        $this->setAttribute($this->getRgtName(), $newBounds['rgt']);
        $this->setAttribute($this->getDepthName(), $newBounds['depth']);
        $this->setAttribute($this->getParentIdName(), $newParentId);

        // Keep dirty tracking honest — Eloquent's later UPDATE inside save()
        // will then issue at most a parent_id change.
        $this->syncOriginalAttribute($this->getLftName());
        $this->syncOriginalAttribute($this->getRgtName());
        $this->syncOriginalAttribute($this->getDepthName());
    }

    /**
     * Re-reads $other's bounds from the database so we never act on a stale
     * in-memory snapshot — between the user constructing a parent reference
     * and our save() running, other nodes might have shifted the parent.
     */
    private function freshBoundsOf(Model&HasNestedSet $other): NodeBounds
    {
        $mutator = $this->newTreeMutator();

        return $mutator->getNodeData($this->intKey($other));
    }

    /**
     * Narrows Model::getKey() (mixed) to int. Nested-set models always use
     * integer primary keys — anything else would break the algorithm.
     */
    private function intKey(Model $node): int
    {
        $key = $node->getKey();

        if (is_int($key) || is_numeric($key)) {
            return (int) $key;
        }

        throw new LogicException('NestedSet models require integer primary keys.');
    }

    protected function newTreeMutator(): TreeMutationBuilder
    {
        return new TreeMutationBuilder(
            connection: $this->getConnection(),
            table: $this->getTable(),
            lft: $this->getLftName(),
            rgt: $this->getRgtName(),
            parentId: $this->getParentIdName(),
            depth: $this->getDepthName(),
            scope: NestedSetScopeResolver::valuesFor($this),
        );
    }

    /**
     * Sibling immediately before this node (same parent, next-smaller rgt).
     */
    public function prevSibling(): ?static
    {
        $parentId = $this->getParentId();

        $query = $this->newQuery();

        if ($parentId === null) {
            $query->whereNull($this->getParentIdName());
        } else {
            $query->where($this->getParentIdName(), $parentId);
        }

        /** @var static|null $result */
        $result = $query->where($this->getRgtName(), '=', $this->getLft() - 1)->first();

        return $result;
    }

    /**
     * Sibling immediately after this node (same parent, next-larger lft).
     */
    public function nextSibling(): ?static
    {
        $parentId = $this->getParentId();

        $query = $this->newQuery();

        if ($parentId === null) {
            $query->whereNull($this->getParentIdName());
        } else {
            $query->where($this->getParentIdName(), $parentId);
        }

        /** @var static|null $result */
        $result = $query->where($this->getLftName(), '=', $this->getRgt() + 1)->first();

        return $result;
    }
}
