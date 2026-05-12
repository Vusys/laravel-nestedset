<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Strategy\DeltaMaintenance;
use Vusys\NestedSet\Aggregates\Strategy\RecomputeMaintenance;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Jobs\FixAggregatesJob;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Query\TreeAggregateBuilder;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Model-level read and maintenance methods for precalculated aggregate
 * columns.
 *
 * Phase B exposed the fresh-read path. Phase D adds the SUM/COUNT
 * delta-maintenance side: source-column updates (Path B), creates and
 * deletes route their cascades through the methods on this trait.
 * MIN/MAX/AVG and structural mutations (Path A) land in later phases.
 *
 * @mixin Model
 * @mixin HasNestedSet
 */
trait HasNestedSetAggregates
{
    /**
     * Source-column deltas captured in `saving` and applied in `saved`.
     * Keyed by aggregate column name (the SUM column receiving the delta).
     *
     * @var array<string, int>
     */
    private array $vusysCapturedAggregateDeltas = [];

    /**
     * Cheap-delta MIN/MAX candidates captured in `saving` (extension or
     * insert direction — i.e. the new value can only extend the
     * extremum, never invalidate it). Applied alongside Phase D's
     * deltas as `CASE WHEN ... THEN candidate ELSE stored END`.
     *
     * @var array<string, array{function: AggregateFunction, value: int}>
     */
    private array $vusysCapturedExtremes = [];

    /**
     * Recompute candidates captured in `saving` (lost-holder direction
     * — the change may have invalidated the stored extremum on some
     * ancestor). Applied via {@see RecomputeMaintenance} after the
     * delta UPDATE commits, filtered by `stored = previous_value` so
     * unaffected ancestors are skipped.
     *
     * @var array<string, array{function: AggregateFunction, source: string, filterValue: int}>
     */
    private array $vusysCapturedRecomputes = [];

    /**
     * The user-facing aggregate definitions declared on this model.
     * Excludes internal companions auto-promoted alongside AVG
     * declarations — those are an implementation detail of the
     * maintenance machinery, not part of the public read surface.
     *
     * @return list<AggregateDefinition>
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
     * Recomputes the value of an aggregate column for this node by
     * running a subquery against the source column. Reads the stored
     * column via `$model->{$column}`; this method always returns truth
     * from the data, which is why it is more expensive.
     *
     * @throws AggregateConfigurationException when $column is not a
     *                                         declared aggregate on this model.
     */
    public function freshAggregate(string $column): mixed
    {
        $definition = $this->resolveAggregateDefinition($column);

        return TreeAggregateBuilder::scalar($this, $definition);
    }

    /**
     * True when this node's lft/rgt have been assigned via a tree
     * placement (appendToNode, makeRoot, etc.). False for freshly-
     * constructed models whose bounds are still at the migration
     * default of 0. The `created` aggregate hook skips maintenance on
     * unplaced nodes so a bare `Area::create(...)` without placement
     * does not touch every ancestor row in the table.
     */
    public function isPlacedInTree(): bool
    {
        $lft = self::vusysNumeric($this->getAttribute($this->getLftName()));
        $rgt = self::vusysNumeric($this->getAttribute($this->getRgtName()));

        return $lft > 0 && $rgt > $lft;
    }

