<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Concerns\HasBulkInsert;
use Vusys\NestedSet\Concerns\HasMaterialisedPath;
use Vusys\NestedSet\Concerns\HasNestedSetAggregates;
use Vusys\NestedSet\Concerns\HasNodeInspection;
use Vusys\NestedSet\Concerns\HasSoftDeleteTree;
use Vusys\NestedSet\Concerns\HasSubtreeClone;
use Vusys\NestedSet\Concerns\HasTreeExport;
use Vusys\NestedSet\Concerns\HasTreeMutation;
use Vusys\NestedSet\Concerns\HasTreeRelations;
use Vusys\NestedSet\Concerns\HasTreeRepair;
use Vusys\NestedSet\Concerns\HasTreeWalk;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\Events\Aggregates\AggregateMaintenanceFailed;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Exceptions\MisconfiguredNodeException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Exceptions\UnplacedNodeException;
use Vusys\NestedSet\Query\Aggregates\Read\FreshAggregateProjector;
use Vusys\NestedSet\Query\TreeBaseQueryBuilder;
use Vusys\NestedSet\Query\TreeQueryBuilder;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Adds the full nested-set API to an Eloquent model.
 *
 * Models that use this trait must also `implements MaintainsTreeAggregates`
 * (which itself extends {@see HasNestedSet}). The
 * trait provides default implementations of all five interface methods
 * (getLft/getRgt/getDepth/getParentId/getBounds) so the contract is
 * satisfied out of the box; user code can override the column-name
 * accessors below to point at non-default columns.
 *
 * @mixin Model
 *
 * @phpstan-require-implements MaintainsTreeAggregates
 */
trait NodeTrait
{
    use HasBulkInsert;
    use HasMaterialisedPath;
    use HasNestedSetAggregates;
    use HasNodeInspection;
    use HasSoftDeleteTree;
    use HasSubtreeClone;
    use HasTreeExport;
    use HasTreeMutation;
    use HasTreeRelations;
    use HasTreeRepair;
    use HasTreeWalk;

