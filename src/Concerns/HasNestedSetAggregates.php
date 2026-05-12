<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Strategy\DeltaMaintenance;
use Vusys\NestedSet\Aggregates\Strategy\RecomputeMaintenance;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
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
}