    /**
     * `saving` hook (existing models only): for each SUM aggregate over
     * a dirty source column, capture the signed delta. The capture is
     * applied to the ancestor chain by {@see applyAggregateDeltas()}
     * after the save commits.
     *
     * Inserts go through the `created` hook
     * ({@see applyAggregateOnCreate()}) instead — at `saving` time a
     * new model has no `getOriginal()` baseline, so dirty tracking
     * cannot distinguish "freshly set" from "changed".
     *
     * Phase D scope: SUM only. COUNT does not depend on the source
     * value so updates leave it untouched. MIN/MAX/AVG land in Phases
     * F/E.
     */
    public function captureAggregateDeltas(): void
    {
        $this->vusysCapturedAggregateDeltas = [];
        $this->vusysCapturedExtremes = [];
        $this->vusysCapturedRecomputes = [];

        if (! $this->exists) {
            return;
        }

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if ($definition->source === null) {
                continue;
            }
            if (! $this->isDirty($definition->source)) {
                continue;
            }

            $new = self::vusysNumeric($this->getAttribute($definition->source));
            $old = self::vusysNumeric($this->getOriginal($definition->source));
            $delta = $new - $old;

            if ($delta === 0) {
                continue;
            }

            if ($definition->function === AggregateFunction::Sum) {
                $this->vusysCapturedAggregateDeltas[$definition->column] = $delta;

                continue;
            }

            if ($definition->function === AggregateFunction::Max) {
                if ($delta > 0) {
                    // New value is larger — max can only stay or rise. Cheap delta.
                    $this->vusysCapturedExtremes[$definition->column] = [
                        'function' => AggregateFunction::Max,
                        'value' => $new,
                    ];
                } else {
                    // New value is smaller — old may have been the holder; recompute
                    // ancestors whose stored_max equals the old value.
                    $this->vusysCapturedRecomputes[$definition->column] = [
                        'function' => AggregateFunction::Max,
                        'source' => $definition->source,
                        'filterValue' => $old,
                    ];
                }

                continue;
            }

            if ($definition->function === AggregateFunction::Min) {
                if ($delta < 0) {
                    $this->vusysCapturedExtremes[$definition->column] = [
                        'function' => AggregateFunction::Min,
                        'value' => $new,
                    ];
                } else {
                    $this->vusysCapturedRecomputes[$definition->column] = [
                        'function' => AggregateFunction::Min,
                        'source' => $definition->source,
                        'filterValue' => $old,
                    ];
                }
            }
        }
    }

    /**
     * `saved` hook: issue the delta UPDATE captured in
     * {@see captureAggregateDeltas()}. Touches self + ancestors so the
     * node's own stored aggregate stays in sync alongside the rollup.
     */
    public function applyAggregateDeltas(): void
    {
        $deltas = $this->vusysCapturedAggregateDeltas;
        $extremes = $this->vusysCapturedExtremes;
        $recomputes = $this->vusysCapturedRecomputes;

        $this->vusysCapturedAggregateDeltas = [];
        $this->vusysCapturedExtremes = [];
        $this->vusysCapturedRecomputes = [];

        if ($deltas === [] && $extremes === [] && $recomputes === []) {
            return;
        }

        if (! $this->isPlacedInTree()) {
            // Defensive: existing-model updates on unplaced rows
            // (lft/rgt = 0) shouldn't propagate to every other row.
            return;
        }

        $scope = NestedSetScopeResolver::valuesFor($this);

        DeltaMaintenance::apply(
            connection: $this->getConnection(),
            table: $this->getTable(),
            lftCol: $this->getLftName(),
            rgtCol: $this->getRgtName(),
            bounds: $this->getBounds(),
            deltas: $deltas,
            includeSelf: true,
            scope: $scope,
            avgs: AggregateRegistry::avgCompanionsFor(static::class),
            extremes: $extremes,
        );

        if ($recomputes !== []) {
            $this->applyCapturedRecomputes($recomputes, $scope);
        }
    }

    /**
     * @param  array<string, array{function: AggregateFunction, source: string, filterValue: int}>  $recomputes
     * @param  array<string, mixed>  $scope
     */
    private function applyCapturedRecomputes(array $recomputes, array $scope): void
    {
        $columns = [];
        $filterEquals = [];

        foreach ($recomputes as $aggregateColumn => $spec) {
            $columns[] = [
                'column' => $aggregateColumn,
                'function' => $spec['function'],
                'source' => $spec['source'],
                'inclusive' => true,
            ];
            $filterEquals[$aggregateColumn] = $spec['filterValue'];
        }

        RecomputeMaintenance::apply(
            connection: $this->getConnection(),
            table: $this->getTable(),
            lftCol: $this->getLftName(),
            rgtCol: $this->getRgtName(),
            bounds: $this->getBounds(),
            columns: $columns,
            scope: $scope,
            filterEquals: $filterEquals,
            locking: self::vusysAggregateLockingMode(),
        );
    }

    /**
     * @return 'always'|'auto'|'never'
     */
    private static function vusysAggregateLockingMode(): string
    {
        $value = config('nestedset.aggregate_locking', 'auto');

        return match ($value) {
            'always' => 'always',
            'never' => 'never',
            default => 'auto',
        };
    }

    /**
     * `created` hook: a newly-inserted node has just been placed in
     * the tree (or it has not been — see {@see isPlacedInTree()} for
     * the guard). For each inclusive SUM/COUNT declaration, push the
     * node's contribution to its ancestor chain and to its own row in
     * one UPDATE.
     */
    public function applyAggregateOnCreate(): void
    {
        if (! $this->isPlacedInTree()) {
            return;
        }

        $deltas = [];
        $extremes = [];

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition->inclusive) {
                // Exclusive aggregates have different self-handling;
                // inclusive-only for now. Exclusive support arrives in Phase G.
                continue;
            }

            if ($definition->function === AggregateFunction::Sum && $definition->source !== null) {
                $value = self::vusysNumeric($this->getAttribute($definition->source));
                if ($value !== 0) {
                    $deltas[$definition->column] = $value;
                }

                continue;
            }

            if ($definition->function === AggregateFunction::Count) {
                $deltas[$definition->column] = 1;

                continue;
            }

            if (($definition->function === AggregateFunction::Max
                || $definition->function === AggregateFunction::Min)
                && $definition->source !== null
            ) {
                $value = self::vusysNumeric($this->getAttribute($definition->source));
                $extremes[$definition->column] = [
                    'function' => $definition->function,
                    'value' => $value,
                ];
            }
        }

        if ($deltas === [] && $extremes === []) {
            return;
        }

        DeltaMaintenance::apply(
            connection: $this->getConnection(),
            table: $this->getTable(),
            lftCol: $this->getLftName(),
            rgtCol: $this->getRgtName(),
            bounds: $this->getBounds(),
            deltas: $deltas,
            includeSelf: true,
            scope: NestedSetScopeResolver::valuesFor($this),
            avgs: AggregateRegistry::avgCompanionsFor(static::class),
            extremes: $extremes,
        );
    }

    /**
     * `deleted` hook (hard and soft): subtract this node's stored
     * subtree aggregate from each ancestor.
     *
     * For inclusive SUM/COUNT, `$node->{aggregate_column}` already
     * holds the inclusive subtree total — which is exactly what every
     * ancestor needs to lose. Self is excluded from the UPDATE: its
     * row is gone (hard delete) or being preserved with `deleted_at`
     * set (soft delete; the cascade in
     * {@see HasSoftDeleteTree::cascadeSoftDelete()} marks descendants
     * separately).
     */
    public function applyAggregateOnDelete(): void
    {
        if (! $this->isPlacedInTree()) {
            return;
        }

        $deltas = [];
        $minMaxRecomputes = [];

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition->inclusive) {
                continue;
            }

            if ($definition->function === AggregateFunction::Sum
                || $definition->function === AggregateFunction::Count) {
                $value = self::vusysNumeric($this->getAttribute($definition->column));
                if ($value !== 0) {
                    $deltas[$definition->column] = -$value;
                }

                continue;
            }

            if (($definition->function === AggregateFunction::Max
                || $definition->function === AggregateFunction::Min)
                && $definition->source !== null
            ) {
                // Cheap-skip: only recompute ancestors whose stored extremum
                // matches this node's stored extremum. Other ancestors held
                // their MIN/MAX from somewhere else; the deletion can't have
                // affected them.
                $stored = self::vusysNumeric($this->getAttribute($definition->column));
                $minMaxRecomputes[$definition->column] = [
                    'function' => $definition->function,
                    'source' => $definition->source,
                    'filterValue' => $stored,
                ];
            }
        }

        $scope = NestedSetScopeResolver::valuesFor($this);

        if ($deltas !== []) {
            DeltaMaintenance::apply(
                connection: $this->getConnection(),
                table: $this->getTable(),
                lftCol: $this->getLftName(),
                rgtCol: $this->getRgtName(),
                bounds: $this->getBounds(),
                deltas: $deltas,
                includeSelf: false,
                scope: $scope,
                avgs: AggregateRegistry::avgCompanionsFor(static::class),
            );
        }

        if ($minMaxRecomputes !== []) {
            $this->applyCapturedRecomputes($minMaxRecomputes, $scope);
        }
    }

    /**
     * Path A "before-move" hook: the OLD ancestor chain loses this
     * subtree's contribution. Runs while pre-move bounds are still
     * accurate — bounds-based WHERE clauses match the correct
     * ancestors.
     *
     * Called from {@see HasTreeMutation::onBeforePendingAction()}
     * inside the move's auto-transaction.
     */
    public function applyAggregateBeforeMove(NodeBounds $from, string $action): void
    {
        [$sumCountDeltas, $minMaxByFunction] = $this->collectMoveSubtreeContribution();

        if ($sumCountDeltas === [] && $minMaxByFunction === []) {
            return;
        }

        $scope = NestedSetScopeResolver::valuesFor($this);

        if ($sumCountDeltas !== []) {
            $negative = array_map(static fn (int $v): int => -$v, $sumCountDeltas);
            DeltaMaintenance::apply(
                connection: $this->getConnection(),
                table: $this->getTable(),
                lftCol: $this->getLftName(),
                rgtCol: $this->getRgtName(),
                bounds: $from,
                deltas: $negative,
                includeSelf: false,
                scope: $scope,
                avgs: AggregateRegistry::avgCompanionsFor(static::class),
            );
        }

        if ($minMaxByFunction !== []) {
            // Exclude self's pre-move subtree from the inner MIN/MAX
            // scan. The move SQL hasn't run yet, so A1's rows are still
            // physically present; we have to logically exclude them so
            // the recompute reflects the post-move ancestor state.
            $this->applyMoveRecomputes($from, $minMaxByFunction, $scope, excludeBounds: $from);
        }
    }

    /**
     * Path A "after-move" hook: the NEW ancestor chain gains this
     * subtree's contribution. Runs after the structural SQL has
     * shifted bounds — the new bounds are in place so bounds-based
     * WHEREs match the correct ancestors.
     *
     * Called from {@see HasTreeMutation::onAfterPendingAction()}
     * inside the move's auto-transaction.
     */
    public function applyAggregateAfterMove(NodeBounds $from, NodeBounds $to, string $action): void
    {
        [$sumCountDeltas, , $candidateExtremes] = $this->collectMoveSubtreeContribution();

        if ($sumCountDeltas === [] && $candidateExtremes === []) {
            return;
        }

        DeltaMaintenance::apply(
            connection: $this->getConnection(),
            table: $this->getTable(),
            lftCol: $this->getLftName(),
            rgtCol: $this->getRgtName(),
            bounds: $to,
            deltas: $sumCountDeltas,
            includeSelf: false,
            scope: NestedSetScopeResolver::valuesFor($this),
            avgs: AggregateRegistry::avgCompanionsFor(static::class),
            extremes: $candidateExtremes,
        );
    }

    /**
     * Walks the registry and collects what each aggregate path needs
     * from this node's stored values for a move:
     *
     *   [0] SUM/COUNT deltas (positive — caller negates for old chain)
     *   [1] MIN/MAX recompute specs (filter by self's stored extremum)
     *   [2] MIN/MAX cheap-delta candidates (extend new chain)
     *
     * Same data is read by both before- and after-move hooks; this
     * helper keeps them in sync.
     *
     * @return array{0: array<string, int>, 1: array<string, array{function: AggregateFunction, source: string, filterValue: int}>, 2: array<string, array{function: AggregateFunction, value: int}>}
     */
    private function collectMoveSubtreeContribution(): array
    {
        $sumCount = [];
        $minMaxRecomputes = [];
        $extremes = [];

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition->inclusive) {
                continue;
            }

            if ($definition->function === AggregateFunction::Sum
                || $definition->function === AggregateFunction::Count) {
                $value = self::vusysNumeric($this->getAttribute($definition->column));
                if ($value !== 0) {
                    $sumCount[$definition->column] = $value;
                }

                continue;
            }

            if (($definition->function === AggregateFunction::Max
                || $definition->function === AggregateFunction::Min)
                && $definition->source !== null
            ) {
                $stored = self::vusysNumeric($this->getAttribute($definition->column));
                $minMaxRecomputes[$definition->column] = [
                    'function' => $definition->function,
                    'source' => $definition->source,
                    'filterValue' => $stored,
                ];
                $extremes[$definition->column] = [
                    'function' => $definition->function,
                    'value' => $stored,
                ];
            }
        }

        return [$sumCount, $minMaxRecomputes, $extremes];
    }

    /**
     * @param  array<string, array{function: AggregateFunction, source: string, filterValue: int}>  $minMaxByFunction
     * @param  array<string, mixed>  $scope
     */
    private function applyMoveRecomputes(
        NodeBounds $bounds,
        array $minMaxByFunction,
        array $scope,
        ?NodeBounds $excludeBounds = null,
    ): void {
        $columns = [];
        $filterEquals = [];

        foreach ($minMaxByFunction as $aggregateColumn => $spec) {
            $columns[] = [
                'column' => $aggregateColumn,
                'function' => $spec['function'],
                'source' => $spec['source'],
                'inclusive' => true,
            ];
            $filterEquals[$aggregateColumn] = $spec['filterValue'];
        }

        RecomputeMaintenance::apply(
            connection: $this->getConnection(),
            table: $this->getTable(),
            lftCol: $this->getLftName(),
            rgtCol: $this->getRgtName(),
            bounds: $bounds,
            columns: $columns,
            scope: $scope,
            filterEquals: $filterEquals,
            locking: self::vusysAggregateLockingMode(),
            excludeBounds: $excludeBounds,
        );
    }

    /**
     * `restored` hook (soft delete only): the subtree's stored
     * aggregates were left intact during the cascade soft-delete, but
     * the ancestor chain's aggregates were decremented by
     * {@see applyAggregateOnDelete()}. On restore we add them back —
     * SUM/COUNT via delta, MIN/MAX via cheap-delta with the stored
     * subtree extremum as the candidate.
     */
    public function applyAggregateOnRestore(): void
    {
        if (! $this->isPlacedInTree()) {
            return;
        }

        $deltas = [];
        $extremes = [];

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition->inclusive) {
                continue;
            }

            if ($definition->function === AggregateFunction::Sum
                || $definition->function === AggregateFunction::Count) {
                $value = self::vusysNumeric($this->getAttribute($definition->column));
                if ($value !== 0) {
                    $deltas[$definition->column] = $value;
                }

                continue;
            }

            if (($definition->function === AggregateFunction::Max
                || $definition->function === AggregateFunction::Min)
                && $definition->source !== null
            ) {
                $value = self::vusysNumeric($this->getAttribute($definition->column));
                $extremes[$definition->column] = [
                    'function' => $definition->function,
                    'value' => $value,
                ];
            }
        }

        if ($deltas === [] && $extremes === []) {
            return;
        }

        DeltaMaintenance::apply(
            connection: $this->getConnection(),
            table: $this->getTable(),
            lftCol: $this->getLftName(),
            rgtCol: $this->getRgtName(),
            bounds: $this->getBounds(),
            deltas: $deltas,
            includeSelf: false,
            scope: NestedSetScopeResolver::valuesFor($this),
            avgs: AggregateRegistry::avgCompanionsFor(static::class),
            extremes: $extremes,
        );
    }

    /**
     * Overrides {@see Model::replicate()} so cloned models never
     * inherit the source's stored aggregate values. Aggregate columns
     * on the clone reset to the function's "empty" element (0 for
     * SUM / COUNT, NULL for AVG / MIN / MAX); on subsequent placement
     * the regular maintenance path computes correct values.
     *
     * @param  list<string>|null  $except
     */
    public function replicate(?array $except = null): static
    {
        /** @var static $clone */
        $clone = parent::replicate($except);

        foreach (AggregateRegistry::for(static::class) as $definition) {
            $clone->setAttribute(
                $definition->column,
                $definition->function->nullableOnEmpty() ? null : 0,
            );
        }

        return $clone;
    }

    private function resolveAggregateDefinition(string $column): AggregateDefinition
    {
        foreach (AggregateRegistry::for(static::class) as $definition) {
            if ($definition->column === $column) {
                return $definition;
            }
        }

        throw new AggregateConfigurationException(sprintf(
            '%s has no aggregate column "%s". '
            .'Declare it via #[NestedSetAggregate(...)] or nestedSetAggregates().',
            static::class,
            $column,
        ));
    }

    /**
     * Narrows a value that we expect to be numeric (the model's casts
     * usually guarantee this) to int, returning 0 for null. Mirrors
     * {@see NodeTrait::vusysIntAttr()} but tolerant of
     * null so unset/never-saved attributes default to zero rather than
     * throwing.
     */
    private static function vusysNumeric(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    // ----------------------------------------------------------------
    // Aggregate-repair static API
    //
    // These were originally declared directly on NodeTrait alongside
    // the tree-repair statics. They live here now so every aggregate-
    // related method — lifecycle handlers above, public repair surface
    // below — sits in one file. Composed back into NodeTrait via the
    // existing `use HasNestedSetAggregates` so callers still write
    // `Model::fixAggregates()` etc.
    // ----------------------------------------------------------------

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
        $instance = self::vusysAggregateAnchorOrFail($anchor);
        $rootId = self::vusysAnchorRootId($anchor);

        return TreeAggregateBuilder::aggregateErrors(
            connection: $instance->getConnection(),
            table: $instance->getTable(),
            lftCol: $instance->getLftName(),
            rgtCol: $instance->getRgtName(),
            scope: $anchor instanceof Model
                ? NestedSetScopeResolver::valuesFor($anchor)
                : [],
            definitions: AggregateRegistry::for(static::class),
            rootId: $rootId,
        );
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
     * Pass `chunkSize` to process the repair as a synchronous cursor
     * loop — useful for CLI commands where you want to stream progress
     * to stdout. `onChunk` is invoked once per slice with the per-chunk
     * result, the zero-based chunk index, and the cursor (last id
     * processed). The returned `AggregateFixResult` is the merged
     * total across every chunk.
     *
     * ```php
     * Area::fixAggregates(
     *     chunkSize: 1_000,
     *     onChunk: function (AggregateFixResult $chunk, int $i, ?int $cursor) {
     *         echo "Chunk {$i}: {$chunk->totalRowsUpdated} rows updated (cursor={$cursor})\n";
     *     },
     * );
     * ```
     *
     * For the *async* counterpart that hands chunking to a queue worker,
     * see {@see self::queueFixAggregates()} with the same `chunkSize`
     * argument.
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function fixAggregates(
        ?HasNestedSet $anchor = null,
        ?int $chunkSize = null,
        ?\Closure $onChunk = null,
    ): AggregateFixResult {
        if ($chunkSize !== null && $chunkSize > 0) {
            return self::vusysFixAggregatesChunked($anchor, $chunkSize, $onChunk);
        }

        $instance = self::vusysAggregateAnchorOrFail($anchor);
        $rootId = self::vusysAnchorRootId($anchor);

        return TreeAggregateBuilder::fixAggregates(
            connection: $instance->getConnection(),
            table: $instance->getTable(),
            lftCol: $instance->getLftName(),
            rgtCol: $instance->getRgtName(),
            scope: $anchor instanceof Model
                ? NestedSetScopeResolver::valuesFor($anchor)
                : [],
            definitions: AggregateRegistry::for(static::class),
            rootId: $rootId,
        );
    }

    /**
     * Synchronous chunk-loop counterpart to {@see self::fixAggregates()}.
     * Drives `fixAggregatesChunk` from cursor=null until it returns
     * nextAfterId=null, accumulating per-chunk results into one combined
     * AggregateFixResult.
     */
    private static function vusysFixAggregatesChunked(
        ?HasNestedSet $anchor,
        int $chunkSize,
        ?\Closure $onChunk,
    ): AggregateFixResult {
        $totalRows = 0;
        /** @var array<string, int> $perColumn */
        $perColumn = [];

        $cursor = null;
        $chunkIndex = 0;
        $safety = 0;

        do {
            $chunk = self::fixAggregatesChunk($anchor, $cursor, $chunkSize);
            $result = $chunk['result'];

            $totalRows += $result->totalRowsUpdated;
            foreach ($result->perColumn as $column => $count) {
                $perColumn[$column] = ($perColumn[$column] ?? 0) + $count;
            }

            $cursor = $chunk['nextAfterId'];

            if ($onChunk instanceof \Closure) {
                $onChunk($result, $chunkIndex, $cursor);
            }

            $chunkIndex++;

            // Defensive bound — a non-progressing cursor in a buggy
            // backend would otherwise spin forever. Capped at one
            // million chunks (way above realistic table sizes).
            if (++$safety > 1_000_000) {
                throw new \RuntimeException('fixAggregates(chunkSize: …): chunk loop exceeded 1,000,000 iterations.');
            }
        } while ($cursor !== null);

        return new AggregateFixResult(
            totalRowsUpdated: $totalRows,
            perColumn: $perColumn,
        );
    }

    /**
     * Repairs a single chunk of stored aggregate columns and returns the
     * cursor to feed into the next chunk (or null if this was the last).
     *
     * The chunk is defined as "up to `$chunkSize` rows whose id is
     * strictly greater than `$afterId`, ordered by id". Each chunk runs
     * one (chunked) fixAggregates call constrained to those outer ids,
     * so total work scales linearly in chunkSize regardless of total
     * table size.
     *
     * Used by {@see FixAggregatesJob} to break a long-running repair
     * into a series of short, self-re-dispatching jobs.
     *
     * @return array{result: AggregateFixResult, nextAfterId: int|null}
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function fixAggregatesChunk(
        ?HasNestedSet $anchor,
        ?int $afterId,
        int $chunkSize,
    ): array {
        $instance = self::vusysAggregateAnchorOrFail($anchor);
        $rootId = self::vusysAnchorRootId($anchor);

        if ($chunkSize <= 0) {
            throw new \InvalidArgumentException('fixAggregatesChunk: chunkSize must be > 0.');
        }

        $key = $instance->getKeyName();
        $scope = $anchor instanceof Model
            ? NestedSetScopeResolver::valuesFor($anchor)
            : [];

        // Fetch the next chunk of outer ids in a single bounded query
        // — `WHERE id > X ORDER BY id LIMIT N`. Scope-and-rooted in the
        // same shape fixAggregates uses so we don't process rows outside
        // the anchor's subtree.
        $query = $instance->getConnection()
            ->table($instance->getTable())
            ->select($key)
            ->orderBy($key)
            ->limit($chunkSize);

        if ($afterId !== null) {
            $query->where($key, '>', $afterId);
        }
        foreach ($scope as $col => $value) {
            $query->where($col, '=', $value);
        }
        if ($rootId !== null) {
            $rootRow = $instance->getConnection()
                ->table($instance->getTable())
                ->where('id', $rootId)
                ->first([$instance->getLftName(), $instance->getRgtName()]);
            if ($rootRow !== null) {
                $query->where($instance->getLftName(), '>=', $rootRow->{$instance->getLftName()})
                    ->where($instance->getRgtName(), '<=', $rootRow->{$instance->getRgtName()});
            }
        }

        $ids = array_values(array_map(
            static fn (\stdClass $row): int => (int) ($row->{$instance->getKeyName()} ?? 0),
            $query->get()->all(),
        ));

        $result = TreeAggregateBuilder::fixAggregates(
            connection: $instance->getConnection(),
            table: $instance->getTable(),
            lftCol: $instance->getLftName(),
            rgtCol: $instance->getRgtName(),
            scope: $scope,
            definitions: AggregateRegistry::for(static::class),
            rootId: $rootId,
            outerIds: $ids,
        );

        // A short final chunk (fewer rows than asked for) means we've
        // reached the end of the table — no further dispatch needed.
        $nextAfterId = count($ids) === $chunkSize ? end($ids) : null;

        return ['result' => $result, 'nextAfterId' => $nextAfterId];
    }

    /**
     * Dispatches a {@see FixAggregatesJob} to repair stored aggregate
     * columns asynchronously. Useful when the synchronous path would take
     * too long for a web request — e.g. a heavily-drifted 1M-row tree —
     * and you'd rather hand the work to a worker.
     *
     * Routing defaults come from `config('nestedset.queue.connection')`
     * and `config('nestedset.queue.queue')`; both `null` (the default)
     * uses Laravel's default queue. Per-call `onConnection` /
     * `onQueue` overrides take precedence.
     *
     * Pass `chunkSize` to break the work into a chain of bounded
     * self-redispatching jobs — each chunk processes that many outer
     * rows and the chain terminates when a chunk returns fewer rows
     * than `chunkSize`.
     *
     * Idempotent — a second dispatch on a clean tree finds zero drift
     * and writes nothing. Safe to fire defensively after batch work.
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function queueFixAggregates(
        ?HasNestedSet $anchor = null,
        ?string $onConnection = null,
        ?string $onQueue = null,
        ?int $chunkSize = null,
    ): FixAggregatesJob {
        // Fail fast at dispatch time — without this the job would be
        // enqueued, picked up, then throw inside the worker for the
        // exact same reason. Catching here gives a synchronous stack
        // trace and avoids a poisoned queue entry.
        $scopeColumns = NestedSetScopeResolver::columns(static::class);
        if ($scopeColumns !== [] && ! $anchor instanceof HasNestedSet) {
            throw new ScopeViolationException(sprintf(
                '%s declares a scope (%s); pass an anchor node to queueFixAggregates() so the job knows which tree to repair.',
                static::class,
                implode(', ', $scopeColumns),
            ));
        }

        $job = new FixAggregatesJob(
            modelClass: static::class,
            anchorId: self::vusysAnchorRootId($anchor),
            chunkSize: $chunkSize !== null && $chunkSize > 0 ? $chunkSize : null,
        );

        // Apply config defaults; per-call args win. Both null leaves the
        // job on Laravel's default queue/connection.
        $configConnection = config('nestedset.queue.connection');
        $connection = $onConnection ?? (is_string($configConnection) ? $configConnection : null);
        if ($connection !== null) {
            $job->onConnection($connection);
        }

        $configQueue = config('nestedset.queue.queue');
        $queue = $onQueue ?? (is_string($configQueue) ? $configQueue : null);
        if ($queue !== null) {
            $job->onQueue($queue);
        }

        // Dispatch eagerly via the global helper rather than returning
        // a PendingDispatch — PendingDispatch fires its dispatch in
        // __destruct, which can run after the test framework has torn
        // down the container. Eager dispatch also gives callers a
        // simple "did it queue?" check via the returned job instance
        // (its queue/connection properties are set).
        dispatch($job);

        return $job;
    }

    /**
     * Internal — called from {@see HasTreeRepair::fixTree()} after the
     * structural repair so stored aggregates match the rebuilt tree.
     * Cross-trait access works because both traits flatten into the
     * same using class, where private methods are mutually visible.
     */
    private static function vusysRunFixAggregates(
        ?HasNestedSet $anchor,
        ?int $rootId,
    ): ?AggregateFixResult {
        $definitions = AggregateRegistry::for(static::class);

        if ($definitions === []) {
            return null;
        }

        $instance = new static;

        return TreeAggregateBuilder::fixAggregates(
            connection: $instance->getConnection(),
            table: $instance->getTable(),
            lftCol: $instance->getLftName(),
            rgtCol: $instance->getRgtName(),
            scope: $anchor instanceof Model
                ? NestedSetScopeResolver::valuesFor($anchor)
                : [],
            definitions: $definitions,
            rootId: $rootId,
        );
    }

    private static function vusysAggregateAnchorOrFail(?HasNestedSet $anchor): self
    {
        $scopeColumns = NestedSetScopeResolver::columns(static::class);

        if ($scopeColumns !== [] && ! $anchor instanceof HasNestedSet) {
            throw new ScopeViolationException(sprintf(
                '%s declares a scope (%s); pass an anchor node to scope this operation.',
                static::class,
                implode(', ', $scopeColumns),
            ));
        }

        return new static;
    }

    private static function vusysAnchorRootId(?HasNestedSet $anchor): ?int
    {
        if (! $anchor instanceof Model) {
            return null;
        }

        $key = $anchor->getKey();

        return is_numeric($key) ? (int) $key : null;
    }
}
