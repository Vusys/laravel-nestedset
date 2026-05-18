<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Vusys\NestedSet\Concerns\HasBulkInsert;
use Vusys\NestedSet\Concerns\HasNestedSetAggregates;
use Vusys\NestedSet\Concerns\HasNodeInspection;
use Vusys\NestedSet\Concerns\HasSoftDeleteTree;
use Vusys\NestedSet\Concerns\HasTreeMutation;
use Vusys\NestedSet\Concerns\HasTreeRelations;
use Vusys\NestedSet\Concerns\HasTreeRepair;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\AggregateMaintenanceFailed;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Query\TreeAggregateBuilder;
use Vusys\NestedSet\Query\TreeBaseQueryBuilder;
use Vusys\NestedSet\Query\TreeQueryBuilder;

/**
 * Adds the full nested-set API to an Eloquent model.
 *
 * Models that use this trait must also `implements HasNestedSet`. The
 * trait provides default implementations of all five interface methods
 * (getLft/getRgt/getDepth/getParentId/getBounds) so the contract is
 * satisfied out of the box; user code can override the column-name
 * accessors below to point at non-default columns.
 *
 * @mixin Model
 */
trait NodeTrait
{
    use HasBulkInsert;
    use HasNestedSetAggregates;
    use HasNodeInspection;
    use HasSoftDeleteTree;
    use HasTreeMutation;
    use HasTreeRelations;
    use HasTreeRepair;

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
     *                {@see HasNestedSetAggregates::isPlacedInTree()}).
     *  - `deleted` → applyAggregateOnDelete (subtract stored subtree
     *                contribution from ancestors). Fires for both hard
     *                and soft deletes; HasSoftDeleteTree's separate
     *                `deleted` listener cascades the timestamp to
     *                descendants.
     */
    public static function bootNodeTrait(): void
    {
        static::saving(static function (Model $node): void {
            if (! $node instanceof HasNestedSet) {
                return;
            }
            if (method_exists($node, 'callPendingAction')) {
                $node->callPendingAction();
            }
            if (method_exists($node, 'captureAggregateDeltas')) {
                self::runAggregateHook($node, 'capture', static fn () => $node->captureAggregateDeltas());
            }
        });

        static::saved(static function (Model $node): void {
            if ($node instanceof HasNestedSet && method_exists($node, 'applyAggregateDeltas')) {
                self::runAggregateHook($node, 'apply', static fn () => $node->applyAggregateDeltas());
            }
        });

        static::created(static function (Model $node): void {
            if ($node instanceof HasNestedSet && method_exists($node, 'applyAggregateOnCreate')) {
                self::runAggregateHook($node, 'on_create', static fn () => $node->applyAggregateOnCreate());
            }
        });

        static::deleting(static function (Model $node): void {
            if (! $node instanceof HasNestedSet) {
                return;
            }
            // Re-read structural columns (lft/rgt/depth/parent_id) so
            // the deleted hook below sees current values. The in-memory
            // attributes may have gone stale since this model was
            // loaded — e.g. an earlier closeGap from a sibling's hard
            // delete shifted this row's bounds in the DB but not in
            // memory. Aggregate maintenance and the closeGap step both
            // rely on accurate bounds.
            $key = $node->getKey();
            if ($key === null) {
                return;
            }
            $row = $node->getConnection()
                ->table($node->getTable())
                ->where($node->getKeyName(), $key)
                ->first([$node->getLftName(), $node->getRgtName(), $node->getDepthName(), $node->getParentIdName()]);
            if ($row === null) {
                return;
            }
            $node->setAttribute($node->getLftName(), $row->{$node->getLftName()});
            $node->setAttribute($node->getRgtName(), $row->{$node->getRgtName()});
            $node->setAttribute($node->getDepthName(), $row->{$node->getDepthName()});
            $node->setAttribute($node->getParentIdName(), $row->{$node->getParentIdName()});
            $node->syncOriginalAttribute($node->getLftName());
            $node->syncOriginalAttribute($node->getRgtName());
            $node->syncOriginalAttribute($node->getDepthName());
            $node->syncOriginalAttribute($node->getParentIdName());
        });

        static::deleted(static function (Model $node): void {
            if (! $node instanceof HasNestedSet) {
                return;
            }
            if (method_exists($node, 'applyAggregateOnDelete')) {
                self::runAggregateHook($node, 'on_delete', static fn () => $node->applyAggregateOnDelete());
            }
            // Compact lft/rgt for hard-delete-of-a-leaf so the bounds
            // sequence stays a contiguous 1..2N permutation. No-ops for
            // soft delete (row still exists) and interior force-delete
            // (children would shift into invalid positions).
            if (method_exists($node, 'applyStructuralCleanupOnDelete')) {
                $node->applyStructuralCleanupOnDelete();
            }
        });

        // Restored — soft-delete only. Aggregates were decremented by
        // applyAggregateOnDelete; add them back. HasSoftDeleteTree's
        // separate `restored` listener cascades timestamps to
        // descendants in parallel.
        static::registerModelEvent('restored', static function (Model $node): void {
            if ($node instanceof HasNestedSet && method_exists($node, 'applyAggregateOnRestore')) {
                self::runAggregateHook($node, 'on_restore', static fn () => $node->applyAggregateOnRestore());
            }
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
                anchorId: is_numeric($nodeKey) ? (int) $nodeKey : null,
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

    public function getParentId(): ?int
    {
        $v = $this->getAttribute($this->getParentIdName());

        if ($v === null) {
            return null;
        }

        return $this->intAttr($this->getParentIdName());
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
     * used by {@see TreeAggregateBuilder::applyMariaDbDerivedFreshSelects()})
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
