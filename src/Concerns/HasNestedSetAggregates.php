<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\ChangeFeed\ChangeFeedRecorder;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Lazy\LazyAggregateAccess;
use Vusys\NestedSet\Aggregates\Lifecycle\CapturedMutation;
use Vusys\NestedSet\Aggregates\Lifecycle\CreateHookApplier;
use Vusys\NestedSet\Aggregates\Lifecycle\DeleteHookApplier;
use Vusys\NestedSet\Aggregates\Lifecycle\DeltaCapture;
use Vusys\NestedSet\Aggregates\Lifecycle\MoveHookApplier;
use Vusys\NestedSet\Aggregates\Lifecycle\RestoreHookApplier;
use Vusys\NestedSet\Aggregates\Listeners\ListenerCalculator;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Repair\AggregateAnchor;
use Vusys\NestedSet\Aggregates\Repair\AggregateRepair;
use Vusys\NestedSet\Aggregates\Repair\DeferredMaintenanceRunner;
use Vusys\NestedSet\Contracts\AggregateDefinitionContract;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Jobs\FixAggregatesJob;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Query\Aggregates\Read\FreshAggregateProjector;

/**
 * Model-level read and maintenance methods for precalculated aggregate
 * columns. Thin dispatch surface over the subsystem helpers under
 * `src/Aggregates/`:
 *
 *  - {@see LazyAggregateAccess} — read-side `getAttribute()`
 *    interception and write-side ancestor invalidation.
 *  - {@see ChangeFeedRecorder}
 *    — per-row, per-column change-feed snapshot/diff/dispatch.
 *  - {@see ListenerCalculator} — PHP fresh-read and chain-recompute
 *    for `#[NestedSetAggregateListener]` columns.
 *  - {@see DeltaCapture} — `saving` → `saved` source-update pipeline.
 *  - {@see CreateHookApplier} / {@see DeleteHookApplier} /
 *    {@see MoveHookApplier} / {@see RestoreHookApplier} — lifecycle
 *    hook bodies.
 *  - {@see AggregateRepair} / {@see DeferredMaintenanceRunner} —
 *    public repair surface.
 *
 * The trait owns three pieces of state that the helpers either receive
 * by reference or read through the trait's accessors:
 *
 *  - {@see self::$deferredDepth} — per-class deferral counter for
 *    {@see DeferredMaintenanceRunner::withDeferred()}. A `private
 *    static` on a trait gives every using class its own counter.
 *  - {@see self::$refreshingLazyAggregate} — per-instance re-entry
 *    guard for {@see self::getAttribute()} so the lazy refresh path
 *    doesn't recurse when it reads the source / stamp / scope
 *    attributes via `parent::getAttribute()`.
 *  - {@see self::$aggregateMutation} — the {@see CapturedMutation}
 *    bag holding the seven `saving`-time captured deltas / extremes /
 *    recomputes plus the change-feed pre-snapshot, consumed by
 *    {@see DeltaCapture::apply()} in the `saved` hook.
 *
 * @mixin Model
 * @mixin HasNestedSet
 *
 * @phpstan-require-implements MaintainsTreeAggregates
 */
trait HasNestedSetAggregates
{
    /**
     * Per-class reentrancy depth for
     * {@see DeferredMaintenanceRunner::withDeferred()}. When > 0,
     * every lifecycle handler in this trait becomes a no-op; the
     * wrapper fires one `fixAggregates()` at the outermost exit
     * (success or failure) to repair the cumulative drift in one pass.
     */
    private static int $deferredDepth = 0;

    /**
     * Per-instance re-entry guard for {@see self::getAttribute()} so the
     * lazy-aggregate refresh path doesn't recurse when it reads the
     * source / stamp / scope attributes via `parent::getAttribute()`.
     */
    private bool $refreshingLazyAggregate = false;

    /**
     * Captured state for the current `saving` → `saved` cycle.
     * Lazy-initialised on first access; never null after first read.
     */
    private ?CapturedMutation $aggregateMutation = null;

