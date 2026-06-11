<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Events\Mutation\NodeMoved;
use Vusys\NestedSet\Events\Mutation\NodePromotedToRoot;
use Vusys\NestedSet\Events\Mutation\NodesSwapped;
use Vusys\NestedSet\Events\Mutation\SiblingsReordered;
use Vusys\NestedSet\Events\Subtree\SubtreeForceDeleted;
use Vusys\NestedSet\Events\Subtree\SubtreeForceDeleting;
use Vusys\NestedSet\Events\Subtree\SubtreeMoved;
use Vusys\NestedSet\Events\Subtree\SubtreeMoving;
use Vusys\NestedSet\Exceptions\InvalidSiblingOrderException;
use Vusys\NestedSet\Exceptions\SaveCancelledException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Exceptions\UnplacedNodeException;
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
     * The mover's fresh pre-move bounds, read once at the top of
     * {@see callPendingAction()} and reused by {@see positionAt()} so an
     * existing-node move issues a single SELECT for its own bounds.
     */
    private ?NodeBounds $pendingMoveFromBounds = null;

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
     * Queue this node to land under $parent at the given position among
     * its siblings on the next save(). One ergonomic entry point that
     * picks between {@see appendToNode}, {@see prependToNode}, and
     * {@see insertBeforeNode} based on the resolved index.
     *
     * Position semantics:
     *
     * | `$position`        | Resolves to                              |
     * | ------------------ | ---------------------------------------- |
     * | `'last'` (default) | `appendToNode($parent)`                  |
     * | `'first'`          | `prependToNode($parent)`                 |
     * | `0`                | `prependToNode($parent)`                 |
     * | `$n` (1..count-1)  | `insertBeforeNode(siblings[$n])`         |
     * | `$n >= count`      | `appendToNode($parent)`                  |
     * | negative `$n`      | throws `LogicException`                  |
     *
     * The index is counted after self-excluding `$this` if it already
     * lives under `$parent`, so "position N" means "end up at final
     * index N" rather than "skip N other siblings".
     *
     * Same-parent reorders use this same call shape:
     * `$node->moveTo($node->parent, $newIndex)`. There is no dedicated
     * `moveAt(int)` helper.
     *
     * @throws LogicException
     *                        When the position is invalid, `$parent` is unsaved, or the underlying primitive rejects the move.
     * @throws ScopeViolationException
     *                                 When `$parent` belongs to a different scope (multi-tree models).
     */
    public function moveTo(HasNestedSet $parent, int|string $position = 'last'): static
    {
        if (is_string($position)) {
            return match ($position) {
                'last' => $this->appendToNode($parent),
                'first' => $this->prependToNode($parent),
                default => throw new LogicException(
                    "moveTo() string position must be 'first' or 'last'. Got '{$position}'."
                ),
            };
        }

        if ($position < 0) {
            throw new LogicException("moveTo() int position must be non-negative. Got {$position}.");
        }

        if ($position === 0) {
            return $this->prependToNode($parent);
        }

        if (! $parent instanceof Model || ! $parent->exists) {
            throw new LogicException('moveTo() requires a saved parent model.');
        }

        $target = $this->orderedSiblingsUnder($parent)->skip($position)->first();

        if ($target === null) {
            return $this->appendToNode($parent);
        }

        return $this->insertBeforeNode($target);
    }

    /**
     * Queue this node to become an immediate left-sibling of $sibling.
     * Ergonomic alias of {@see insertBeforeNode}.
     *
     * @throws LogicException
     * @throws ScopeViolationException
     */
    public function moveBefore(HasNestedSet $sibling): static
    {
        return $this->insertBeforeNode($sibling);
    }

    /**
     * Queue this node to become an immediate right-sibling of $sibling.
     * Ergonomic alias of {@see insertAfterNode}.
     *
     * @throws LogicException
     * @throws ScopeViolationException
     */
    public function moveAfter(HasNestedSet $sibling): static
    {
        return $this->insertAfterNode($sibling);
    }

    /**
     * Reshuffle this node's direct children into the order given by
     * `$idsInOrder` using one atomic CASE-WHEN UPDATE. Every direct
     * child must appear exactly once — missing, unknown, or duplicate
     * keys throw {@see InvalidSiblingOrderException}.
     *
     * The supplied list may contain either primary-key values or
     * model instances; instances are normalised to their primary key.
     *
     * If the supplied order matches the current `lft` order the call
     * is a no-op: no UPDATE fires, no {@see SiblingsReordered} event
     * is emitted, and the method returns `$this` unchanged. A parent
     * with no children is likewise a silent no-op.
     *
     * The reorder issues a raw UPDATE through {@see TreeMutationBuilder},
     * bypassing the Eloquent `saving` / `saved` listener chain — so the
     * aggregate-maintenance hooks (which key off the lifecycle) never
     * run. Sibling reordering doesn't change ancestry, so stored
     * aggregate values stay correct without maintenance.
     *
     * Returns `$this` refreshed from the database so `lft` / `rgt`
     * reflect the post-reorder bounds (the parent's own bounds are
     * unchanged but the in-memory copy is left in sync for the caller).
     *
     * @param  list<int|string|HasNestedSet>  $idsInOrder
     *
     * @throws UnplacedNodeException
     *                               When this parent has never been placed in the tree.
     * @throws InvalidSiblingOrderException
     *                                      When the supplied membership does not exactly match the parent's direct children.
     */
    public function reorderChildren(array $idsInOrder): static
    {
        if (! $this->exists) {
            throw new UnplacedNodeException(sprintf(
                '%s::reorderChildren() requires a saved parent.',
                static::class,
            ));
        }

        $mutator = $this->newTreeMutator();
        $startNs = hrtime(true);

        // Wrap the parent-bounds read, the child-bounds read, and the
        // reorder UPDATE in ONE transaction with the parent row locked
        // FOR UPDATE. Previously the reads ran outside the transaction,
        // so a concurrent append to the same parent between the reads
        // and the UPDATE could shift the child windows and corrupt the
        // computed shifts. Validation exceptions thrown inside roll back
        // the (still write-free) transaction harmlessly.
        //
        // Returns null for the no-op cases (empty parent, identity
        // reorder) so the caller returns $this without an event; on a
        // real reorder it returns the affected-row count and the
        // requested key order for the post-commit event.
        /** @var array{rowsAffected: int, requestedKeys: list<int|string>}|null $outcome */
        $outcome = $this->getConnection()->transaction(function () use ($mutator, $idsInOrder): ?array {
            // Resolve the parent's own bounds under a row lock — the
            // in-memory copy may be stale and the UPDATE predicate hinges
            // on these being current and held until commit.
            $parentBounds = $this->freshBoundsOf($this, lockForUpdate: true);

            // The parent's subtree is empty — nothing to reorder.
            if ($parentBounds->rgt - $parentBounds->lft === 1) {
                if ($idsInOrder !== []) {
                    throw InvalidSiblingOrderException::unknownChildren(
                        $this->normaliseSiblingKeys($idsInOrder),
                    );
                }

                return null;
            }

            $requestedKeys = $this->normaliseSiblingKeys($idsInOrder);
            $duplicates = $this->duplicateKeys($requestedKeys);
            if ($duplicates !== []) {
                throw InvalidSiblingOrderException::duplicateChildren($duplicates);
            }

            // The leaf-check above (rgt - lft === 1) guarantees the parent
            // has at least one descendant, which in turn means at least one
            // direct child — so this list is never empty here.
            /** @var list<array{key: int|string, lft: int, rgt: int}> $current */
            $current = $this->loadDirectChildBounds();

            $currentKeys = array_map(static fn (array $row): int|string => $row['key'], $current);

            $unknown = array_values(array_diff($requestedKeys, $currentKeys));
            if ($unknown !== []) {
                throw InvalidSiblingOrderException::unknownChildren($unknown);
            }

            $missing = array_values(array_diff($currentKeys, $requestedKeys));
            if ($missing !== []) {
                throw InvalidSiblingOrderException::missingChildren($missing);
            }

            // Identity reorder: every position matches → no UPDATE.
            $isIdentity = true;
            foreach ($currentKeys as $i => $key) {
                if (! $this->keysEqual($key, $requestedKeys[$i])) {
                    $isIdentity = false;
                    break;
                }
            }
            if ($isIdentity) {
                return null;
            }

            // Index current rows by key for O(1) lookup as we walk the
            // requested order computing new starting lft for each sibling.
            $byKey = [];
            foreach ($current as $row) {
                $byKey[(string) $row['key']] = $row;
            }

            /** @var list<array{int, int, int}> $shifts */
            $shifts = [];
            $cursor = $parentBounds->lft + 1;
            foreach ($requestedKeys as $key) {
                $row = $byKey[(string) $key];
                $height = $row['rgt'] - $row['lft'] + 1;
                $delta = $cursor - $row['lft'];
                $shifts[] = [$row['lft'], $row['rgt'], $delta];
                $cursor += $height;
            }

            $rowsAffected = $mutator->reorderSiblings(
                parentLft: $parentBounds->lft,
                parentRgt: $parentBounds->rgt,
                shifts: $shifts,
            );

            return ['rowsAffected' => $rowsAffected, 'requestedKeys' => $requestedKeys];
        });

        if ($outcome === null) {
            return $this;
        }

        $durationMs = (hrtime(true) - $startNs) / 1_000_000;

        EventDispatcher::dispatch(new SiblingsReordered(
            modelClass: static::class,
            parent: $this,
            idsInOrder: $outcome['requestedKeys'],
            rowsAffected: $outcome['rowsAffected'],
            durationMs: $durationMs,
        ));

        return $this;
    }

    /**
     * Sugar over {@see reorderChildren()}: sorts this node's direct
     * children by a column name or a closure and applies the
     * resulting order. Equivalent to
     * `$this->reorderChildren($this->children->sortBy($key)->pluck($this->getKeyName())->all())`
     * but reads the children directly so callers don't have to.
     *
     * Useful for one-shot needs like "sort siblings alphabetically".
     *
     * @param  string|\Closure(static): mixed  $key
     */
    public function reorderChildrenBy(string|\Closure $key): static
    {
        if (! $this->exists) {
            throw new UnplacedNodeException(sprintf(
                '%s::reorderChildrenBy() requires a saved parent.',
                static::class,
            ));
        }

        $children = $this->newQuery()
            ->where($this->getParentIdName(), $this->keyOf($this))
            ->orderBy($this->getLftName())
            ->get();

        if ($children->isEmpty()) {
            return $this;
        }

        $ordered = $children->sortBy($key)->values();

        /** @var list<int|string> $ids */
        $ids = [];
        foreach ($ordered as $child) {
            $ids[] = $this->keyOf($child);
        }

        return $this->reorderChildren($ids);
    }

    /**
     * Move this node to position `$position` (1-indexed) within its
     * current sibling group. A thin wrapper over its parent's
     * {@see reorderChildren()} that preserves everyone else's relative
     * order.
     *
     * Position semantics match `up()` / `down()`: position 1 is the
     * first sibling, position `count(siblings)` is the last.
     *
     * @throws LogicException
     *                        When `$position` is outside `[1, count(siblings)]`.
     * @throws UnplacedNodeException
     *                               When this node has no parent (a root has no sibling group to reorder within).
     */
    public function moveToSiblingPosition(int $position): static
    {
        $parentId = $this->getParentId();

        if ($parentId === null) {
            throw new UnplacedNodeException(sprintf(
                '%s::moveToSiblingPosition() requires the node to have a parent — roots have no sibling group to reorder within. Use up()/down() to reorder roots, or rebuild the tree.',
                static::class,
            ));
        }

        /** @var static|null $parent */
        $parent = $this->newQuery()->whereKey($parentId)->first();

        if ($parent === null) {
            throw new LogicException(sprintf(
                '%s::moveToSiblingPosition(): parent (id=%s) not found.',
                static::class,
                (string) $parentId,
            ));
        }

        $siblings = $parent->loadDirectChildBounds();
        $count = count($siblings);

        if ($position < 1 || $position > $count) {
            throw new LogicException(sprintf(
                '%s::moveToSiblingPosition(%d): position must be in [1, %d].',
                static::class,
                $position,
                $count,
            ));
        }

        $selfKey = $this->keyOf($this);

        // Pull self out of the sibling list, then insert at the
        // requested (1-indexed) slot.
        $others = array_values(array_filter(
            array_map(static fn (array $row): int|string => $row['key'], $siblings),
            fn ($key): bool => ! $this->keysEqual($key, $selfKey),
        ));

        $newOrder = $others;
        array_splice($newOrder, $position - 1, 0, [$selfKey]);

        $parent->reorderChildren($newOrder);

        return $this->refresh();
    }

    /**
     * Static alias for `$parent->reorderChildren($idsInOrder)`.
     * Reads more naturally at call sites that already have a parent
     * variable in scope, e.g. `Category::reorderSiblings($parent, $ids)`.
     *
     * @param  list<int|string|HasNestedSet>  $idsInOrder
     */
    public static function reorderSiblings(Model&HasNestedSet $parent, array $idsInOrder): static
    {
        if (! $parent instanceof static) {
            throw new LogicException(sprintf(
                '%s::reorderSiblings(): parent must be an instance of %s, got %s.',
                static::class,
                static::class,
                $parent::class,
            ));
        }

        return $parent->reorderChildren($idsInOrder);
    }

    /**
     * Direct children of this node, returned as a list of
     * `[key, lft, rgt]` triples in lft order. Used by the reorder
     * helpers to (a) validate caller-supplied membership and (b)
     * compute per-sibling subtree heights without loading full
     * Eloquent instances.
     *
     * @return list<array{key: int|string, lft: int, rgt: int}>
     *
     * @internal
     */
    public function loadDirectChildBounds(): array
    {
        $query = $this->getConnection()
            ->table($this->getTable())
            ->where($this->getParentIdName(), $this->keyOf($this))
            ->orderBy($this->getLftName());

        foreach (NestedSetScopeResolver::valuesFor($this) as $col => $value) {
            $query->where($col, $value);
        }

        $isIntKey = $this->getKeyType() === 'int';
        $keyName = $this->getKeyName();
        $lftName = $this->getLftName();
        $rgtName = $this->getRgtName();

        $rows = $query->get([$keyName, $lftName, $rgtName]);

        $out = [];
        foreach ($rows as $row) {
            $key = $row->{$keyName};
            if (! is_int($key) && ! is_string($key)) {
                continue;
            }
            $out[] = [
                'key' => $isIntKey ? (int) $key : (string) $key,
                'lft' => (int) $row->{$lftName},
                'rgt' => (int) $row->{$rgtName},
            ];
        }

        return $out;
    }

    /**
     * @param  list<int|string|HasNestedSet>  $idsInOrder
     * @return list<int|string>
     */
    private function normaliseSiblingKeys(array $idsInOrder): array
    {
        $isIntKey = $this->getKeyType() === 'int';
        $out = [];
        foreach ($idsInOrder as $entry) {
            if ($entry instanceof HasNestedSet && $entry instanceof Model) {
                $key = $entry->getKey();
            } else {
                $key = $entry;
            }

            if (! is_int($key) && ! is_string($key)) {
                throw new LogicException(sprintf(
                    '%s::reorderChildren(): every entry must be a primary key (int|string) or a saved %s instance; got %s.',
                    static::class,
                    static::class,
                    get_debug_type($entry),
                ));
            }

            $out[] = $isIntKey ? (int) $key : (string) $key;
        }

        return $out;
    }

    /**
     * @param  list<int|string>  $keys
     * @return list<int|string>
     */
    private function duplicateKeys(array $keys): array
    {
        $seen = [];
        $dupes = [];
        foreach ($keys as $key) {
            $needle = (string) $key;
            if (isset($seen[$needle])) {
                // Preserve int/string identity of the original key.
                if (! in_array($key, $dupes, true)) {
                    $dupes[] = $key;
                }

                continue;
            }
            $seen[$needle] = true;
        }

        return $dupes;
    }

    private function keysEqual(int|string $a, int|string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        // Tolerate int/string mixes from raw DB rows vs Eloquent casts.
        if (is_numeric($a) && is_numeric($b)) {
            return (string) $a === (string) $b;
        }

        return false;
    }

    /**
     * Children of $parent in lft order, with $this excluded so positional
     * arithmetic resolves to a final index rather than counting siblings
     * to skip. Built from $this->newQuery() (rather than $parent->children())
     * so the generic stays static<>, no `instanceof` narrowing of the
     * relation is needed, and the scope predicates come from $this — which
     * are by construction the same as $parent's once we've checked
     * scope-compatibility.
     *
     * @return Collection<int, static>
     */
    private function orderedSiblingsUnder(Model&HasNestedSet $parent): Collection
    {
        NestedSetScopeResolver::assertSameScope($this, $parent);

        $query = $this->newQuery()
            ->where($this->getParentIdName(), $this->keyOf($parent))
            ->orderBy($this->getLftName());

        foreach (NestedSetScopeResolver::valuesFor($this) as $col => $value) {
            $query->where($col, $value);
        }

        if ($this->exists) {
            $query->whereKeyNot($this->getKey());
        }

        return $query->get()->values();
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

        try {
            return (bool) $this->getConnection()->transaction(function () use ($options): bool {
                $saved = parent::save($options);

                // A saving/creating/updating listener cancelled the save by
                // returning false — but the trait's own `saving` listener
                // has already run the structural SQL (makeGap / moveNode).
                // transaction() commits unless an exception is thrown, so a
                // bare `return false` would leave that gap/move committed
                // with no row write. Throw to force the rollback; the catch
                // below restores the cancelled-save contract for the caller.
                if ($saved === false) {
                    throw new SaveCancelledException;
                }

                return $saved;
            });
        } catch (SaveCancelledException) {
            return false;
        }
    }

    /**
     * Placement is core write logic, not observability — it runs in the
     * `saving` listener (queued-operation dispatch plus the unplaced-node
     * guard), which `saveQuietly()` suppresses via `withoutEvents()`.
     * Persisting quietly would therefore silently drop a queued
     * appendToNode/makeRoot/… and write `lft = rgt = 0`. Refuse instead:
     * a node with a pending placement, or a new node not yet placed, must
     * go through `save()`.
     *
     * @param  array<string, mixed>  $options
     */
    public function saveQuietly(array $options = []): bool
    {
        if ($this->pending !== null) {
            throw new UnplacedNodeException(sprintf(
                '%s::saveQuietly() cannot dispatch the queued tree operation "%s" — placement '
                .'runs in the `saving` event that saveQuietly() suppresses. Use save().',
                static::class,
                $this->pending->action,
            ));
        }

        if (! $this->exists && ! $this->isPlacedInTree()) {
            throw new UnplacedNodeException(sprintf(
                '%s::saveQuietly() would persist an unplaced node (lft = rgt = 0). Place it first '
                .'via appendToNode($parent) / makeRoot() / … and use save().',
                static::class,
            ));
        }

        return parent::saveQuietly($options);
    }

    /**
     * Wraps Eloquent's delete() in a transaction when
     * `config('nestedset.auto_transaction')` is on, for the same reason
     * save() is wrapped: the delete pipeline is multi-statement — the
     * row's own DELETE/soft-delete, the descendant cascade, the aggregate
     * decrement, and the closeGap compaction all run across the
     * `deleting`/`deleted` listeners. On MySQL/MariaDB/PG the row delete
     * autocommits without this wrap, so a throw mid-pipeline leaves a
     * permanent hole in the lft/rgt sequence. Both `Model::forceDelete()`
     * and `SoftDeletes::forceDelete()` delegate to `delete()`, so this
     * single override covers force deletes too.
     *
     * Laravel handles nested calls via savepoints, so wrapping inside an
     * outer `DB::transaction()` is safe.
     */
    public function delete(): ?bool
    {
        if (! config('nestedset.auto_transaction', true)) {
            return parent::delete();
        }

        $result = $this->getConnection()->transaction(fn (): ?bool => parent::delete());

        return is_bool($result) ? $result : null;
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

        return $this->moveAndEmitSiblingSwap(
            $sibling,
            fn (): bool => $this->insertBeforeNode($sibling)->save(),
            direction: 'up',
        );
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

        return $this->moveAndEmitSiblingSwap(
            $sibling,
            fn (): bool => $this->insertAfterNode($sibling)->save(),
            direction: 'down',
        );
    }

    /**
     * Wraps an `insertBeforeNode` / `insertAfterNode` swap so a
     * `NodeMoved` event also fires for the displaced sibling, plus
     * a single {@see NodesSwapped} that frames the swap as one
     * logical operation.
     *
     * The wrapped `->save()` already emits `NodeMoved` for `$this`.
     * After it returns we re-read the sibling's post-swap bounds
     * (its in-memory copy is stale because the SQL shifted it) and
     * dispatch the second `NodeMoved` plus `NodesSwapped`.
     *
     * @param  \Closure(): bool  $perform
     */
    private function moveAndEmitSiblingSwap(Model&HasNestedSet $sibling, \Closure $perform, string $direction): bool
    {
        $thisFrom = $this->getBounds();
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
            nodeId: $this->keyOf($sibling),
            fromBounds: $siblingFrom,
            toBounds: $sibling->getBounds(),
            operation: 'sibling-displaced',
            durationMs: $durationMs,
        ));

        EventDispatcher::dispatch(new NodesSwapped(
            modelClass: static::class,
            movedNode: $this,
            movedNodeFrom: $thisFrom,
            movedNodeTo: $this->getBounds(),
            displacedSibling: $sibling,
            displacedFrom: $siblingFrom,
            displacedTo: $sibling->getBounds(),
            direction: $direction,
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
            // Target lft/rgt come from the DB row (freshBoundsOf re-reads
            // them on dispatch) so a stale in-memory copy is fine — but a
            // saved target with lft=rgt=0 on disk (raw insert, recovered
            // corruption) would otherwise read those zero bounds and call
            // makeGap(0, 2), shifting every row in the scope up by 2 and
            // leaving the target at (2,2) with the new node at (0,1) —
            // silent corruption with no ancestor relationship. Restricted
            // to $exists targets so unsaved-target rejection stays with
            // the keyOf "no primary key" check, which is the more
            // specific failure for that case.
            if ($target->exists && ! $target->isPlacedInTree()) {
                $targetKey = $target->getKey();
                throw new UnplacedNodeException(sprintf(
                    '%s::%s target %s id=%s has no bounds — place it in a tree (saveAsRoot / appendToNode / …) first.',
                    static::class,
                    $op->action,
                    $target::class,
                    is_int($targetKey) || is_string($targetKey) ? (string) $targetKey : '?',
                ));
            }
        }

        // For existing-node moves we want hooks that bracket the
        // structural SQL: the "before" hook reads pre-move bounds while
        // they're still accurate; the "after" hook acts on post-move
        // bounds. New-node placements have no meaningful `from` (lft/rgt
        // are still the migration default 0); the `created` Eloquent
        // event handles their aggregate maintenance.
        $wasExisting = $this->exists;
        // Read the mover's bounds from the DB, not the in-memory model:
        // a sibling insert (or any prior mutation) since this instance
        // was loaded may have shifted its lft/rgt, and the before-move
        // aggregate hook + the move events all key off these bounds.
        // Lock the mover's row FOR UPDATE: between this read and the
        // target lock sit the SubtreeMoving dispatch and the before-move
        // aggregate hook — a wide window in which a concurrent committed
        // insert/move could shift the mover's bounds and make the
        // moveNode() CASE band match the wrong rows. Stash it so
        // positionAt() reuses the same read instead of issuing a second
        // identical SELECT — the before-move hook only touches ancestor
        // aggregate columns, never the mover's bounds, so the value is
        // still current when positionAt runs.
        $from = $wasExisting ? $this->freshBoundsOf($this, lockForUpdate: true) : null;
        $this->pendingMoveFromBounds = $from;

        // Re-read the mover's stored aggregate columns before the
        // before-move hook subtracts them from the old ancestor chain.
        // Delta maintenance writes these via raw SQL without syncing the
        // model, so a held instance (source updated, child appended, or
        // freshly created then moved in the same instance) carries stale
        // totals the move would otherwise transfer verbatim.
        if ($wasExisting && $this instanceof MaintainsTreeAggregates) {
            $this->refreshMaintainedAggregateColumns();
        }

        $previousParentId = $wasExisting ? $this->getParentId() : null;
        $previousDepth = $wasExisting ? $this->getDepth() : 0;

        if ($wasExisting && $from !== null) {
            EventDispatcher::dispatch(new SubtreeMoving(
                modelClass: static::class,
                anchor: $this,
                fromBounds: $from,
                operation: $op->action,
            ));
        }

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
            $toBounds = $this->getBounds();

            EventDispatcher::dispatch(new NodeMoved(
                modelClass: static::class,
                nodeId: $this->keyOf($this),
                fromBounds: $from,
                toBounds: $toBounds,
                operation: $op->action,
                durationMs: $durationMs,
            ));

            $descendantIds = EventDispatcher::hasListeners(SubtreeMoved::class)
                ? $this->collectStrictDescendantIds($toBounds)
                : [];

            EventDispatcher::dispatch(new SubtreeMoved(
                modelClass: static::class,
                anchor: $this,
                fromBounds: $from,
                toBounds: $toBounds,
                operation: $op->action,
                descendantIds: $descendantIds,
                durationMs: $durationMs,
            ));

            if ($op->action === 'root') {
                EventDispatcher::dispatch(new NodePromotedToRoot(
                    modelClass: static::class,
                    anchor: $this,
                    previousParentId: $previousParentId,
                    previousDepth: $previousDepth,
                ));
            }
        }
    }

    /**
     * Reads strict-descendant primary keys via a bounds SELECT in
     * the node's scope. Used to populate {@see SubtreeMoved} and
     * is gated by {@see EventDispatcher::enabled()} at the call
     * site so disabled telemetry pays no extra query.
     *
     * @return list<int|string>
     */
    private function collectStrictDescendantIds(NodeBounds $bounds): array
    {
        $query = $this->getConnection()
            ->table($this->getTable())
            ->where($this->getLftName(), '>', $bounds->lft)
            ->where($this->getRgtName(), '<', $bounds->rgt);

        foreach (NestedSetScopeResolver::valuesFor($this) as $column => $value) {
            $query->where($column, '=', $value);
        }

        $keyName = $this->getKeyName();
        $isIntKey = $this->getKeyType() === 'int';

        $rows = $query->select([$keyName])->get();

        $ids = [];
        foreach ($rows as $row) {
            $value = $row->{$keyName} ?? null;
            if ($value === null) {
                continue;
            }
            $ids[] = $isIntKey ? (int) $value : (string) $value;
        }

        return $ids;
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
        // FOR UPDATE on the parent row serialises concurrent appenders
        // against the same parent — without it, two callers read the
        // same parent.rgt and both insert at that slot. The lock is
        // held for the rest of the enclosing transaction (auto-on by
        // default), so the gap-shift UPDATE that follows runs while
        // the next appender is still blocked on the same SELECT.
        $parentBounds = $this->freshBoundsOf($parent, lockForUpdate: true);
        $position = $parentBounds->rgt;
        $newDepth = $parentBounds->depth + 1;
        $newParentId = $this->keyOf($parent);

        $this->positionAt($position, $newDepth, $newParentId);
    }

    private function actPrependTo(Model&HasNestedSet $parent): void
    {
        $parentBounds = $this->freshBoundsOf($parent, lockForUpdate: true);
        $position = $parentBounds->lft + 1;
        $newDepth = $parentBounds->depth + 1;
        $newParentId = $this->keyOf($parent);

        $this->positionAt($position, $newDepth, $newParentId);
    }

    private function actSibling(Model&HasNestedSet $sibling, Position $position): void
    {
        if ($this->exists && $sibling->getKey() === $this->getKey()) {
            throw new LogicException('Cannot position node as a sibling of itself.');
        }

        // Read bounds AND parent_id from the same fresh row. Using the
        // sibling's in-memory parent_id while taking fresh bounds would
        // nest the new node under the sibling's current parent but stamp
        // parent_id with the stale one — and parent_id is the source of
        // truth, so a later fixTree() would silently relocate the node.
        $fresh = $this->newTreeMutator()->getPlainNodeData($this->keyOf($sibling), lockForUpdate: true);
        $insertAt = $position === Position::Before ? $fresh['lft'] : $fresh['rgt'] + 1;
        $newDepth = $fresh['depth'];
        $newParentId = $fresh['parent_id'];

        $this->positionAt($insertAt, $newDepth, $newParentId);
    }

    /**
     * `deleted` lifecycle hook: close the lft/rgt gap a hard-delete
     * leaves behind so the table's bounds stay a contiguous 1..2N
     * permutation per root.
     *
     * Only fires when the row was actually removed from the DB
     * (hard delete — `$this->exists === false`). Soft-deleted rows
     * still occupy their slots, so closing the gap would invalidate
     * their bounds. For interior hard-deletes the cascade
     * ({@see applyForceDeleteCascade}) clears every descendant
     * first, so the entire subtree's width is what we close.
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

        // An unplaced row (lft = rgt = 0) never reserved a slot, so there
        // is no gap to close. closeGap(0, 1) would instead shift every
        // placed row in the scope down by one — driving root bounds toward
        // zero and eventually negative on repeats.
        if ($lft < 1 || $rgt <= $lft) {
            return;
        }

        $this->newTreeMutator()->closeGap($lft, $rgt - $lft + 1);
    }

    /**
     * `deleted` lifecycle hook: hard-delete every descendant of this
     * node in the same scope so a `forceDelete()` on an interior
     * node behaves like the soft-delete cascade — no orphans, no
     * holes in the lft/rgt sequence after
     * {@see applyStructuralCleanupOnDelete} runs.
     *
     * Issues a raw query-builder DELETE (no Eloquent events for the
     * descendants), mirroring {@see HasSoftDeleteTree::applySoftDeleteCascade}.
     * The root row has already been deleted by the time this runs,
     * so we use its in-memory bounds to scope the query.
     */
    public function applyForceDeleteCascade(): void
    {
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

        if ($rgt - $lft === 1) {
            return;
        }

        $scope = NestedSetScopeResolver::valuesFor($this);
        $bounds = new NodeBounds(lft: $lft, rgt: $rgt, depth: $this->getDepth());

        $query = $this->getConnection()
            ->table($this->getTable())
            ->where($this->getLftName(), '>', $lft)
            ->where($this->getRgtName(), '<', $rgt);

        foreach ($scope as $column => $value) {
            $query->where($column, '=', $value);
        }

        $descendantIds = EventDispatcher::hasListeners(SubtreeForceDeleting::class)
            || EventDispatcher::hasListeners(SubtreeForceDeleted::class)
            ? $this->collectForceDeleteDescendantIds($lft, $rgt, $scope)
            : [];

        EventDispatcher::dispatch(new SubtreeForceDeleting(
            modelClass: static::class,
            anchor: $this,
            bounds: $bounds,
            scope: $scope,
            descendantIds: $descendantIds,
        ));

        $affected = $query->delete();

        EventDispatcher::dispatch(new SubtreeForceDeleted(
            modelClass: static::class,
            anchor: $this,
            bounds: $bounds,
            scope: $scope,
            descendantIds: $descendantIds,
            descendantsAffected: $affected,
        ));
    }

    /**
     * Reads descendant primary keys via a SELECT bounded by the
     * same lft/rgt window the cascade DELETE uses. Cheap on the
     * bounds index; gated by {@see EventDispatcher::enabled()} at
     * the call site so disabled telemetry pays no extra query.
     *
     * @param  array<string, mixed>  $scope
     * @return list<int|string>
     */
    private function collectForceDeleteDescendantIds(int $lft, int $rgt, array $scope): array
    {
        $query = $this->getConnection()
            ->table($this->getTable())
            ->where($this->getLftName(), '>', $lft)
            ->where($this->getRgtName(), '<', $rgt);

        foreach ($scope as $column => $value) {
            $query->where($column, '=', $value);
        }

        $keyName = $this->getKeyName();
        $isIntKey = $this->getKeyType() === 'int';

        $rows = $query->select([$keyName])->get();

        $ids = [];
        foreach ($rows as $row) {
            $value = $row->{$keyName} ?? null;
            if ($value === null) {
                continue;
            }
            $ids[] = $isIntKey ? (int) $value : (string) $value;
        }

        return $ids;
    }

    private function actMakeRoot(): void
    {
        $driver = $this->getConnection()->getDriverName();

        // Scope the max-rgt lookup to this node's scope. Without the
        // scope filter, the second scope's first root would land past
        // the first scope's rgt and silently break per-scope lft/rgt
        // independence. Caught by the scope-isolation fuzzer.
        $query = $this->newQuery()->getQuery();
        foreach (NestedSetScopeResolver::valuesFor($this) as $col => $value) {
            $query->where($col, $value);
        }

        // Serialise concurrent makeRoot calls in the same scope by
        // locking the row that currently owns the max rgt. Without
        // the lock, two parallel callers could read the same max and
        // both insert at the same lft/rgt slot — a duplicate_lft /
        // duplicate_rgt corruption.
        //
        // PostgreSQL rejects FOR UPDATE on aggregate queries
        // (SQLSTATE 0A000), so we can't `lockForUpdate()->max()`
        // directly. Locking the single row with the highest rgt via
        // ORDER BY ... LIMIT 1 FOR UPDATE returns the same value and
        // works on every backend that supports row locking.
        // SQLite is single-writer; skip the lock there.
        if ($driver === 'sqlite') {
            $rawMax = $query->max($this->getRgtName());
        } else {
            $rawMax = $query
                ->orderBy($this->getRgtName(), 'desc')
                ->limit(1)
                ->lockForUpdate()
                ->value($this->getRgtName());
        }

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
    private function positionAt(int $position, int $newDepth, int|string|null $newParentId): void
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
        // callPendingAction already read it for the before-move hook /
        // events; reuse that read instead of issuing a second SELECT.
        $from = $this->pendingMoveFromBounds ?? $mutator->getNodeData($this->keyOf($this));
        $this->pendingMoveFromBounds = null;
        $depthDelta = $newDepth - $from->depth;

        $mutator->moveNode($from, $position, $depthDelta);

        // Re-read this node's new lft/rgt/depth — moveNode shifts many rows
        // at once via CASE WHEN, so we can't derive them locally without
        // duplicating the algorithm.
        $newBounds = $mutator->getPlainNodeData($this->keyOf($this));

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
    private function freshBoundsOf(Model&HasNestedSet $other, bool $lockForUpdate = false): NodeBounds
    {
        $mutator = $this->newTreeMutator();

        return $mutator->getNodeData($this->keyOf($other), $lockForUpdate);
    }

    /**
     * Re-reads this node's maintained aggregate columns from the DB into
     * the in-memory model. Delta maintenance writes these columns via
     * raw SQL and never syncs the model, so without this a held instance
     * carries stale totals that the before-move hook would transfer to
     * the new ancestor chain. Syncs originals so the columns stay clean
     * for dirty tracking.
     */
    private function refreshMaintainedAggregateColumns(): void
    {
        $columns = AggregateRegistry::maintainedColumnsFor(static::class);
        if ($columns === []) {
            return;
        }

        $row = $this->getConnection()
            ->table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->first($columns);

        if ($row === null) {
            return;
        }

        foreach ($columns as $column) {
            $this->setAttribute($column, $row->{$column});
            $this->syncOriginalAttribute($column);
        }
    }

    /**
     * Returns the model's primary key value typed as int or string —
     * matches what Eloquent's `$keyType` declares. Throws if a model
     * is unsaved (null PK), so callers don't accidentally feed null
     * to a parent_id slot or builder lookup.
     */
    private function keyOf(Model $node): int|string
    {
        $key = $node->getKey();

        if (is_int($key) || is_string($key)) {
            return $key;
        }

        throw new LogicException('NestedSet anchor has no primary key — was it saved?');
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
     *
     * For roots (`parent_id IS NULL`) the parent predicate is not
     * scope-isolating on its own — every scope has its own NULL-parent
     * roots and `makeRoot()` restarts lft/rgt at 1 per scope, so two
     * scopes can independently produce roots whose bounds collide.
     * The scope predicates added here keep the lookup inside this
     * node's own partition.
     */
    public function prevSibling(): ?static
    {
        $parentId = $this->getParentId();

        $query = $this->newQuery();

        if ($parentId === null) {
            $query->whereNull($this->getParentIdName());
            foreach (NestedSetScopeResolver::valuesFor($this) as $col => $value) {
                $query->where($col, $value);
            }
        } else {
            $query->where($this->getParentIdName(), $parentId);
        }

        /** @var static|null $result */
        $result = $query->where($this->getRgtName(), '=', $this->getLft() - 1)->first();

        return $result;
    }

    /**
     * Sibling immediately after this node (same parent, next-larger lft).
     *
     * See {@see prevSibling()} for the per-scope filter rationale on
     * root siblings.
     */
    public function nextSibling(): ?static
    {
        $parentId = $this->getParentId();

        $query = $this->newQuery();

        if ($parentId === null) {
            $query->whereNull($this->getParentIdName());
            foreach (NestedSetScopeResolver::valuesFor($this) as $col => $value) {
                $query->where($col, $value);
            }
        } else {
            $query->where($this->getParentIdName(), $parentId);
        }

        /** @var static|null $result */
        $result = $query->where($this->getLftName(), '=', $this->getRgt() + 1)->first();

        return $result;
    }
}
