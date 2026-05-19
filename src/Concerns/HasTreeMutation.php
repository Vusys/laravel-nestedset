<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Events\NodeMoved;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
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
    protected ?PendingOperation $pending = null;

    /**
     * Queue this node to become the last child of $parent on the next save().
     *
     * @throws ScopeViolationException
     *                                 When $parent belongs to a different scope (multi-tree models).
     * @throws LogicException
     *                        When $parent is in this node's own subtree (would create a cycle).
     */
    public function appendToNode(HasNestedSet $parent): static
    {
        $this->pending = new PendingOperation('appendTo', $parent);

        return $this;
    }

    /**
     * Queue this node to become the first child of $parent on the next save().
     *
     * @throws ScopeViolationException
     * @throws LogicException
     */
    public function prependToNode(HasNestedSet $parent): static
    {
        $this->pending = new PendingOperation('prependTo', $parent);

        return $this;
    }

    /**
     * Queue this node to become an immediate left-sibling of $sibling.
     *
     * @throws ScopeViolationException
     * @throws LogicException
     */
    public function insertBeforeNode(HasNestedSet $sibling): static
    {
        $this->pending = new PendingOperation('sibling', $sibling, Position::Before);

        return $this;
    }

    /**
     * Queue this node to become an immediate right-sibling of $sibling.
     *
     * @throws ScopeViolationException
     * @throws LogicException
     */
    public function insertAfterNode(HasNestedSet $sibling): static
    {
        $this->pending = new PendingOperation('sibling', $sibling, Position::After);

        return $this;
    }

    /**
     * Queue this node to become a top-level root (parent_id = null,
     * depth = 0) on the next save().
     */
    public function makeRoot(): static
    {
        $this->pending = new PendingOperation('root');

        return $this;
    }

    /**
     * Shorthand for `$this->makeRoot()->save()`. Returns the result of save().
     */
    public function saveAsRoot(): bool
    {
        return $this->makeRoot()->save();
    }

    /**
     * Wraps Eloquent's save() in a transaction when
     * `config('nestedset.auto_transaction')` is on, so the structural
     * SQL (makeGap / moveNode), the Eloquent INSERT/UPDATE, and the
     * aggregate hooks (`saved` / `created` listeners) all commit or
     * roll back together. Without this wrap, a failed INSERT — unique
     * constraint, throwing listener, etc. — would leave the gap
     * committed and produce a permanent hole in the lft/rgt sequence.
     *
     * Laravel handles nested calls via savepoints, so wrapping inside
     * an outer `DB::transaction()` is safe.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if (! config('nestedset.auto_transaction', true)) {
            return parent::save($options);
        }

        return (bool) $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    /**
     * Move this node one position up among its siblings (toward smaller lft).
     *
     * Fires {@see NodeMoved} for **both** participants — the moved
     * node (via the wrapped `insertBeforeNode->save`) and the
     * displaced sibling (emitted explicitly here). A swap is two
     * moves; consumers rebuilding a cache or index need to know about
     * both endpoints.
     */
    public function up(): bool
    {
        $sibling = $this->prevSibling();

        if ($sibling === null) {
            return false;
        }

        return $this->moveAndEmitSiblingSwap($sibling, fn (): bool => $this->insertBeforeNode($sibling)->save());
    }

    /**
     * Move this node one position down among its siblings (toward larger lft).
     *
     * Fires {@see NodeMoved} for both participants — see {@see up()}
     * for the contract.
     */
    public function down(): bool
    {
        $sibling = $this->nextSibling();

        if ($sibling === null) {
            return false;
        }

        return $this->moveAndEmitSiblingSwap($sibling, fn (): bool => $this->insertAfterNode($sibling)->save());
    }

    /**
     * Wraps an `insertBeforeNode` / `insertAfterNode` swap so a
     * `NodeMoved` event also fires for the displaced sibling.
     *
     * The wrapped `->save()` already emits `NodeMoved` for `$this`.
     * After it returns we re-read the sibling's post-swap bounds
     * (its in-memory copy is stale because the SQL shifted it) and
     * dispatch a second event so observers see both endpoints.
     *
     * @param  \Closure(): bool  $perform
     */
    private function moveAndEmitSiblingSwap(Model&HasNestedSet $sibling, \Closure $perform): bool
    {
        $siblingFrom = $sibling->getBounds();
        $startNs = hrtime(true);

        $result = $perform();

        if (! $result) {
            return $result;
        }

        $sibling->refresh();
        $durationMs = (hrtime(true) - $startNs) / 1_000_000;

        EventDispatcher::dispatch(new NodeMoved(
            modelClass: static::class,
            nodeId: $this->intKey($sibling),
            fromBounds: $siblingFrom,
            toBounds: $sibling->getBounds(),
            operation: 'sibling-displaced',
            durationMs: $durationMs,
        ));

        return $result;
    }

    /**
     * Dispatches whatever the user queued via appendToNode/etc. Called from
     * the model's `saving` event in NodeTrait::bootNodeTrait.
     *
     * @internal
     */
    public function callPendingAction(): void
    {
        if ($this->pending === null) {
            return;
        }

        $op = $this->pending;
        $this->pending = null;

        if ($op->node !== null) {
            $target = $this->requireModelNode($op);
            NestedSetScopeResolver::assertSameScope($this, $target);
        }

        // For existing-node moves we want hooks that bracket the
        // structural SQL: the "before" hook reads pre-move bounds while
        // they're still accurate; the "after" hook acts on post-move
        // bounds. New-node placements have no meaningful `from` (lft/rgt
        // are still the migration default 0); the `created` Eloquent
        // event handles their aggregate maintenance.
        $wasExisting = $this->exists;
        $from = $wasExisting ? $this->getBounds() : null;

        $work = function () use ($op, $wasExisting, $from): void {
            if ($wasExisting && $from !== null) {
                $this->onBeforePendingAction($from, $op->action);
            }

            match ($op->action) {
                'appendTo' => $this->actAppendTo($this->requireModelNode($op)),
                'prependTo' => $this->actPrependTo($this->requireModelNode($op)),
                'sibling' => $this->actSibling($this->requireModelNode($op), $op->position),
                'root' => $this->actMakeRoot(),
                default => throw new LogicException("Unknown pending action: {$op->action}"),
            };

            if ($wasExisting && $from !== null) {
                $this->onAfterPendingAction($from, $this->getBounds(), $op->action);
            }
        };

        $startNs = hrtime(true);

        // The outer transaction is opened by the trait's save() override
        // when auto_transaction is on; the gap, the Eloquent
        // INSERT/UPDATE that follows the saving listener, and the
        // aggregate hooks all commit or roll back as one unit. Nothing
        // extra to do here.
        $work();

        $durationMs = (hrtime(true) - $startNs) / 1_000_000;

        $this->markMoved();

        // NodeMoved fires for existing-node mutations only. New-node
        // placements have Eloquent's `created` event for observability;
        // emitting our own NodeMoved for them would duplicate that
        // surface and confuse the "this was a move, not an insert"
        // intent of the event.
        if ($wasExisting && $from !== null) {
            EventDispatcher::dispatch(new NodeMoved(
                modelClass: static::class,
                nodeId: $this->intKey($this),
                fromBounds: $from,
                toBounds: $this->getBounds(),
                operation: $op->action,
                durationMs: $durationMs,
            ));
        }
    }

    /**
     * Seam called immediately before the structural SQL runs for an
     * existing-node mutation. The pre-move bounds are still accurate
     * here, so handlers can act on the OLD ancestor chain (e.g.
     * subtract aggregate contributions) using bounds-based WHEREs.
     *
     * Default no-op on HasTreeMutation-only models; NodeTrait composes
     * HasNestedSetAggregates which provides the aggregate handler. A
     * model using HasTreeMutation without HasNestedSetAggregates would
     * miss this dispatch, but the package's NodeTrait pairs them.
     */
    protected function onBeforePendingAction(NodeBounds $from, string $action): void
    {
        $this->applyAggregateBeforeMove($from, $action);
    }

    /**
     * Seam called immediately after the structural SQL runs for an
     * existing-node mutation, within the same transaction. The
     * post-move bounds are now in place, so handlers can act on the
     * NEW ancestor chain.
     */
    protected function onAfterPendingAction(NodeBounds $from, NodeBounds $to, string $action): void
    {
        $this->applyAggregateAfterMove($from, $to, $action);
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
        if ($this->exists && $sibling->getKey() === $this->getKey()) {
            throw new LogicException('Cannot position node as a sibling of itself.');
        }

        $bounds = $this->freshBoundsOf($sibling);
        $insertAt = $position === Position::Before ? $bounds->lft : $bounds->rgt + 1;
        $newDepth = $bounds->depth;
        $newParentId = $sibling->getParentId();

        $this->positionAt($insertAt, $newDepth, $newParentId);
    }

    /**
     * `deleted` lifecycle hook: close the lft/rgt gap a hard-delete
     * leaves behind so the table's bounds stay a contiguous 1..2N
     * permutation per root.
     *
     * Only fires when the row was actually removed from the DB
     * (hard delete — `$this->exists === false`) and the deleted
     * node was a leaf. Soft-deleted rows still occupy their slots,
     * so closing the gap would invalidate their bounds. Interior
     * hard-deletes leave their children orphaned and inside the
     * vanished range — shifting the surrounding rows would move
     * those orphans into impossible positions, making the eventual
     * fixTree call's job harder rather than easier.
     *
     * Wired into NodeTrait's `deleted` event after the aggregate
     * decrement runs (the aggregate hook reads the deleted node's
     * old bounds, so order matters).
     */
    public function applyStructuralCleanupOnDelete(): void
    {
        // Soft-deleted rows still exist (only deleted_at was set);
        // their slots must stay reserved.
        if ($this->exists) {
            return;
        }

        $rawLft = $this->getAttribute($this->getLftName());
        $rawRgt = $this->getAttribute($this->getRgtName());
        if (! is_numeric($rawLft) || ! is_numeric($rawRgt)) {
            return;
        }
        $lft = (int) $rawLft;
        $rgt = (int) $rawRgt;

        // Interior hard-delete: skip. Children are orphaned but their
        // bounds at least stay inside their (now-vanished) parent's
        // former range — fixTree can recover from that. Closing the
        // gap would shift the orphans into invalid positions.
        if ($rgt - $lft !== 1) {
            return;
        }

        $this->newTreeMutator()->closeGap($lft, 2);
    }

    private function actMakeRoot(): void
    {
        // Scope the max-rgt lookup to this node's scope. Without the
        // scope filter, the second scope's first root would land past
        // the first scope's rgt and silently break per-scope lft/rgt
        // independence. Caught by the scope-isolation fuzzer.
        $query = $this->newQuery()->getQuery();
        foreach (NestedSetScopeResolver::valuesFor($this) as $col => $value) {
            $query->where($col, $value);
        }
        $rawMax = $query->max($this->getRgtName());
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

        // Read $from from the DB rather than $this — the in-memory model may
        // be stale (e.g. saved before later sibling inserts shifted its rgt),
        // and feeding moveNode an out-of-date bound corrupts the tree.
        $from = $mutator->getNodeData($this->intKey($this));
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
            idCol: $this->getKeyName(),
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