    /**
     * The user-facing aggregate definitions declared on this model.
     * Excludes internal companions auto-promoted alongside AVG
     * declarations — those are an implementation detail of the
     * maintenance machinery, not part of the public read surface.
     *
     * @return list<AggregateDefinitionContract>
     */
    public function getAggregateDefinitions(): array
    {
        $userFacing = [];

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition->isInternal()) {
                $userFacing[] = $definition;
            }
        }

        return $userFacing;
    }

    /**
     * Override of {@see Model::getAttribute()} that intercepts reads of
     * lazy aggregate columns. When the requested column is declared
     * `lazy: true` and its stamp companion is NULL (or past its TTL),
     * the value is recomputed via {@see self::freshAggregate()},
     * written back to the row alongside `<column>_computed_at = NOW()`,
     * and reflected in the in-memory attributes before returning.
     */
    public function getAttribute($key)
    {
        // Mirror Eloquent's own falsy-key short-circuit. The lazy
        // discriminator below narrows $key to string for
        // LazyAggregateAccess::definitionForColumn(), so null / false
        // / 0 / '' / '0' must skip past it before that call.
        if (! $key) {
            return parent::getAttribute($key);
        }

        if (! $this->exists || $this->refreshingLazyAggregate) {
            return parent::getAttribute($key);
        }

        $definition = LazyAggregateAccess::definitionForColumn(static::class, $key);
        if (! $definition instanceof AggregateDefinitionContract) {
            return parent::getAttribute($key);
        }

        if (LazyAggregateAccess::isStale($definition, $this->getAttributes()) && $this->isPlacedInTree()) {
            $this->refreshingLazyAggregate = true;
            try {
                LazyAggregateAccess::refresh($this, $definition);
            } finally {
                $this->refreshingLazyAggregate = false;
            }
        }

        return parent::getAttribute($key);
    }

    /**
     * Recomputes the value of an aggregate column for this node.
     * For SQL-backed aggregates, runs a subquery against the source column.
     * For listener aggregates, evaluates the listener in PHP over the subtree.
     *
     * @throws AggregateConfigurationException when $column is not a
     *                                         declared aggregate on this model.
     */
    public function freshAggregate(string $column, bool $withTrashed = false): mixed
    {
        $definition = $this->resolveAggregateDefinitionByColumn($column);

        if ($definition instanceof AggregateDefinition) {
            $value = FreshAggregateProjector::scalar($this, $definition, $withTrashed);
        } elseif ($definition instanceof ListenerAggregateDefinition) {
            $value = ListenerCalculator::freshAggregate($this, $definition, $withTrashed);
        } else {
            throw new AggregateConfigurationException(sprintf(
                'Unsupported aggregate definition type %s for column "%s".',
                $definition::class,
                $column,
            ));
        }

        // Route the raw scalar through the model's cast for the column so
        // the result is type- and precision-stable across backends and
        // directly comparable to the stored attribute (the method's main
        // use is drift detection: `$stored !== $node->freshAggregate(...)`).
        // Without this, MySQL/PG return numerics as strings and a
        // decimal:N column would truncate the stored value while the fresh
        // recompute stays full-precision. NULL (empty subtree) stays NULL.
        if ($value !== null && $this->hasCast($column)) {
            return $this->castAttribute($column, $value);
        }

        return $value;
    }

    /**
     * `saving` hook (existing models only). Delegates to
     * {@see DeltaCapture::capture()}.
     */
    public function captureAggregateDeltas(): void
    {
        DeltaCapture::capture($this, $this->aggregateMutationState());
    }

    /**
     * `saved` hook: issue the delta UPDATE captured in
     * {@see captureAggregateDeltas()}. Delegates to
     * {@see DeltaCapture::apply()}.
     */
    public function applyAggregateDeltas(): void
    {
        DeltaCapture::apply($this, $this->aggregateMutationState());
    }

    /**
     * `created` hook: a newly-inserted node has just been placed in
     * the tree. Delegates to {@see CreateHookApplier::apply()}.
     */
    public function applyAggregateOnCreate(): void
    {
        CreateHookApplier::apply($this);
    }

    /**
     * `deleted` hook (hard and soft). Delegates to
     * {@see DeleteHookApplier::apply()}.
     */
    public function applyAggregateOnDelete(): void
    {
        DeleteHookApplier::apply($this);
    }

    /**
     * Path A "before-move" hook. Delegates to
     * {@see MoveHookApplier::applyBeforeMove()}.
     */
    public function applyAggregateBeforeMove(NodeBounds $from, string $action): void
    {
        MoveHookApplier::applyBeforeMove($this, $from);
    }

    /**
     * Path A "after-move" hook. Delegates to
     * {@see MoveHookApplier::applyAfterMove()}.
     */
    public function applyAggregateAfterMove(NodeBounds $from, NodeBounds $to, string $action): void
    {
        MoveHookApplier::applyAfterMove($this, $from, $to, $action);
    }

    /**
     * `restored` hook (soft delete only). Delegates to
     * {@see RestoreHookApplier::apply()}.
     */
    public function applyAggregateOnRestore(): void
    {
        RestoreHookApplier::apply($this);
    }

    /**
     * Overrides {@see Model::replicate()} so cloned models never
     * inherit the source's stored aggregate values OR its tree
     * position. Aggregate columns on the clone reset to the function's
     * "empty" element (0 for SUM / COUNT, NULL for AVG / MIN / MAX);
     * structural columns (`lft` / `rgt` / `depth` / `parent_id`) reset
     * to the migration default so the clone presents as "unplaced"
     * until the caller explicitly places it via
     * `appendToNode(...)->save()` or `makeRoot()->save()`.
     *
     * Also clears `deleted_at` so a clone of a trashed row starts
     * un-trashed — the clone is a template for a new placement, not a
     * continuation of the source's lifecycle.
     *
     * @param  list<string>|null  $except
     */
    public function replicate(?array $except = null): static
    {
        /** @var static $clone */
        $clone = parent::replicate($except);

        // Structural columns: leave the clone unplaced so a downstream
        // `->save()` without a placement call fails the
        // `isPlacedInTree()` check rather than corrupting the tree.
        $clone->setAttribute($this->getLftName(), 0);
        $clone->setAttribute($this->getRgtName(), 0);
        $clone->setAttribute($this->getDepthName(), 0);
        $clone->setAttribute($this->getParentIdName(), null);

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }
            $clone->setAttribute(
                $definition->column,
                $definition->function->nullableOnEmpty() ? null : 0,
            );
        }

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if ($definition instanceof ListenerAggregateDefinition) {
                $clone->setAttribute(
                    $definition->column,
                    (in_array($definition->operation, [AggregateFunction::Min, AggregateFunction::Max, AggregateFunction::Avg], true))
                        ? null
                        : 0,
                );
            }
        }

        if (($deletedAtColumn = AggregateAnchor::softDeleteColumn($this)) !== null) {
            $clone->setAttribute($deletedAtColumn, null);
        }

        return $clone;
    }

    /**
     * Returns per-column counts of stored aggregate columns that
     * disagree with their freshly-computed values over the source.
     * Empty array on a model with no aggregate declarations.
     *
     * @return array<string, int>
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function aggregateErrors(?HasNestedSet $anchor = null): array
    {
        return AggregateRepair::aggregateErrors(static::class, $anchor);
    }

    /**
     * True when any declared aggregate column has at least one row
     * whose stored value disagrees with the freshly-computed value.
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function aggregatesAreBroken(?HasNestedSet $anchor = null): bool
    {
        return array_sum(self::aggregateErrors($anchor)) > 0;
    }

    /**
     * Recomputes every declared aggregate column (including internal
     * AVG companions) from the source data and overwrites stored
     * values that have drifted. Returns a structured count per column.
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function fixAggregates(
        ?HasNestedSet $anchor = null,
        ?int $chunkSize = null,
        ?\Closure $onChunk = null,
    ): AggregateFixResult {
        return AggregateRepair::fixAggregates(static::class, $anchor, $chunkSize, $onChunk);
    }

    /**
     * Repairs a single chunk of stored aggregate columns and returns
     * the cursor to feed into the next chunk (or null if this was the
     * last). Used by {@see FixAggregatesJob} to break a long-running
     * repair into a series of short, self-re-dispatching jobs.
     *
     * @return array{result: AggregateFixResult, nextAfterId: int|string|null}
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function fixAggregatesChunk(
        ?HasNestedSet $anchor,
        int|string|null $afterId,
        int $chunkSize,
    ): array {
        return AggregateRepair::fixAggregatesChunk(static::class, $anchor, $afterId, $chunkSize);
    }

    /**
     * Runs `$work` with per-row aggregate maintenance suspended, then
     * fires one `fixAggregates($anchor)` at the outermost exit to
     * repair the cumulative drift in a single pass.
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function withDeferredAggregateMaintenance(
        \Closure $work,
        ?HasNestedSet $anchor = null,
    ): mixed {
        return DeferredMaintenanceRunner::withDeferred(static::class, $work, $anchor);
    }

    /**
     * Dispatches a {@see FixAggregatesJob} to repair stored aggregate
     * columns asynchronously.
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function queueFixAggregates(
        ?HasNestedSet $anchor = null,
        ?string $onConnection = null,
        ?string $onQueue = null,
        ?int $chunkSize = null,
    ): FixAggregatesJob {
        return DeferredMaintenanceRunner::queueFixAggregates(
            static::class,
            $anchor,
            $onConnection,
            $onQueue,
            $chunkSize,
        );
    }

    /**
     * Per-class deferral counter accessor. Called by
     * {@see DeferredMaintenanceRunner} and every helper that needs to
     * check whether per-row maintenance is currently suspended.
     *
     * @internal
     */
    public static function aggregateDeferredDepth(): int
    {
        return self::$deferredDepth;
    }

    /**
     * Increments the per-class deferral counter. Called only by
     * {@see DeferredMaintenanceRunner::withDeferred()}.
     *
     * @internal
     */
    public static function incrementAggregateDeferredDepth(): void
    {
        self::$deferredDepth++;
    }

    /**
     * Decrements the per-class deferral counter. Called only by
     * {@see DeferredMaintenanceRunner::withDeferred()} in its finally
     * block.
     *
     * @internal
     */
    public static function decrementAggregateDeferredDepth(): void
    {
        self::$deferredDepth--;
    }

    /**
     * Internal — called from {@see HasTreeRepair::fixTree()} after the
     * structural repair so stored aggregates match the rebuilt tree.
     * Cross-trait access works because both traits flatten into the
     * same using class, where private methods are mutually visible.
     */
    private static function runFixAggregates(
        ?HasNestedSet $anchor,
        int|string|null $rootId,
    ): ?AggregateFixResult {
        return AggregateRepair::runForFixTree(static::class, $anchor, $rootId);
    }

    /**
     * Lazy-initialise (and return) the captured-mutation state object
     * for the current `saving` → `saved` cycle.
     */
    private function aggregateMutationState(): CapturedMutation
    {
        return $this->aggregateMutation ??= new CapturedMutation;
    }

    private function resolveAggregateDefinitionByColumn(string $column): AggregateDefinitionContract
    {
        foreach (AggregateRegistry::for(static::class) as $definition) {
            if ($definition->getColumn() === $column) {
                return $definition;
            }
        }

        throw new AggregateConfigurationException(sprintf(
            '%s has no aggregate column "%s". '
            .'Declare it via #[NestedSetAggregate(...)] / #[NestedSetAggregateListener(...)] '
            .'or the method-override forms.',
            static::class,
            $column,
        ));
    }
}