    /**
     * Wires every Eloquent lifecycle event the package consumes.
     *
     *  - `saving`  → callPendingAction (Path A move/insert dispatch)
     *                and captureAggregateDeltas (Path B source-column
     *                dirty-tracking; existing models only).
     *  - `saved`   → applyAggregateDeltas (issues the captured UPDATE).
     *  - `created` → applyAggregateOnCreate (push fresh node's
     *                contribution to ancestors; skipped when the node
     *                has not been placed in the tree yet, see
     *                {@see HasNestedSet::isPlacedInTree()}).
     *  - `deleted` → applyAggregateOnDelete (subtract stored subtree
     *                contribution from ancestors). Fires for both hard
     *                and soft deletes; HasSoftDeleteTree's separate
     *                `deleted` listener cascades the timestamp to
     *                descendants.
     */
    public static function bootNodeTrait(): void
    {
        static::saving(static function (Model $node): void {
            // A NodeTrait model that reaches this listener must implement
            // MaintainsTreeAggregates — the trait supplies every method
            // the contract demands, so the only way to fail the check is
            // to omit `implements MaintainsTreeAggregates` from the class.
            // Historically this was a silent early-return, which meant the
            // placement lifecycle (callPendingAction below) never ran and
            // the node was inserted with lft = rgt = 0 (an invalid_bounds
            // corruption) with no error. Throw instead so the
            // misconfiguration surfaces at the first save rather than as
            // mysterious zero-bounds rows. bulkInsertTree() masks the bug
            // because it sets the bounds attributes directly.
            if (! $node instanceof MaintainsTreeAggregates) {
                throw new MisconfiguredNodeException(sprintf(
                    '%s uses %s but does not implement %s. Add "implements '
                    .'MaintainsTreeAggregates" to the class declaration — '
                    .'without it the placement lifecycle '
                    .'(appendToNode/makeRoot/saveAsRoot) silently does nothing '
                    .'and nodes are saved with lft = rgt = 0.',
                    $node::class,
                    NodeTrait::class,
                    MaintainsTreeAggregates::class,
                ));
            }
            // Guard against silent cross-scope corruption: changing a
            // scope column on an existing node makes the scoped mutation
            // SQL target the WRONG partition (the in-memory scope) while
            // the disk row still lives in the old one — shifting
            // bystanders in both trees with no error. The delete path was
            // already hardened against this; the save/move path was not.
            // Cross-tree moves are not supported via a column edit; the
            // documented recipe is delete + re-insert under the new tree.
            if ($node->exists) {
                foreach (NestedSetScopeResolver::columns($node::class) as $scopeColumn) {
                    if ($node->isDirty($scopeColumn)) {
                        throw new ScopeViolationException(sprintf(
                            '%s: scope column "%s" was changed on an existing node. '
                            .'Moving a node between trees by editing its scope column corrupts both trees '
                            .'(the scoped mutation runs against the in-memory scope while the row is still '
                            .'in the old one). Delete the node and re-insert it under the new tree instead.',
                            $node::class,
                            $scopeColumn,
                        ));
                    }
                }
            }
            if (method_exists($node, 'callPendingAction')) {
                $node->callPendingAction();
            }
            // New nodes must have been placed in the tree by this point —
            // either by a pending operation that just ran (appendToNode,
            // makeRoot, etc.) or by bulkInsertTree setting lft/rgt
            // directly. Otherwise the INSERT would land with lft=rgt=0,
            // producing an invalid_bounds corruption. Catches the common
            // footguns: Model::create([...]) without placement, and
            // ->save() on an unplaced replicate() clone.
            if (! $node->exists && ! $node->isPlacedInTree()) {
                throw new UnplacedNodeException(sprintf(
                    'Cannot save %s without placing it in the tree first. '
                    .'Call appendToNode($parent), prependToNode($parent), '
                    .'insertBeforeNode($sibling), insertAfterNode($sibling), '
                    .'or makeRoot() before save().',
                    $node::class,
                ));
            }
            if (method_exists($node, 'applyMaterialisedPathsOnSaving')) {
                $node->applyMaterialisedPathsOnSaving();
            }
            self::runAggregateHook($node, 'capture', static fn () => $node->captureAggregateDeltas());
        });

        static::saved(static function (Model $node): void {
            if (! $node instanceof MaintainsTreeAggregates) {
                return;
            }
            self::runAggregateHook($node, 'apply', static fn () => $node->applyAggregateDeltas());
            if (method_exists($node, 'applyMaterialisedPathsOnSaved')) {
                $node->applyMaterialisedPathsOnSaved();
            }
        });

        static::created(static function (Model $node): void {
            if ($node instanceof MaintainsTreeAggregates) {
                self::runAggregateHook($node, 'on_create', static fn () => $node->applyAggregateOnCreate());
            }
        });

        static::deleting(static function (Model $node): void {
            if (! $node instanceof MaintainsTreeAggregates) {
                return;
            }
            // Re-read structural columns (lft/rgt/depth/parent_id) and
            // any declared scope columns so the deleted hook below sees
            // current values. The in-memory attributes may have gone
            // stale since this model was loaded — e.g. an earlier
            // closeGap from a sibling's hard delete shifted this row's
            // bounds, or the caller mutated a scope attribute without
            // saving. Aggregate maintenance, the cascade query, and the
            // closeGap step all rely on persisted values.
            $key = $node->getKey();
            if ($key === null) {
                return;
            }
            $structuralColumns = [
                $node->getLftName(),
                $node->getRgtName(),
                $node->getDepthName(),
                $node->getParentIdName(),
            ];
            $scopeColumns = NestedSetScopeResolver::columns($node::class);
            // Aggregate display/companion columns too: the deleted hook
            // subtracts this node's stored aggregate values from its
            // ancestors, but delta maintenance updates those columns via
            // raw SQL without syncing the model — a held-then-deleted
            // instance would otherwise subtract a stale total.
            $aggregateColumns = AggregateRegistry::maintainedColumnsFor($node::class);
            $columnsToRead = array_values(array_unique(array_merge(
                $structuralColumns,
                $scopeColumns,
                $aggregateColumns,
            )));
            $connection = $node->getConnection();
            $query = $connection
                ->table($node->getTable())
                ->where($node->getKeyName(), $key);
            // Lock the row for the rest of the delete's transaction so a
            // concurrent gap-shift (a sibling's closeGap, a move) can't
            // slide this row's bounds between this re-read and the cascade
            // / closeGap that act on them — otherwise the delete closes the
            // wrong gap or cascades over the wrong band. Mirrors the
            // FOR UPDATE discipline on every other stale-bounds re-read.
            // SQLite is single-writer (no row locks); outside a transaction
            // the lock would release immediately, so only take it when one
            // is open (delete() wraps itself when auto_transaction is on).
            if ($connection->getDriverName() !== 'sqlite' && $connection->transactionLevel() > 0) {
                $query->lockForUpdate();
            }
            $row = $query->first($columnsToRead);
            if ($row === null) {
                return;
            }
            foreach ($columnsToRead as $column) {
                $node->setAttribute($column, $row->{$column});
                $node->syncOriginalAttribute($column);
            }
        });

        static::deleted(static function (Model $node): void {
            if (! $node instanceof MaintainsTreeAggregates) {
                return;
            }

            // Soft-delete cascade FIRST so descendants are marked
            // trashed before any aggregate chain-recompute runs.
            // Otherwise listener Min/Max recomputes (and exclusive
            // aggregates) read a stale "still-live" descendant set and
            // produce values that don't match the post-cascade state.
            if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
                HasSoftDeleteTree::applySoftDeleteCascade($node);
            }

            // Hard-delete cascade: clear every descendant from the
            // table before the aggregate decrement runs. Mirrors the
            // soft-delete cascade so chain recomputes (listener
            // Min/Max, exclusive aggregates) see the post-cascade
            // state, and so applyStructuralCleanupOnDelete can close
            // the entire subtree gap rather than leaving orphans
            // stranded in a vanished range.
            if (! $node->exists && method_exists($node, 'applyForceDeleteCascade')) {
                $node->applyForceDeleteCascade();
            }

            // forceDelete on a row that was already soft-deleted fires
            // this hook a second time. Its aggregate contribution was
            // removed at the original soft-delete; running the hook
            // again would double-decrement every ancestor.
            //
            // Resolve the soft-delete column dynamically so models that
            // override `const DELETED_AT` still trigger the guard.
            $deletedAtColumn = in_array(SoftDeletes::class, class_uses_recursive(static::class), true)
                ? (new \ReflectionMethod($node, 'getDeletedAtColumn'))->invoke($node)
                : null;
            $alreadyTrashed = method_exists($node, 'isForceDeleting')
                && $node->isForceDeleting()
                && is_string($deletedAtColumn)
                && $node->getAttribute($deletedAtColumn) !== null;

            if (! $alreadyTrashed) {
                self::runAggregateHook($node, 'on_delete', static fn () => $node->applyAggregateOnDelete());
            }
            // Compact lft/rgt for any hard-delete so the bounds
            // sequence stays a contiguous 1..2N permutation. No-op for
            // soft delete (row still exists). The cascade above
            // cleared every descendant first for interior nodes, so
            // closing the entire subtree gap here is safe.
            if (method_exists($node, 'applyStructuralCleanupOnDelete')) {
                $node->applyStructuralCleanupOnDelete();
            }
        });

        // Restored — soft-delete only. Aggregates were decremented by
        // applyAggregateOnDelete; add them back here. Cascade-restore
        // must run *before* the aggregate hook so chain recomputes see
        // descendants in their final (post-cascade) live state.
        static::registerModelEvent('restored', static function (Model $node): void {
            if (! $node instanceof MaintainsTreeAggregates) {
                return;
            }
            if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
                HasSoftDeleteTree::applyRestoreCascade($node);
            }
            self::runAggregateHook($node, 'on_restore', static fn () => $node->applyAggregateOnRestore());
        });
    }

    /**
     * Runs one of the trait's aggregate-maintenance hooks, firing
     * {@see AggregateMaintenanceFailed} if it throws so observability
     * tooling (Sentry, Bugsnag, etc.) sees the failure even though the
     * exception still propagates up to roll back the wrapping
     * transaction. `$stage` is one of: 'capture', 'apply',
     * 'on_create', 'on_delete', 'on_restore'.
     */
    private static function runAggregateHook(Model $node, string $stage, \Closure $hook): void
    {
        try {
            $hook();
        } catch (\Throwable $e) {
            $nodeKey = $node->getKey();
            EventDispatcher::dispatch(new AggregateMaintenanceFailed(
                modelClass: $node::class,
                anchorId: is_int($nodeKey) || is_string($nodeKey) ? $nodeKey : null,
                stage: $stage,
                exception: $e,
            ));

            throw $e;
        }
    }

    // ----------------------------------------------------------------
    // HasNestedSet defaults (column accessors)
    // ----------------------------------------------------------------

    public function getLft(): int
    {
        return $this->intAttr($this->getLftName());
    }

    public function getRgt(): int
    {
        return $this->intAttr($this->getRgtName());
    }

    public function getDepth(): int
    {
        return $this->intAttr($this->getDepthName());
    }

    public function getParentId(): int|string|null
    {
        $v = $this->getAttribute($this->getParentIdName());

        if ($v === null) {
            return null;
        }

        if ($this->getKeyType() === 'int') {
            return $this->intAttr($this->getParentIdName());
        }

        if (is_string($v)) {
            return $v;
        }

        if (is_int($v) || is_float($v) || (is_object($v) && method_exists($v, '__toString'))) {
            return (string) $v;
        }

        throw new \LogicException(sprintf(
            'parent_id attribute on %s is not int/string/stringable; got %s.',
            static::class,
            get_debug_type($v),
        ));
    }

    /**
     * Reads an attribute that we expect to be numeric (the model's casts
     * guarantee this in practice) and returns it as int — narrows mixed
     * for the type system without an unchecked cast.
     */
    private function intAttr(string $name): int
    {
        $v = $this->getAttribute($name);

        if (is_int($v)) {
            return $v;
        }

        if (is_numeric($v)) {
            return (int) $v;
        }

        throw new \LogicException("Attribute {$name} is not numeric");
    }

    public function getBounds(): NodeBounds
    {
        return new NodeBounds(
            lft: $this->getLft(),
            rgt: $this->getRgt(),
            depth: $this->getDepth(),
        );
    }

    public function getLftName(): string
    {
        $v = config('nestedset.columns.lft');

        return is_string($v) ? $v : Columns::LFT;
    }

    public function getRgtName(): string
    {
        $v = config('nestedset.columns.rgt');

        return is_string($v) ? $v : Columns::RGT;
    }

    public function getParentIdName(): string
    {
        $v = config('nestedset.columns.parent_id');

        return is_string($v) ? $v : Columns::PARENT_ID;
    }

    public function getDepthName(): string
    {
        $v = config('nestedset.columns.depth');

        return is_string($v) ? $v : Columns::DEPTH;
    }

    // ----------------------------------------------------------------
    // Eloquent overrides
    // ----------------------------------------------------------------

    /**
     * Narrowed return type (TreeQueryBuilder rather than the base
     * Eloquent Builder) so Larastan can resolve tree-specific methods —
     * whereDescendantOf, withDepth, withFreshAggregates, etc. — on
     * `Model::query()` results. Returning the base Builder causes
     * Larastan to forward calls to it and miss every package method
     * that does not happen to match its `where*` dynamic-where pattern.
     *
     * Generic parameter is left open ({@see Model}) because
     * `new TreeQueryBuilder($query)` cannot bind the template to the
     * concrete subclass — Larastan resolves per-model methods through
     * its own builder-helper machinery instead.
     *
     * @param  Builder  $query
     * @return TreeQueryBuilder<Model>
     */
    public function newEloquentBuilder($query): TreeQueryBuilder
    {
        return new TreeQueryBuilder($query);
    }

    /**
     * Returns the package's custom base query builder so SQL-execution
     * hooks (e.g. the MariaDB `SET STATEMENT optimizer_switch=…` prefix
     * used by {@see FreshAggregateProjector::applyMariaDbDerivedFreshSelects()})
     * have somewhere to live. Falls back to the parent builder's
     * connection/grammar/processor wiring otherwise — identical to
     * Eloquent's default except for the concrete class.
     */
    protected function newBaseQueryBuilder(): TreeBaseQueryBuilder
    {
        $connection = $this->getConnection();

        return new TreeBaseQueryBuilder(
            $connection,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor(),
        );
    }

    /**
     * @param  array<int, static>  $models
     * @return NodeCollection<int, static>
     */
    public function newCollection(array $models = []): EloquentCollection
    {
        return new NodeCollection($models);
    }
}
