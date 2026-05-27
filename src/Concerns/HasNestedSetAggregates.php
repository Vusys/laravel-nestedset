<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use InvalidArgumentException;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceTransform;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicateKind;
use Vusys\NestedSet\Aggregates\Numeric;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Sql\AggregateSqlEmitter;
use Vusys\NestedSet\Aggregates\Strategy\DeltaMaintenance;
use Vusys\NestedSet\Aggregates\Strategy\RecomputeMaintenance;
use Vusys\NestedSet\Contracts\AggregateDefinitionContract;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Contracts\TreeAggregateListener;
use Vusys\NestedSet\Events\Aggregates\AggregateDriftDetected;
use Vusys\NestedSet\Events\Aggregates\DeferredAggregateMaintenanceCompleted;
use Vusys\NestedSet\Events\Aggregates\DeferredMaintenanceStarting;
use Vusys\NestedSet\Events\Aggregates\FixAggregatesChunkCompleted;
use Vusys\NestedSet\Events\Aggregates\FixAggregatesCompleted;
use Vusys\NestedSet\Events\Aggregates\FixAggregatesJobDispatched;
use Vusys\NestedSet\Events\Aggregates\NodeAggregatesRecomputed;
use Vusys\NestedSet\Events\Diagnostics\ScopeViolationDetected;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Exceptions\AggregateSourceConstraintViolationException;
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
     * Per-class reentrancy depth for {@see self::withDeferredAggregateMaintenance()}.
     * When > 0, every lifecycle handler in this trait becomes a no-op;
     * the wrapper fires one `fixAggregates()` at the outermost exit
     * (success or failure) to repair the cumulative drift in one pass.
     *
     * A `private static` property on a trait gives every using class
     * its own counter — so deferring maintenance on `Area` doesn't
     * affect `Category::create()` etc.
     */
    private static int $deferredDepth = 0;

    /**
     * Source-column deltas captured in `saving` and applied in `saved`.
     * Keyed by aggregate column name (the SUM column receiving the delta).
     *
     * Listener aggregates may produce float deltas; SQL aggregates over
     * integer source columns produce int deltas. The maintenance pipeline
     * threads both through as `int|float`.
     *
     * @var array<string, int|float>
     */
    private array $capturedAggregateDeltas = [];

    /**
     * Cheap-delta MIN/MAX candidates captured in `saving` (extension or
     * insert direction — i.e. the new value can only extend the
     * extremum, never invalidate it). Applied alongside Phase D's
     * deltas as `CASE WHEN ... THEN candidate ELSE stored END`.
     *
     * @var array<string, array{function: AggregateFunction, value: int|float}>
     */
    private array $capturedExtremes = [];

    /**
     * Recompute candidates captured in `saving` (lost-holder direction
     * — the change may have invalidated the stored extremum on some
     * ancestor). Applied via {@see RecomputeMaintenance} after the
     * delta UPDATE commits, filtered by `stored = previous_value` so
     * unaffected ancestors are skipped.
     *
     * @var array<string, array{function: AggregateFunction, source: string, filterValue: int|float, filter: FilterPredicate|null}>
     */
    private array $capturedRecomputes = [];

    /**
     * Listener Min/Max definitions where the stored extremum may be
     * invalidated by a change — PHP-based recompute required.
     *
     * @var array<string, ListenerAggregateDefinition>
     */
    private array $capturedListenerRecomputes = [];

    /**
     * Bitwise BitOr / BitXor deltas captured in `saving` / `created` /
     * `deleted`. Each entry encodes the value to fold in (XOR for
     * BitXor — self-inverse; OR for BitOr — monotone-add on insert).
     * BitAnd never appears here; it always routes through chain
     * recompute.
     *
     * @var array<string, array{function: AggregateFunction, value: int|float}>
     */
    private array $capturedBitwise = [];

    /**
     * Aggregate definitions that need an ancestor-chain recompute on
     * the next applyAggregateDeltas() pass. Covers two cases the
     * delta path can't (or doesn't) handle:
     *
     *  - **Raw-SQL filter** definitions, where the package can't
     *    evaluate the predicate in PHP to produce a signed delta.
     *  - **Exclusive** definitions, whose subtree-contribution math
     *    is per-function and per-filter; the chain recompute path
     *    handles every function (SUM/COUNT/AVG/MIN/MAX) uniformly
     *    and with any filter kind — same machinery as raw filters.
     *
     * Cost: bounded to O(depth × subtree-size) per save, same as the
     * MIN/MAX extremum-lost path. Mutations that don't touch a watched
     * column skip the recompute entirely.
     *
     * @var array<string, AggregateDefinition>
     */
    private array $capturedChainRecomputes = [];

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
     * Recomputes the value of an aggregate column for this node.
     * For SQL-backed aggregates, runs a subquery against the source column.
     * For listener aggregates, evaluates the listener in PHP over the subtree.
     *
     * Pass `withTrashed: true` to include soft-deleted descendants in
     * the recompute — useful for matching the rowset of a
     * `withTrashed()` outer query.
     *
     * @throws AggregateConfigurationException when $column is not a
     *                                         declared aggregate on this model.
     */
    public function freshAggregate(string $column, bool $withTrashed = false): mixed
    {
        $definition = $this->resolveDefinitionByColumn($column);

        if ($definition instanceof AggregateDefinition) {
            return TreeAggregateBuilder::scalar($this, $definition, $withTrashed);
        }

        if ($definition instanceof ListenerAggregateDefinition) {
            return $this->freshListenerAggregate($definition, $withTrashed);
        }

        throw new AggregateConfigurationException(sprintf(
            'Unsupported aggregate definition type %s for column "%s".',
            $definition::class,
            $column,
        ));
    }

    /**
     * PHP-based fresh read for a single listener aggregate column on this node.
     */
    private function freshListenerAggregate(ListenerAggregateDefinition $definition, bool $withTrashed = false): int|float|null
    {
        $bounds = $this->getBounds();
        $lftCol = $this->getLftName();
        $rgtCol = $this->getRgtName();
        $scope = NestedSetScopeResolver::valuesFor($this);
        $listener = $definition->makeListener();

        $query = static::query();
        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }
        foreach ($scope as $col => $value) {
            $query->where($col, $value);
        }

        if ($definition->isInclusive()) {
            $query->where($lftCol, '>=', $bounds->lft)
                ->where($rgtCol, '<=', $bounds->rgt);
        } else {
            $query->where($lftCol, '>', $bounds->lft)
                ->where($rgtCol, '<', $bounds->rgt);
        }

        // Stream via cursor and fold into running accumulators — peak
        // memory is O(1) regardless of subtree size (the pre-cursor
        // implementation hydrated the full Collection ~3KB/node, the
        // intermediate cursor-with-list pass still held one int/float
        // per contributing node). Holds sum/count/min/max for the
        // currently-streamed values; the final match picks the right
        // one for the listener's declared operation.
        $sum = 0;
        $count = 0;
        $min = null;
        $max = null;
        foreach ($query->cursor() as $node) {
            $c = $listener->contribution($node);
            if ($c === null) {
                continue;
            }
            $sum += $c;
            $count++;
            $min = $min === null ? $c : min($min, $c);
            $max = $max === null ? $c : max($max, $c);
        }

        return match ($definition->operation) {
            AggregateFunction::Sum => $sum,
            AggregateFunction::Count => $count,
            AggregateFunction::Min => $min,
            AggregateFunction::Max => $max,
            AggregateFunction::Avg => $count === 0 ? null : $sum / $count,
            AggregateFunction::Variance, AggregateFunction::Stddev => throw new \LogicException(
                'Variance / Stddev are not supported for listener aggregates. '
                .'Use a SQL aggregate (Aggregate::variance / ::stddev) or maintain Sum + Count manually.',
            ),
            AggregateFunction::BitOr,
            AggregateFunction::BitAnd,
            AggregateFunction::BitXor => throw new \LogicException(
                'Bitwise listener aggregates are not supported — ListenerAggregateDefinition rejects them at construction.',
            ),
            AggregateFunction::WeightedAvg,
            AggregateFunction::BoolOr,
            AggregateFunction::BoolAnd,
            AggregateFunction::GeometricMean,
            AggregateFunction::HarmonicMean,
            AggregateFunction::DistinctCount,
            AggregateFunction::StringAgg,
            AggregateFunction::JsonAgg,
            AggregateFunction::JsonObjectAgg,
            AggregateFunction::Median,
            AggregateFunction::Percentile => throw new AggregateConfigurationException(sprintf(
                'Listener aggregates do not support %s; declare it via #[NestedSetAggregate] (column-based) instead.',
                $definition->operation->value,
            )),
        };
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
        $lft = Numeric::asIntOrZero($this->getAttribute($this->getLftName()));
        $rgt = Numeric::asIntOrZero($this->getAttribute($this->getRgtName()));

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
        // Constraint check runs for both inserts (exists=false) and
        // updates (exists=true) so it fires before the row is persisted.
        // Validate even when maintenance is deferred — otherwise
        // withDeferredAggregateMaintenance() would let geom/harm-mean
        // source-domain violations land in the database silently.
        $this->validateAggregateSourceConstraints();

        if (self::$deferredDepth > 0) {
            return;
        }

        $this->capturedAggregateDeltas = [];
        $this->capturedExtremes = [];
        $this->capturedRecomputes = [];
        $this->capturedListenerRecomputes = [];
        $this->capturedChainRecomputes = [];
        $this->capturedBitwise = [];

        if (! $this->exists) {
            return;
        }

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }

            // Exclusive aggregates: queue a chain recompute when a
            // watched column is dirty. Delta arithmetic would need
            // per-function subtree-contribution composition; recompute
            // is uniform across functions.
            if (! $definition->inclusive) {
                $watchCols = self::triggerColumnsFor($definition);
                if ($watchCols !== [] && $this->isDirty($watchCols)) {
                    $this->capturedChainRecomputes[$definition->column] = $definition;
                }

                continue;
            }

            // Raw predicates can't be evaluated in PHP, so we can't
            // produce a signed delta. Queue an ancestor-chain recompute
            // instead — kicked off in applyAggregateDeltas() alongside
            // the other captured maintenance work.
            if ($definition->filter?->getKind() === FilterPredicateKind::Raw) {
                $watchCols = self::triggerColumnsFor($definition);
                if ($watchCols !== [] && $this->isDirty($watchCols)) {
                    $this->capturedChainRecomputes[$definition->column] = $definition;
                }

                continue;
            }

            // Collection aggregate kinds: any change to a contributing column
            // triggers a full subtree recompute up the ancestor chain.
            if (self::requiresChainRecompute($definition->function)) {
                $watchCols = array_unique(array_merge(
                    AggregateSqlEmitter::watchColumns($definition),
                    $definition->filter?->watchColumns() ?? [],
                ));
                if ($watchCols !== [] && $this->isDirty($watchCols)) {
                    $this->capturedChainRecomputes[$definition->column] = $definition;
                }

                continue;
            }

            // Determine trigger columns: source column + filter watch
            // columns + (for weighted-product companions) the weight
            // column too. Without the weight trigger, a row whose value
            // is unchanged but whose weight changed would miss the
            // delta capture and leave `Σ(w · x)` stale.
            $triggerCols = self::triggerColumnsFor($definition);
            // Skip if nothing relevant is dirty.
            if ($triggerCols === []) {
                continue;
            }
            if (! $this->isDirty($triggerCols)) {
                continue;
            }

            // Evaluate filter against new and old attribute sets.
            $newPred = $definition->filter instanceof FilterPredicate
                ? ($definition->filter->evaluateFor($this->getAttributes()) ?? true)
                : true;
            $oldPred = $definition->filter instanceof FilterPredicate
                ? ($definition->filter->evaluateFor($this->getOriginal()) ?? true)
                : true;

            $source = $definition->source;

            if ($definition->function === AggregateFunction::Sum && $source !== null) {
                $newSource = $definition->sourceTransform->applyPhp(
                    $this->getAttribute($source),
                    $definition->weight !== null
                        ? Numeric::asNumericOrNull($this->getAttribute($definition->weight))
                        : null,
                );
                $oldSource = $definition->sourceTransform->applyPhp(
                    $this->getOriginal($source),
                    $definition->weight !== null
                        ? Numeric::asNumericOrNull($this->getOriginal($definition->weight))
                        : null,
                );
                $delta = ($newPred ? $newSource : 0) - ($oldPred ? $oldSource : 0);

                if ($delta !== 0) {
                    $this->capturedAggregateDeltas[$definition->column] = $delta;
                }

                continue;
            }

            if ($definition->function === AggregateFunction::Count) {
                if ($source === null) {
                    $delta = ($newPred ? 1 : 0) - ($oldPred ? 1 : 0);
                } elseif ($definition->sourceTransform === CompanionSourceTransform::Identity) {
                    $newContrib = ($newPred && ($this->getAttribute($source) !== null)) ? 1 : 0;
                    $oldContrib = ($oldPred && ($this->getOriginal($source) !== null)) ? 1 : 0;
                    $delta = $newContrib - $oldContrib;
                } else {
                    // Non-Identity transform on a Count companion (today:
                    // Ln for GeometricMean, Recip for HarmonicMean) — count
                    // only rows whose transformed value would land in the
                    // sibling Sum companion. applyPhp returns 0 for rows
                    // the transform skips (LN of ≤ 0, 1/0), so equating
                    // against zero is the right "did this row contribute"
                    // test.
                    $newTransformed = $definition->sourceTransform->applyPhp($this->getAttribute($source));
                    $oldTransformed = $definition->sourceTransform->applyPhp($this->getOriginal($source));
                    $newContrib = ($newPred && $newTransformed != 0) ? 1 : 0;
                    $oldContrib = ($oldPred && $oldTransformed != 0) ? 1 : 0;
                    $delta = $newContrib - $oldContrib;
                }

                if ($delta !== 0) {
                    $this->capturedAggregateDeltas[$definition->column] = $delta;
                }

                continue;
            }

            if ($definition->function === AggregateFunction::Max && $source !== null) {
                $newSource = Numeric::asIntOrZero($this->getAttribute($source));
                $oldSource = Numeric::asIntOrZero($this->getOriginal($source));

                if ($newPred && ! $oldPred) {
                    // Entered filter — can only extend the max.
                    $this->capturedExtremes[$definition->column] = [
                        'function' => AggregateFunction::Max,
                        'value' => $newSource,
                    ];
                } elseif (! $newPred && $oldPred) {
                    // Exited filter — old value may have been the holder.
                    $this->capturedRecomputes[$definition->column] = [
                        'function' => AggregateFunction::Max,
                        'source' => $source,
                        'filterValue' => $oldSource,
                        'filter' => $definition->filter,
                    ];
                } elseif ($newPred && $oldPred) {
                    $delta = $newSource - $oldSource;
                    if ($delta > 0) {
                        $this->capturedExtremes[$definition->column] = [
                            'function' => AggregateFunction::Max,
                            'value' => $newSource,
                        ];
                    } elseif ($delta < 0) {
                        $this->capturedRecomputes[$definition->column] = [
                            'function' => AggregateFunction::Max,
                            'source' => $source,
                            'filterValue' => $oldSource,
                            'filter' => $definition->filter,
                        ];
                    }
                }

                continue;
            }

            if ($definition->function === AggregateFunction::Min && $source !== null) {
                $newSource = Numeric::asIntOrZero($this->getAttribute($source));
                $oldSource = Numeric::asIntOrZero($this->getOriginal($source));

                if ($newPred && ! $oldPred) {
                    // Entered filter — can only extend the min.
                    $this->capturedExtremes[$definition->column] = [
                        'function' => AggregateFunction::Min,
                        'value' => $newSource,
                    ];
                } elseif (! $newPred && $oldPred) {
                    // Exited filter — old value may have been the holder.
                    $this->capturedRecomputes[$definition->column] = [
                        'function' => AggregateFunction::Min,
                        'source' => $source,
                        'filterValue' => $oldSource,
                        'filter' => $definition->filter,
                    ];
                } elseif ($newPred && $oldPred) {
                    $delta = $newSource - $oldSource;
                    if ($delta < 0) {
                        $this->capturedExtremes[$definition->column] = [
                            'function' => AggregateFunction::Min,
                            'value' => $newSource,
                        ];
                    } elseif ($delta > 0) {
                        $this->capturedRecomputes[$definition->column] = [
                            'function' => AggregateFunction::Min,
                            'source' => $source,
                            'filterValue' => $oldSource,
                            'filter' => $definition->filter,
                        ];
                    }
                }

                continue;
            }

            if ($definition->function === AggregateFunction::BitXor && $source !== null) {
                // BitXor is self-inverse — `parent ^= old` undoes the
                // old contribution; `parent ^= new` adds the new one.
                // Combined: `parent ^= (oldContrib ^ newContrib)` where
                // each contrib is the source value when the row passes
                // the filter, else 0 (the identity for XOR).
                $newSource = Numeric::asIntOrZero($this->getAttribute($source));
                $oldSource = Numeric::asIntOrZero($this->getOriginal($source));
                $newContrib = $newPred ? $newSource : 0;
                $oldContrib = $oldPred ? $oldSource : 0;
                $xorDelta = $oldContrib ^ $newContrib;

                if ($xorDelta !== 0) {
                    $this->capturedBitwise[$definition->column] = [
                        'function' => AggregateFunction::BitXor,
                        'value' => $xorDelta,
                    ];
                }

                continue;
            }

            if (in_array($definition->function, [AggregateFunction::BitOr, AggregateFunction::BitAnd], true)
                && $source !== null
            ) {
                // BitOr: source change can drop a bit no other row holds
                // (`parent |= new` would not unset it). BitAnd: every
                // change can promote or demote the AND fold. Route both
                // through chain recompute on any dirty source.
                $this->capturedChainRecomputes[$definition->column] = $definition;

                continue;
            }
        }

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition instanceof ListenerAggregateDefinition) {
                continue;
            }

            $listener = $definition->makeListener();
            $watchCols = $listener->watchColumns();
            if ($watchCols === []) {
                continue;
            }
            if (! $this->isDirty($watchCols)) {
                continue;
            }

            // Exclusive listener: route to chain recompute (uniform
            // across operations; subtree-contribution composition is
            // function-specific and we don't want to duplicate that
            // here).
            if (! $definition->isInclusive()) {
                $this->capturedListenerRecomputes[$definition->column] = $definition;

                continue;
            }

            $op = $definition->operation;

            // Variance / Stddev have no listener-side implementation.
            // Reject early — the update path's final else-branch would
            // otherwise misroute these into the Min recompute path.
            if ($op === AggregateFunction::Variance || $op === AggregateFunction::Stddev) {
                throw new \LogicException(
                    'Variance / Stddev are not supported for listener aggregates. '
                    .'Use a SQL aggregate (Aggregate::variance / ::stddev) or maintain Sum + Count manually.',
                );
            }

            // AVG listener defs maintain themselves through their
            // auto-promoted Sum + Count companions (separate
            // ListenerAggregateDefinition entries the registry
            // generates). The display column is written by
            // DeltaMaintenance via the `$avgs` SET clauses.
            if ($op === AggregateFunction::Avg) {
                continue;
            }

            // Old contribution: snapshot of pre-save attributes
            $oldSnapshot = new static;
            $oldSnapshot->setRawAttributes($this->getOriginal(), true);
            $oldContrib = $listener->contribution($oldSnapshot);
            $oldVal = Numeric::contributionOrZero($oldContrib);

            // New contribution: current attributes
            $newContrib = $listener->contribution($this);
            $newVal = Numeric::contributionOrZero($newContrib);

            if ($op === AggregateFunction::Sum) {
                $delta = $newVal - $oldVal;
                if ($delta != 0) {
                    $this->capturedAggregateDeltas[$definition->column] = $delta;
                }

                continue;
            }

            if ($op === AggregateFunction::Count) {
                // Count operation: 1 when the node contributes (i.e.
                // contribution() returned non-null), 0 otherwise.
                // Delta is the transition: -1 / 0 / +1.
                $oldCounted = $oldContrib !== null ? 1 : 0;
                $newCounted = $newContrib !== null ? 1 : 0;
                $delta = $newCounted - $oldCounted;
                if ($delta !== 0) {
                    $this->capturedAggregateDeltas[$definition->column] = $delta;
                }

                continue;
            }

            if ($op === AggregateFunction::Max) {
                if ($newVal > $oldVal) {
                    $this->capturedExtremes[$definition->column] = ['function' => AggregateFunction::Max, 'value' => $newVal];
                } elseif ($newVal < $oldVal) {
                    $this->capturedListenerRecomputes[$definition->column] = $definition;
                }

                continue;
            }

            // Min: only remaining op after Sum/Count/Avg/Max continues above.
            if ($newVal < $oldVal) {
                $this->capturedExtremes[$definition->column] = ['function' => AggregateFunction::Min, 'value' => $newVal];
            } elseif ($newVal > $oldVal) {
                $this->capturedListenerRecomputes[$definition->column] = $definition;
            }
        }
    }

    /**
     * Validates that any GeometricMean or HarmonicMean aggregate source
     * value on this model satisfies its positivity / non-zero constraint.
     * Runs for both inserts and updates (called from
     * {@see captureAggregateDeltas()} before the early-return gate).
     *
     * For inserts: checks the current attribute value.
     * For updates: checks only when the source column is dirty.
     *
     * A violation throws {@see AggregateSourceConstraintViolationException}
     * unless the aggregate was declared with `->allowNonPositive()` /
     * `allowNonPositive: true`, in which case the row silently
     * contributes nothing to the companion sum.
     *
     * @throws AggregateSourceConstraintViolationException
     */
    private function validateAggregateSourceConstraints(): void
    {
        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }

            if ($definition->function !== AggregateFunction::GeometricMean
                && $definition->function !== AggregateFunction::HarmonicMean) {
                continue;
            }

            if ($definition->allowNonPositive) {
                continue;
            }

            if ($definition->internal) {
                continue;
            }

            $source = $definition->source;
            if ($source === null) {
                continue;
            }

            // For updates: only validate when source is dirty.
            if ($this->exists && ! $this->isDirty($source)) {
                continue;
            }

            $value = $this->getAttribute($source);
            if ($value === null) {
                continue;
            }

            if (! is_numeric($value)) {
                continue;
            }

            $numeric = (float) $value;

            if ($definition->function === AggregateFunction::GeometricMean && $numeric <= 0) {
                throw new AggregateSourceConstraintViolationException(sprintf(
                    '%s: source column "%s" = %s violates the positivity constraint of geometricMean("%s"). '
                    .'Only strictly positive values are valid. '
                    .'Declare the aggregate with ->allowNonPositive() / allowNonPositive: true to '
                    .'silently exclude non-positive rows instead.',
                    static::class,
                    $source,
                    $value,
                    $source,
                ));
            }

            if ($definition->function === AggregateFunction::HarmonicMean && $numeric == 0) {
                throw new AggregateSourceConstraintViolationException(sprintf(
                    '%s: source column "%s" = 0 violates the non-zero constraint of harmonicMean("%s"). '
                    .'Zero values produce a division by zero in the reciprocal sum. '
                    .'Declare the aggregate with ->allowNonPositive() / allowNonPositive: true to '
                    .'silently exclude zero rows instead.',
                    static::class,
                    $source,
                    $source,
                ));
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
        if (self::$deferredDepth > 0) {
            return;
        }

        $deltas = $this->capturedAggregateDeltas;
        $extremes = $this->capturedExtremes;
        $recomputes = $this->capturedRecomputes;
        $listenerRecomputes = $this->capturedListenerRecomputes;
        $chainRecomputes = $this->capturedChainRecomputes;
        $bitwise = $this->capturedBitwise;

        $this->capturedAggregateDeltas = [];
        $this->capturedExtremes = [];
        $this->capturedRecomputes = [];
        $this->capturedListenerRecomputes = [];
        $this->capturedChainRecomputes = [];
        $this->capturedBitwise = [];

        if ($deltas === [] && $extremes === [] && $recomputes === [] && $listenerRecomputes === [] && $chainRecomputes === [] && $bitwise === []) {
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
            bitwise: $bitwise,
            softDeletedColumn: $this->softDeleteColumn(),
            variances: AggregateRegistry::varianceCompanionsFor(static::class),
            weightedAvgs: AggregateRegistry::weightedAvgCompanionsFor(static::class),
            bools: AggregateRegistry::boolCompanionsFor(static::class),
            means: AggregateRegistry::meanCompanionsFor(static::class),
        );

        if ($recomputes !== []) {
            $this->applyCapturedRecomputes($recomputes, $scope);
        }

        if ($chainRecomputes !== []) {
            $this->applyChainRecompute($this->getBounds(), $scope, $chainRecomputes);
        }

        if ($listenerRecomputes !== []) {
            $this->applyListenerChainRecompute(
                bounds: $this->getBounds(),
                scope: $scope,
                definitions: $listenerRecomputes,
                includeSelf: true,
            );
        }
    }

    /**
     * @param  array<string, array{function: AggregateFunction, source: string, filterValue: int|float, filter: FilterPredicate|null}>  $recomputes
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
                'filter' => $spec['filter'],
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
            locking: self::aggregateLockingMode(),
            softDeletedColumn: $this->softDeleteColumn(),
            idCol: $this->getKeyName(),
        );
    }

    /**
     * Bulk-recomputes the listed raw-filter aggregate columns across the
     * ancestor chain of `$bounds`. Used wherever a per-row mutation may
     * change which rows pass the (un-PHP-evaluable) raw predicate —
     * source/watch column update, create, delete, move, restore.
     *
     * Issues one SELECT and one UPDATE per affected ancestor row. Cost
     * is O(depth × subtree-size) per call, matching the MIN/MAX-extremum
     * recompute branch.
     *
     * @param  array<string, AggregateDefinition>  $definitions
     * @param  array<string, mixed>  $scope
     */
    private function applyChainRecompute(
        NodeBounds $bounds,
        array $scope,
        array $definitions,
        ?NodeBounds $excludeBounds = null,
    ): void {
        if ($definitions === []) {
            return;
        }

        $columns = [];
        foreach ($definitions as $aggregateColumn => $definition) {
            $columns[] = [
                'column' => $aggregateColumn,
                'function' => $definition->function,
                // RecomputeMaintenance reads inner_a.<source>; for COUNT(*) the
                // source is null in the definition, but the helper handles
                // empty string specially.
                'source' => $definition->source ?? '',
                'inclusive' => $definition->inclusive,
                'filter' => $definition->filter,
                // Variance / Stddev recomputes need the sample flag to
                // pick the right denominator; the SumSq companion of
                // those kinds needs its Square source-transform so the
                // inner SQL emits `SUM(x * x)` instead of `SUM(x)`.
                // Without these two fields, chain recomputes triggered
                // by raw-filter / exclusive / move / restore paths
                // silently fall back to population maths and rebuild
                // SumSq as a plain Sum.
                'sample' => $definition->sample,
                'sourceTransform' => $definition->sourceTransform,
                'definition' => $definition,
            ];
        }

        RecomputeMaintenance::apply(
            connection: $this->getConnection(),
            table: $this->getTable(),
            lftCol: $this->getLftName(),
            rgtCol: $this->getRgtName(),
            bounds: $bounds,
            columns: $columns,
            scope: $scope,
            filterEquals: [],   // recompute every ancestor; no cheap-skip
            locking: self::aggregateLockingMode(),
            excludeBounds: $excludeBounds,
            softDeletedColumn: $this->softDeleteColumn(),
            idCol: $this->getKeyName(),
        );
    }

    /**
     * True for aggregate kinds that always need a full subtree recompute
     * on every mutation — no delta or cheap-skip fast path applies.
     *
     * Includes the four collection-aggregate kinds (DistinctCount / StringAgg /
     * JsonAgg / JsonObjectAgg). MIN/MAX are also recompute-only but
     * carry a cheap-skip filter on the previous extremum value, so
     * they get their own captured-recompute branch instead of going
     * through chainRecomputes.
     */
    private static function requiresChainRecompute(AggregateFunction $fn): bool
    {
        return match ($fn) {
            AggregateFunction::DistinctCount,
            AggregateFunction::StringAgg,
            AggregateFunction::JsonAgg,
            AggregateFunction::JsonObjectAgg,
            AggregateFunction::BitOr,
            AggregateFunction::BitAnd,
            AggregateFunction::BitXor => true,
            default => false,
        };
    }

    /**
     * Columns whose dirty state should trigger aggregate maintenance
     * for $definition. Includes the source column, the filter's watch
     * columns, and (when the source transform consumes a weight) the
     * weight column too — without the weight trigger, a row whose
     * value is unchanged but whose weight changed would skip the
     * delta capture and leave `Σ(w · x)` stale.
     *
     * @return list<string>
     */
    private static function triggerColumnsFor(AggregateDefinition $definition): array
    {
        return array_values(array_unique(array_merge(
            $definition->source !== null ? [$definition->source] : [],
            $definition->sourceTransform->requiresWeight() && $definition->weight !== null
                ? [$definition->weight]
                : [],
            $definition->filter?->watchColumns() ?? [],
        )));
    }

    /**
     * @return 'always'|'auto'|'never'
     */
    private static function aggregateLockingMode(): string
    {
        $value = config('nestedset.aggregate_locking', 'auto');

        return match ($value) {
            'always' => 'always',
            'never' => 'never',
            default => 'auto',
        };
    }

    /**
     * Dispatches {@see NodeAggregatesRecomputed} for one lifecycle
     * hook, naming every declared aggregate column on the model.
     * No-op when the model declares no aggregates or has no primary
     * key. Stage is one of 'on_create', 'on_delete', 'on_restore',
     * 'move'.
     */
    private function dispatchAggregatesRecomputed(string $stage): void
    {
        $definitions = AggregateRegistry::for(static::class);

        if ($definitions === []) {
            return;
        }

        $key = $this->getKey();
        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        $columns = [];
        foreach ($definitions as $def) {
            if ($def->isInternal()) {
                continue;
            }
            $columns[] = $def->getColumn();
        }
        $columns = array_values(array_unique($columns));

        EventDispatcher::dispatch(new NodeAggregatesRecomputed(
            modelClass: static::class,
            nodeId: $key,
            columns: $columns,
            stage: $stage,
        ));
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
        if (self::$deferredDepth > 0) {
            return;
        }

        if (! $this->isPlacedInTree()) {
            return;
        }

        $deltas = [];
        $extremes = [];
        /** @var array<string, array{function: AggregateFunction, value: int}> $bitwise */
        $bitwise = [];
        /** @var array<string, AggregateDefinition> $chainRecomputes */
        $chainRecomputes = [];

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }

            // Exclusive aggregates: route through the chain-recompute
            // path. The delta math differs per-function and per-filter
            // (subtree contribution is `descendants-only + self` for
            // SUM/COUNT, but min/max of those for MIN/MAX, derived
            // from companions for AVG). The recompute path handles
            // every shape uniformly.
            if (! $definition->inclusive) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            // Raw filter: predicate can't be evaluated in PHP, so we
            // can't decide whether this node contributes. Queue an
            // ancestor-chain recompute and skip the per-function delta
            // logic for this definition.
            if ($definition->filter instanceof FilterPredicate
                && $definition->filter->getKind() === FilterPredicateKind::Raw) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            // Collection aggregate kinds: always full subtree recompute.
            if (self::requiresChainRecompute($definition->function)) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            if ($definition->function === AggregateFunction::Sum && $definition->source !== null) {
                if ($definition->filter instanceof FilterPredicate
                    && $definition->filter->evaluateFor($this->getAttributes()) !== true) {
                    continue;
                }
                $value = $definition->sourceTransform->applyPhp(
                    $this->getAttribute($definition->source),
                    $definition->weight !== null
                        ? Numeric::asNumericOrNull($this->getAttribute($definition->weight))
                        : null,
                );
                if ($value !== 0) {
                    $deltas[$definition->column] = $value;
                }

                continue;
            }

            if ($definition->function === AggregateFunction::Count) {
                if ($definition->filter instanceof FilterPredicate
                    && $definition->filter->evaluateFor($this->getAttributes()) !== true) {
                    continue;
                }
                // COUNT(source) — only contribute if source is non-null.
                if ($definition->source !== null && $this->getAttribute($definition->source) === null) {
                    continue;
                }
                $deltas[$definition->column] = 1;

                continue;
            }

            if (($definition->function === AggregateFunction::Max
                || $definition->function === AggregateFunction::Min)
                && $definition->source !== null
            ) {
                if ($definition->filter instanceof FilterPredicate
                    && $definition->filter->evaluateFor($this->getAttributes()) !== true) {
                    continue;
                }
                $value = Numeric::asIntOrZero($this->getAttribute($definition->source));
                $extremes[$definition->column] = [
                    'function' => $definition->function,
                    'value' => $value,
                ];

                continue;
            }

            if (($definition->function === AggregateFunction::BitOr
                || $definition->function === AggregateFunction::BitXor)
                && $definition->source !== null
            ) {
                if ($definition->filter instanceof FilterPredicate
                    && $definition->filter->evaluateFor($this->getAttributes()) !== true) {
                    continue;
                }
                $value = Numeric::asIntOrZero($this->getAttribute($definition->source));
                $bitwise[$definition->column] = [
                    'function' => $definition->function,
                    'value' => $value,
                ];

                continue;
            }

            if ($definition->function === AggregateFunction::BitAnd && $definition->source !== null) {
                // BitAnd: inserting a row with any bit cleared narrows
                // the AND fold; can't be expressed as a single delta.
                $chainRecomputes[$definition->column] = $definition;
            }
        }

        /** @var array<string, ListenerAggregateDefinition> $exclusiveListenerDefs */
        $exclusiveListenerDefs = [];

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition instanceof ListenerAggregateDefinition) {
                continue;
            }

            if (! $definition->isInclusive()) {
                // Exclusive listener defs use the chain-recompute path —
                // delta math would need per-function subtree-contribution
                // composition; recompute is uniform across operations.
                $exclusiveListenerDefs[$definition->column] = $definition;

                continue;
            }

            $op = $definition->operation;

            // Variance / Stddev have no listener-side implementation
            // (the SQL path derives them from companion sums; the
            // listener path would need n-pass accumulation we don't
            // model). Fail loudly at the create entry point so a
            // misconfigured listener does not fall through to the
            // Min/Max branch below.
            if ($op === AggregateFunction::Variance || $op === AggregateFunction::Stddev) {
                throw new \LogicException(
                    'Variance / Stddev are not supported for listener aggregates. '
                    .'Use a SQL aggregate (Aggregate::variance / ::stddev) or maintain Sum + Count manually.',
                );
            }

            // AVG listener: maintained by auto-promoted Sum + Count
            // companions, which iterate this loop as separate
            // ListenerAggregateDefinition entries.
            if ($op === AggregateFunction::Avg) {
                continue;
            }

            $listener = $definition->makeListener();
            $contrib = $listener->contribution($this);
            $value = Numeric::contributionOrZero($contrib);

            if ($op === AggregateFunction::Sum) {
                if ($value != 0) {
                    $deltas[$definition->column] = $value;
                }
            } elseif ($op === AggregateFunction::Count) {
                // Count contributes 1 when contribution() returned non-null.
                if ($contrib !== null) {
                    $deltas[$definition->column] = 1;
                }
            } elseif ($contrib !== null) {
                // Min / Max — only remaining ops after Sum/Count/Avg above.
                $extremes[$definition->column] = ['function' => $op, 'value' => $value];
            }
        }

        if ($deltas === [] && $extremes === [] && $bitwise === [] && $chainRecomputes === [] && $exclusiveListenerDefs === []) {
            return;
        }

        $scope = NestedSetScopeResolver::valuesFor($this);

        if ($deltas !== [] || $extremes !== [] || $bitwise !== []) {
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
                bitwise: $bitwise,
                softDeletedColumn: $this->softDeleteColumn(),
                variances: AggregateRegistry::varianceCompanionsFor(static::class),
                weightedAvgs: AggregateRegistry::weightedAvgCompanionsFor(static::class),
                bools: AggregateRegistry::boolCompanionsFor(static::class),
                means: AggregateRegistry::meanCompanionsFor(static::class),
            );
        }

        if ($chainRecomputes !== []) {
            $this->applyChainRecompute($this->getBounds(), $scope, $chainRecomputes);
        }

        if ($exclusiveListenerDefs !== []) {
            $this->applyListenerChainRecompute(
                bounds: $this->getBounds(),
                scope: $scope,
                definitions: $exclusiveListenerDefs,
            );
        }

        $this->dispatchAggregatesRecomputed('on_create');
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
        if (self::$deferredDepth > 0) {
            return;
        }

        if (! $this->isPlacedInTree()) {
            return;
        }

        $deltas = [];
        $minMaxRecomputes = [];
        /** @var array<string, array{function: AggregateFunction, value: int}> $bitwise */
        $bitwise = [];
        /** @var array<string, AggregateDefinition> $chainRecomputes */
        $chainRecomputes = [];

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }

            // Exclusive aggregate: chain-recompute the column over the
            // ancestor chain. The subtree-contribution math differs by
            // function (e.g. exclusive_col represents descendants-only;
            // the deleted node's own contribution composes differently
            // per function). Chain recompute handles every shape.
            if (! $definition->inclusive) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            // Raw filter: post-delete, the row no longer satisfies any
            // predicate. Recompute the column on the ancestor chain.
            if ($definition->filter instanceof FilterPredicate
                && $definition->filter->getKind() === FilterPredicateKind::Raw) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            // Collection aggregate kinds: full subtree recompute over the ancestor chain.
            if (self::requiresChainRecompute($definition->function)) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            if ($definition->function === AggregateFunction::Sum
                || $definition->function === AggregateFunction::Count) {
                // Preserve numeric type — Sum companions of WeightedAvg /
                // GeometricMean / HarmonicMean hold decimal sums (sum_wx,
                // sum_log, sum_recip) that numeric() would truncate to 0
                // or int-cast away the fractional part.
                $value = Numeric::asNumericOrZero($this->getAttribute($definition->column));
                if ($value != 0) {
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
                $stored = Numeric::asIntOrZero($this->getAttribute($definition->column));
                $minMaxRecomputes[$definition->column] = [
                    'function' => $definition->function,
                    'source' => $definition->source,
                    'filterValue' => $stored,
                    'filter' => $definition->filter,
                ];

                continue;
            }

            if ($definition->function === AggregateFunction::BitXor && $definition->source !== null) {
                // BitXor self-inverse: XOR-ing the deleted subtree's
                // rolled-up value out of each ancestor undoes its
                // contribution exactly. Use the stored display column
                // — it already holds the inclusive subtree XOR.
                $stored = $this->getAttribute($definition->column);
                if ($stored !== null) {
                    $value = Numeric::asIntOrZero($stored);
                    if ($value !== 0) {
                        $bitwise[$definition->column] = [
                            'function' => AggregateFunction::BitXor,
                            'value' => $value,
                        ];
                    }
                }

                continue;
            }

            if (($definition->function === AggregateFunction::BitOr
                || $definition->function === AggregateFunction::BitAnd)
                && $definition->source !== null
            ) {
                // BitOr: removing a row can clear a bit no one else
                // holds. BitAnd: removing a row can raise the AND
                // fold. Both route through chain recompute.
                $chainRecomputes[$definition->column] = $definition;
            }
        }

        /** @var array<string, ListenerAggregateDefinition> $listenerChainDefs */
        $listenerChainDefs = [];

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition instanceof ListenerAggregateDefinition) {
                continue;
            }

            if (! $definition->isInclusive()) {
                // Exclusive listener: chain recompute (any function).
                $listenerChainDefs[$definition->column] = $definition;

                continue;
            }

            $op = $definition->operation;

            // AVG listener: maintained via Sum + Count companions
            // (which iterate this loop separately).
            if ($op === AggregateFunction::Avg) {
                continue;
            }

            if ($op === AggregateFunction::Sum || $op === AggregateFunction::Count) {
                // Stored column holds the inclusive subtree total — same as SQL path.
                // Use type-preserving read: a float-listener column may be DECIMAL.
                $value = Numeric::asNumericOrZero($this->getAttribute($definition->column));
                if ($value != 0) {
                    $deltas[$definition->column] = -$value;
                }

                continue;
            }

            // Min / Max — only remaining ops after Sum/Count/Avg above.
            $listenerChainDefs[$definition->column] = $definition;
        }

        $scope = NestedSetScopeResolver::valuesFor($this);

        if ($deltas !== [] || $bitwise !== []) {
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
                bitwise: $bitwise,
                softDeletedColumn: $this->softDeleteColumn(),
                variances: AggregateRegistry::varianceCompanionsFor(static::class),
                weightedAvgs: AggregateRegistry::weightedAvgCompanionsFor(static::class),
                bools: AggregateRegistry::boolCompanionsFor(static::class),
                means: AggregateRegistry::meanCompanionsFor(static::class),
            );
        }

        if ($minMaxRecomputes !== []) {
            $this->applyCapturedRecomputes($minMaxRecomputes, $scope);
        }

        if ($chainRecomputes !== []) {
            // The deleted row is already gone from the table at this
            // point, so the subquery naturally excludes it. Recompute
            // the chain (skip self — its row is gone for hard deletes /
            // soft-deleted for soft deletes).
            $this->applyChainRecompute(
                bounds: $this->getBounds(),
                scope: $scope,
                definitions: $chainRecomputes,
            );
        }

        if ($listenerChainDefs !== []) {
            $this->applyListenerChainRecompute(
                bounds: $this->getBounds(),
                scope: $scope,
                definitions: $listenerChainDefs,
                includeSelf: false,   // deleted row not in DB anymore
            );
        }

        $this->dispatchAggregatesRecomputed('on_delete');
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
        if (self::$deferredDepth > 0) {
            return;
        }

        [$sumCountDeltas, $minMaxByFunction] = $this->collectMoveSubtreeContribution();

        // Don't early-return on empty inclusive deltas alone: a moving
        // leaf with tickets=0 contributes nothing to inclusive Sums,
        // but the chain recompute still needs to drop it from every
        // exclusive descendants_count / descendants_max on the old
        // ancestor chain. The exclusive-aggregate chain recompute
        // logic below depends on running for every move.
        $scope = NestedSetScopeResolver::valuesFor($this);

        if ($sumCountDeltas !== []) {
            $negative = array_map(static fn (int|float $v): int|float => -$v, $sumCountDeltas);
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
                softDeletedColumn: $this->softDeleteColumn(),
                variances: AggregateRegistry::varianceCompanionsFor(static::class),
                weightedAvgs: AggregateRegistry::weightedAvgCompanionsFor(static::class),
                bools: AggregateRegistry::boolCompanionsFor(static::class),
                means: AggregateRegistry::meanCompanionsFor(static::class),
            );
        }

        if ($minMaxByFunction !== []) {
            // Exclude self's pre-move subtree from the inner MIN/MAX
            // scan. The move SQL hasn't run yet, so A1's rows are still
            // physically present; we have to logically exclude them so
            // the recompute reflects the post-move ancestor state.
            $this->applyMoveRecomputes($from, $minMaxByFunction, $scope, excludeBounds: $from);
        }

        /** @var array<string, ListenerAggregateDefinition> $listenerChainSpecs */
        $listenerChainSpecs = [];
        foreach (AggregateRegistry::for(static::class) as $def) {
            if (! $def instanceof ListenerAggregateDefinition) {
                continue;
            }
            // Exclusive listener defs (any function), and inclusive
            // Min/Max listener defs (extremum may have been held by
            // the moving subtree) both need a chain recompute on the
            // old chain.
            if (! $def->isInclusive()
                || $def->operation === AggregateFunction::Max
                || $def->operation === AggregateFunction::Min) {
                $listenerChainSpecs[$def->column] = $def;
            }
        }
        if ($listenerChainSpecs !== []) {
            $this->applyListenerChainRecompute(
                bounds: $from,
                scope: $scope,
                definitions: $listenerChainSpecs,
                includeSelf: false,
                excludeBounds: $from,   // exclude moving subtree from scan
            );
        }

        /** @var array<string, AggregateDefinition> $chainRecomputes */
        $chainRecomputes = [];
        foreach (AggregateRegistry::for(static::class) as $def) {
            if (! $def instanceof AggregateDefinition) {
                continue;
            }
            // Exclusive defs (any function/filter), inclusive defs with
            // a raw filter, and bitwise rollups all ride the
            // chain-recompute path on the old chain. The moving
            // subtree is logically excluded via excludeBounds since
            // the structural SQL hasn't run.
            $isRawFilter = $def->filter instanceof FilterPredicate
                && $def->filter->getKind() === FilterPredicateKind::Raw;
            if (! $def->inclusive || $isRawFilter || self::requiresChainRecompute($def->function)) {
                $chainRecomputes[$def->column] = $def;
            }
        }
        if ($chainRecomputes !== []) {
            $this->applyChainRecompute(
                bounds: $from,
                scope: $scope,
                definitions: $chainRecomputes,
                excludeBounds: $from,
            );
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
        if (self::$deferredDepth > 0) {
            return;
        }

        [$sumCountDeltas, , $candidateExtremes] = $this->collectMoveSubtreeContribution();

        /** @var array<string, AggregateDefinition> $chainRecomputes */
        $chainRecomputes = [];
        /** @var array<string, ListenerAggregateDefinition> $listenerChainSpecs */
        $listenerChainSpecs = [];

        foreach (AggregateRegistry::for(static::class) as $def) {
            if ($def instanceof AggregateDefinition) {
                $isRawFilter = $def->filter instanceof FilterPredicate
                    && $def->filter->getKind() === FilterPredicateKind::Raw;
                if (! $def->inclusive || $isRawFilter || self::requiresChainRecompute($def->function)) {
                    $chainRecomputes[$def->column] = $def;
                }
            } elseif ($def instanceof ListenerAggregateDefinition) {
                if (! $def->isInclusive()
                    || $def->operation === AggregateFunction::Max
                    || $def->operation === AggregateFunction::Min) {
                    $listenerChainSpecs[$def->column] = $def;
                }
            }
        }

        if ($sumCountDeltas === [] && $candidateExtremes === []
            && $chainRecomputes === [] && $listenerChainSpecs === []) {
            return;
        }

        $scope = NestedSetScopeResolver::valuesFor($this);

        if ($sumCountDeltas !== [] || $candidateExtremes !== []) {
            DeltaMaintenance::apply(
                connection: $this->getConnection(),
                table: $this->getTable(),
                lftCol: $this->getLftName(),
                rgtCol: $this->getRgtName(),
                bounds: $to,
                deltas: $sumCountDeltas,
                includeSelf: false,
                scope: $scope,
                avgs: AggregateRegistry::avgCompanionsFor(static::class),
                extremes: $candidateExtremes,
                softDeletedColumn: $this->softDeleteColumn(),
                variances: AggregateRegistry::varianceCompanionsFor(static::class),
                weightedAvgs: AggregateRegistry::weightedAvgCompanionsFor(static::class),
                bools: AggregateRegistry::boolCompanionsFor(static::class),
                means: AggregateRegistry::meanCompanionsFor(static::class),
            );
        }

        if ($chainRecomputes !== []) {
            // New-chain recompute post-move: subtree is at $to now,
            // so a normal recompute over the new chain captures it.
            $this->applyChainRecompute(
                bounds: $to,
                scope: $scope,
                definitions: $chainRecomputes,
            );
        }

        if ($listenerChainSpecs !== []) {
            // Same for listener aggregates: re-evaluate over the new
            // chain's ancestors using each listener's contribution()
            // PHP function.
            $this->applyListenerChainRecompute(
                bounds: $to,
                scope: $scope,
                definitions: $listenerChainSpecs,
            );
        }

        $this->dispatchAggregatesRecomputed('move');
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
     * @return array{0: array<string, int|float>, 1: array<string, array{function: AggregateFunction, source: string, filterValue: int|float, filter: FilterPredicate|null}>, 2: array<string, array{function: AggregateFunction, value: int|float}>}
     */
    private function collectMoveSubtreeContribution(): array
    {
        $sumCount = [];
        $minMaxRecomputes = [];
        $extremes = [];

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }
            if (! $definition->inclusive) {
                continue;
            }

            if ($definition->function === AggregateFunction::Sum
                || $definition->function === AggregateFunction::Count) {
                // Preserve numeric type — see captureSubtreeContribution()
                // for why decimal WeightedAvg / GeometricMean / HarmonicMean
                // companions need this.
                $value = Numeric::asNumericOrZero($this->getAttribute($definition->column));
                if ($value != 0) {
                    $sumCount[$definition->column] = $value;
                }

                continue;
            }

            if (($definition->function === AggregateFunction::Max
                || $definition->function === AggregateFunction::Min)
                && $definition->source !== null
            ) {
                // Stored NULL means the subtree has no matching candidates
                // (filtered MIN/MAX with no in-filter descendants, or empty
                // subtree). Propagating a 0 candidate would clobber the
                // destination's NULL into 0 via the cheap-delta path.
                $rawStored = $this->getAttribute($definition->column);
                if ($rawStored === null) {
                    continue;
                }
                $stored = Numeric::asIntOrZero($rawStored);
                $minMaxRecomputes[$definition->column] = [
                    'function' => $definition->function,
                    'source' => $definition->source,
                    'filterValue' => $stored,
                    'filter' => $definition->filter,
                ];
                $extremes[$definition->column] = [
                    'function' => $definition->function,
                    'value' => $stored,
                ];
            }
        }

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition instanceof ListenerAggregateDefinition) {
                continue;
            }
            if (! $definition->isInclusive()) {
                continue;
            }

            $op = $definition->operation;

            // AVG: maintained via companions; skip in the move-subtree
            // contribution pass.
            if ($op === AggregateFunction::Avg) {
                continue;
            }

            if ($op === AggregateFunction::Sum || $op === AggregateFunction::Count) {
                // Stored column holds the inclusive subtree total.
                // Type-preserving read: listener columns may be DECIMAL.
                $value = Numeric::asNumericOrZero($this->getAttribute($definition->column));
                if ($value != 0) {
                    $sumCount[$definition->column] = $value;
                }

                continue;
            }

            // Min / Max — only remaining ops after Sum/Count/Avg above.
            // Use stored extremum for cheap-delta (extend new chain) and
            // as the recompute filterValue for old chain.
            // Stored NULL means the moved subtree has no matching
            // contributions; skip so the cheap-delta doesn't propagate
            // a fake 0 candidate (would clobber the destination's NULL).
            $rawStored = $this->getAttribute($definition->column);
            if ($rawStored === null) {
                continue;
            }
            $stored = Numeric::asNumericOrZero($rawStored);
            $minMaxRecomputes[$definition->column] = [
                'function' => $op,
                'source' => $definition->column,   // sentinel — not used by listener recompute
                'filterValue' => $stored,
                'filter' => null,
            ];
            $extremes[$definition->column] = ['function' => $op, 'value' => $stored];
        }

        return [$sumCount, $minMaxRecomputes, $extremes];
    }

    /**
     * @param  array<string, array{function: AggregateFunction, source: string, filterValue: int|float, filter: FilterPredicate|null}>  $minMaxByFunction
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
                'filter' => $spec['filter'],
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
            locking: self::aggregateLockingMode(),
            excludeBounds: $excludeBounds,
            softDeletedColumn: $this->softDeleteColumn(),
            idCol: $this->getKeyName(),
        );
    }

    /**
     * `restored` hook (soft delete only): the subtree's stored
     * aggregates were left intact during the cascade soft-delete, but
     * the ancestor chain's aggregates were decremented by
     * {@see applyAggregateOnDelete()}. On restore we re-sync via:
     *
     *   1. fixAggregates(self) — recompute every stored aggregate on
     *      this subtree from the (now-live) set, since intervening
     *      mutations may have invalidated them.
     *   2. Chain recompute on ancestors for every declared aggregate —
     *      not a delta. A delta of self.stored would over-count when a
     *      descendant of self was independently restored earlier and
     *      had its own contribution credited to live ancestors at
     *      that point.
     *
     * Pre-snapshot-semantics builds used a delta path here. That path
     * is wrong under partial restores, so we always recompute.
     */
    public function applyAggregateOnRestore(): void
    {
        if (self::$deferredDepth > 0) {
            return;
        }

        if (! $this->isPlacedInTree()) {
            return;
        }

        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive(static::class), true);

        if ($usesSoftDeletes) {
            // Step 1: recompute self's subtree from the current live
            // set. Cascade restore has already un-trashed matching
            // descendants, so the live subtree is final.
            self::fixAggregates($this);
            $this->refresh();

            // Step 2: chain-recompute every aggregate on the ancestor
            // chain. Skips trashed ancestors via Eloquent's default
            // scope, which is exactly the snapshot-semantics
            // behaviour we want.
            $scope = NestedSetScopeResolver::valuesFor($this);

            /** @var array<string, AggregateDefinition> $sqlDefs */
            $sqlDefs = [];
            /** @var array<string, ListenerAggregateDefinition> $listenerDefs */
            $listenerDefs = [];
            foreach (AggregateRegistry::for(static::class) as $def) {
                if ($def instanceof AggregateDefinition) {
                    $sqlDefs[$def->column] = $def;
                } elseif ($def instanceof ListenerAggregateDefinition) {
                    $listenerDefs[$def->column] = $def;
                }
            }

            if ($sqlDefs !== []) {
                $this->applyChainRecompute($this->getBounds(), $scope, $sqlDefs);
            }
            if ($listenerDefs !== []) {
                $this->applyListenerChainRecompute(
                    bounds: $this->getBounds(),
                    scope: $scope,
                    definitions: $listenerDefs,
                    includeSelf: false,
                );
            }

            $this->dispatchAggregatesRecomputed('on_restore');

            return;
        }

        // Non-soft-delete models: keep the original delta path.
        $deltas = [];
        $extremes = [];
        /** @var array<string, AggregateDefinition> $chainRecomputes */
        $chainRecomputes = [];

        /** @var array<string, ListenerAggregateDefinition> $listenerChainSpecs */
        $listenerChainSpecs = [];

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }

            // Exclusive defs: chain recompute (uniform across functions).
            if (! $definition->inclusive) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            if ($definition->filter instanceof FilterPredicate
                && $definition->filter->getKind() === FilterPredicateKind::Raw) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            if (self::requiresChainRecompute($definition->function)) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            if ($definition->function === AggregateFunction::Sum
                || $definition->function === AggregateFunction::Count) {
                // Preserve numeric type — decimal Sum companions of
                // WeightedAvg / GeometricMean / HarmonicMean would lose
                // their fraction under numeric().
                $value = Numeric::asNumericOrZero($this->getAttribute($definition->column));
                if ($value != 0) {
                    $deltas[$definition->column] = $value;
                }

                continue;
            }

            if (($definition->function === AggregateFunction::Max
                || $definition->function === AggregateFunction::Min)
                && $definition->source !== null
            ) {
                $value = Numeric::asIntOrZero($this->getAttribute($definition->column));
                $extremes[$definition->column] = [
                    'function' => $definition->function,
                    'value' => $value,
                ];

                continue;
            }

            if (in_array($definition->function, [AggregateFunction::BitOr, AggregateFunction::BitAnd, AggregateFunction::BitXor], true)
                && $definition->source !== null
            ) {
                // Restore via chain recompute for all three bitwise
                // kinds. BitXor could ride the delta path, but the
                // non-soft-delete restore is rare enough that the
                // uniform path is the right trade-off.
                $chainRecomputes[$definition->column] = $definition;
            }
        }

        foreach (AggregateRegistry::for(static::class) as $definition) {
            if (! $definition instanceof ListenerAggregateDefinition) {
                continue;
            }

            if (! $definition->isInclusive()) {
                // Exclusive listener (any function): chain recompute.
                $listenerChainSpecs[$definition->column] = $definition;

                continue;
            }

            $op = $definition->operation;

            // AVG: companions handle delta; AVG display written by
            // DeltaMaintenance's $avgs SET clause.
            if ($op === AggregateFunction::Avg) {
                continue;
            }

            if ($op === AggregateFunction::Sum || $op === AggregateFunction::Count) {
                $value = Numeric::asNumericOrZero($this->getAttribute($definition->column));
                if ($value != 0) {
                    $deltas[$definition->column] = $value;
                }

                continue;
            }

            // Min / Max — only remaining ops after Sum/Count/Avg above
            // for SQL listeners. (Bitwise listener aggregates are
            // rejected at ListenerAggregateDefinition construction.)
            $value = Numeric::asNumericOrZero($this->getAttribute($definition->column));
            $extremes[$definition->column] = ['function' => $op, 'value' => $value];
        }

        if ($deltas === [] && $extremes === [] && $chainRecomputes === [] && $listenerChainSpecs === []) {
            return;
        }

        $scope = NestedSetScopeResolver::valuesFor($this);

        if ($deltas !== [] || $extremes !== []) {
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
                extremes: $extremes,
                softDeletedColumn: $this->softDeleteColumn(),
                variances: AggregateRegistry::varianceCompanionsFor(static::class),
                weightedAvgs: AggregateRegistry::weightedAvgCompanionsFor(static::class),
                bools: AggregateRegistry::boolCompanionsFor(static::class),
                means: AggregateRegistry::meanCompanionsFor(static::class),
            );
        }

        if ($chainRecomputes !== []) {
            $this->applyChainRecompute($this->getBounds(), $scope, $chainRecomputes);
        }

        if ($listenerChainSpecs !== []) {
            $this->applyListenerChainRecompute(
                bounds: $this->getBounds(),
                scope: $scope,
                definitions: $listenerChainSpecs,
            );
        }

        $this->dispatchAggregatesRecomputed('on_restore');
    }

    /**
     * Overrides {@see Model::replicate()} so cloned models never
     * inherit the source's stored aggregate values OR its tree
     * position. Aggregate columns on the clone reset to the
     * function's "empty" element (0 for SUM / COUNT, NULL for AVG /
     * MIN / MAX); structural columns (`lft` / `rgt` / `depth` /
     * `parent_id`) reset to the migration default so the clone
     * presents as "unplaced" until the caller explicitly places it
     * via `appendToNode(...)->save()` or `makeRoot()->save()`.
     *
     * Without the structural reset, an accidental `->save()` on the
     * clone would write a duplicate at the source's bounds, breaking
     * the lft/rgt invariant.
     *
     * Also clears `deleted_at` so a clone of a trashed row starts
     * un-trashed — the clone is a template for a new placement, not
     * a continuation of the source's lifecycle.
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
        // The user must call `appendToNode(...)`/`makeRoot()` to put
        // the clone somewhere.
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

        if (($deletedAtColumn = $this->softDeleteColumn()) !== null) {
            $clone->setAttribute($deletedAtColumn, null);
        }

        return $clone;
    }

    /**
     * PHP-based recompute of listener Min/Max aggregate columns for all
     * ancestors of the given bounds. For each ancestor, loads all
     * descendants via Eloquent, calls the listener on each, and takes
     * the max or min of the contributions.
     *
     * @param  array<string, ListenerAggregateDefinition>  $definitions  column => definition
     * @param  array<string, mixed>  $scope
     */
    private function applyListenerChainRecompute(
        NodeBounds $bounds,
        array $scope,
        array $definitions,
        bool $includeSelf = true,
        ?NodeBounds $excludeBounds = null,
    ): void {
        if ($definitions === []) {
            return;
        }

        $lftCol = $this->getLftName();
        $rgtCol = $this->getRgtName();

        // Load ancestor models.
        $ancestorQuery = static::query()
            ->where($lftCol, '<=', $bounds->lft)
            ->where($rgtCol, '>=', $bounds->rgt);

        foreach ($scope as $col => $value) {
            $ancestorQuery->where($col, $value);
        }

        if (! $includeSelf) {
            $lft = $bounds->lft;
            $rgt = $bounds->rgt;
            $ancestorQuery->where(static function ($q) use ($lftCol, $rgtCol, $lft, $rgt): void {
                $q->where($lftCol, '!=', $lft)->orWhere($rgtCol, '!=', $rgt);
            });
        }

        $ancestors = $ancestorQuery->get();

        if ($ancestors->isEmpty()) {
            return;
        }

        // Ancestors are nested intervals — the topmost has the smallest
        // lft and largest rgt and covers every other ancestor's subtree.
        // Loading nodes under that one bounding box once is a superset
        // of what any ancestor needs, so the per-ancestor descendant
        // scan reduces from one SELECT to in-memory filtering.
        $topLft = PHP_INT_MAX;
        $topRgt = PHP_INT_MIN;
        foreach ($ancestors as $ancestor) {
            $aLft = Numeric::asIntOrZero($ancestor->getAttribute($lftCol));
            $aRgt = Numeric::asIntOrZero($ancestor->getAttribute($rgtCol));
            if ($aLft < $topLft) {
                $topLft = $aLft;
            }
            if ($aRgt > $topRgt) {
                $topRgt = $aRgt;
            }
        }

        $nodesQuery = static::query()
            ->where($lftCol, '>=', $topLft)
            ->where($rgtCol, '<=', $topRgt);
        foreach ($scope as $col => $value) {
            $nodesQuery->where($col, $value);
        }

        // Stream the bounding-box subtree via cursor: build the
        // contribution cache and bounds list in one pass, releasing
        // each hydrated Model immediately. Pre-stream, the full
        // collection lived in memory for the entire ancestor-walk
        // loop — ~3KB per Eloquent model × subtree size. After
        // streaming, only the scalar cache and bounds list survive
        // (~50-100 bytes per node).
        /** @var array<string, TreeAggregateListener> $listeners */
        $listeners = [];
        /** @var array<string, array<int|string, int|float|null>> $contribCache */
        $contribCache = [];
        foreach ($definitions as $column => $definition) {
            $listeners[$column] = $definition->makeListener();
            $contribCache[$column] = [];
        }

        /** @var list<array{key: int|string, lft: int, rgt: int}> $nodeBounds */
        $nodeBounds = [];
        foreach ($nodesQuery->cursor() as $node) {
            $key = $node->getKey();
            if (! is_int($key) && ! is_string($key)) {
                continue;
            }
            $nodeBounds[] = [
                'key' => $key,
                'lft' => Numeric::asIntOrZero($node->getAttribute($lftCol)),
                'rgt' => Numeric::asIntOrZero($node->getAttribute($rgtCol)),
            ];
            foreach ($listeners as $column => $listener) {
                $contribCache[$column][$key] = $listener->contribution($node);
            }
        }

        $eLft = $excludeBounds instanceof NodeBounds ? $excludeBounds->lft : null;
        $eRgt = $excludeBounds instanceof NodeBounds ? $excludeBounds->rgt : null;

        foreach ($ancestors as $ancestor) {
            $aLft = Numeric::asIntOrZero($ancestor->getAttribute($lftCol));
            $aRgt = Numeric::asIntOrZero($ancestor->getAttribute($rgtCol));

            $updates = [];

            foreach ($definitions as $column => $definition) {
                $inclusive = $definition->isInclusive();
                /** @var list<int|float> $candidates */
                $candidates = [];

                foreach ($nodeBounds as $nb) {
                    $nLft = $nb['lft'];
                    $nRgt = $nb['rgt'];

                    $inBounds = $inclusive
                        ? ($nLft >= $aLft && $nRgt <= $aRgt)
                        : ($nLft > $aLft && $nRgt < $aRgt);

                    if (! $inBounds) {
                        continue;
                    }

                    if ($eLft !== null && $eRgt !== null
                        && $nLft >= $eLft && $nRgt <= $eRgt) {
                        continue;
                    }

                    $contrib = $contribCache[$column][$nb['key']] ?? null;
                    if ($contrib !== null) {
                        $candidates[] = $contrib;
                    }
                }

                $updates[$column] = self::applyListenerOperation($definition, $candidates);
            }

            $this->getConnection()->table($this->getTable())
                ->where($this->getKeyName(), $ancestor->getKey())
                ->update($updates);
        }
    }

    private function resolveDefinitionByColumn(string $column): AggregateDefinitionContract
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

    // ----------------------------------------------------------------
    // Listener aggregate repair helpers (Phase 9)
    // ----------------------------------------------------------------

    /**
     * PHP-based fix pass for listener aggregate columns.
     *
     * Loads all in-scope Eloquent models once, computes contributions
     * in PHP, aggregates per outer node, and writes drifted rows.
     *
     * @param  list<ListenerAggregateDefinition>  $definitions
     * @param  array<string, mixed>  $scope
     * @param  list<int|string>|null  $outerIds  null = fix all
     */
    private static function fixListenerAggregatesPhp(
        array $definitions,
        array $scope,
        int|string|null $rootId,
        ?array $outerIds,
    ): AggregateFixResult {
        if ($definitions === []) {
            return new AggregateFixResult(totalRowsUpdated: 0, perColumn: []);
        }

        $instance = new static;
        $lftCol = $instance->getLftName();
        $rgtCol = $instance->getRgtName();

        $perColumn = [];
        foreach ($definitions as $def) {
            if (! $def->isInternal()) {
                $perColumn[$def->column] = 0;
            }
        }

        // Empty-chunk short-circuit: chunked callers pass $outerIds = []
        // to mean "no outer rows in this chunk". Skipping buildListenerNodeMeta
        // avoids streaming the entire in-scope subtree just to write zero rows.
        if ($outerIds !== null && $outerIds === []) {
            return new AggregateFixResult(totalRowsUpdated: 0, perColumn: $perColumn);
        }

        // Stream once and project each model into a scalar meta entry
        // (bounds + per-definition contribution + per-definition stored
        // value). Peak hydrated memory is O(1); the meta list is ~150
        // bytes per node vs ~3KB for the full Eloquent model.
        $nodeMeta = self::buildListenerNodeMeta(
            $definitions,
            $scope,
            $rootId,
            $lftCol,
            $rgtCol,
            includeStored: true,
        );

        if ($nodeMeta === []) {
            return new AggregateFixResult(totalRowsUpdated: 0, perColumn: $perColumn);
        }

        // keyed by node id: ['id' => mixed, 'updates' => array<string, mixed>]
        $toUpdate = [];

        foreach ($definitions as $def) {
            foreach ($nodeMeta as $outer) {
                $outerKey = $outer['key'];

                // Chunked mode: skip outer nodes outside this chunk.
                if ($outerIds !== null && ! in_array($outerKey, $outerIds, true)) {
                    continue;
                }

                $outerLft = $outer['lft'];
                $outerRgt = $outer['rgt'];
                /** @var list<int|float> $innerContribs */
                $innerContribs = [];

                foreach ($nodeMeta as $inner) {
                    $innerLft = $inner['lft'];
                    $innerRgt = $inner['rgt'];

                    $inBounds = $def->isInclusive()
                        ? ($innerLft >= $outerLft && $innerRgt <= $outerRgt)
                        : ($innerLft > $outerLft && $innerRgt < $outerRgt);

                    if (! $inBounds) {
                        continue;
                    }

                    $contrib = $inner['contribs'][$def->column] ?? null;
                    if ($contrib !== null) {
                        $innerContribs[] = $contrib;
                    }
                }

                $computed = self::applyListenerOperation($def, $innerContribs);
                $stored = $outer['stored'][$def->column] ?? null;

                if (! TreeAggregateBuilder::aggregatesEqual($stored, $computed)) {
                    $toUpdate[$outerKey] ??= ['id' => $outerKey, 'updates' => []];
                    $toUpdate[$outerKey]['updates'][$def->column] = $computed;
                    if (! $def->isInternal()) {
                        $perColumn[$def->column] = ($perColumn[$def->column] ?? 0) + 1;
                    }
                }
            }
        }

        // Write back drifted rows (per-row UPDATE; listener fix is infrequent).
        $totalRowsUpdated = 0;
        $keyName = $instance->getKeyName();
        foreach ($toUpdate as $row) {
            $updated = $instance->getConnection()
                ->table($instance->getTable())
                ->where($keyName, $row['id'])
                ->update($row['updates']);
            $totalRowsUpdated += $updated;
        }

        return new AggregateFixResult(
            totalRowsUpdated: $totalRowsUpdated,
            perColumn: $perColumn,
        );
    }

    /**
     * Streams in-scope listener nodes via cursor() and projects each
     * model into a scalar metadata entry — bounds, per-definition
     * contribution value, and (optionally) stored aggregate value.
     *
     * Replaces the previous `loadAllListenerNodes()` collection-based
     * read: peak hydrated-model memory drops from O(N) to O(1) at the
     * cost of O(N) scalar meta entries. For typical Eloquent models
     * (~3KB) and 1-3 listener definitions, this is a ~15-20x memory
     * reduction during PHP-side listener repair / error scans.
     *
     * @param  list<ListenerAggregateDefinition>  $definitions
     * @param  array<string, mixed>  $scope
     * @return list<array{key: int|string, lft: int, rgt: int, contribs: array<string, int|float|null>, stored: array<string, mixed>}>
     */
    private static function buildListenerNodeMeta(
        array $definitions,
        array $scope,
        int|string|null $rootId,
        string $lftCol,
        string $rgtCol,
        bool $includeStored,
    ): array {
        $query = static::query();

        foreach ($scope as $col => $value) {
            $query->where($col, $value);
        }

        if ($rootId !== null) {
            $instance = new static;
            $rootRow = $instance->getConnection()
                ->table($instance->getTable())
                ->where($instance->getKeyName(), $rootId)
                ->first([$lftCol, $rgtCol]);

            if ($rootRow === null) {
                return [];
            }

            $query->where($lftCol, '>=', (int) $rootRow->{$lftCol})
                ->where($rgtCol, '<=', (int) $rootRow->{$rgtCol});
        }

        $listeners = [];
        foreach ($definitions as $def) {
            $listeners[$def->column] = $def->makeListener();
        }

        $meta = [];
        foreach ($query->cursor() as $node) {
            $key = $node->getKey();
            if (! is_int($key) && ! is_string($key)) {
                continue;
            }

            $contribs = [];
            $stored = [];
            foreach ($definitions as $def) {
                $contribs[$def->column] = $listeners[$def->column]->contribution($node);
                if ($includeStored) {
                    $stored[$def->column] = $node->getAttribute($def->column);
                }
            }

            $meta[] = [
                'key' => $key,
                'lft' => Numeric::asIntOrZero($node->getAttribute($lftCol)),
                'rgt' => Numeric::asIntOrZero($node->getAttribute($rgtCol)),
                'contribs' => $contribs,
                'stored' => $stored,
            ];
        }

        return $meta;
    }

    /**
     * Applies the listener's operation to a flat list of contributions.
     *
     * @param  list<int|float>  $contributions  (nulls already filtered out)
     */
    private static function applyListenerOperation(
        ListenerAggregateDefinition $def,
        array $contributions,
    ): int|float|null {
        return match ($def->operation) {
            AggregateFunction::Sum => $contributions === [] ? 0 : array_sum($contributions),
            AggregateFunction::Count => count($contributions),
            AggregateFunction::Min => $contributions === [] ? null : min($contributions),
            AggregateFunction::Max => $contributions === [] ? null : max($contributions),
            AggregateFunction::Avg => $contributions === []
                ? null
                : array_sum($contributions) / count($contributions),
            AggregateFunction::Variance, AggregateFunction::Stddev => throw new \LogicException(
                'Variance / Stddev are not supported for listener aggregates. '
                .'Use a SQL aggregate (Aggregate::variance / ::stddev) or maintain Sum + Count manually.',
            ),
            AggregateFunction::BitOr,
            AggregateFunction::BitAnd,
            AggregateFunction::BitXor => throw new \LogicException(
                'Bitwise listener aggregates are not supported — ListenerAggregateDefinition rejects them at construction.',
            ),
            AggregateFunction::WeightedAvg,
            AggregateFunction::BoolOr,
            AggregateFunction::BoolAnd,
            AggregateFunction::GeometricMean,
            AggregateFunction::HarmonicMean,
            AggregateFunction::DistinctCount,
            AggregateFunction::StringAgg,
            AggregateFunction::JsonAgg,
            AggregateFunction::JsonObjectAgg,
            AggregateFunction::Median,
            AggregateFunction::Percentile => throw new AggregateConfigurationException(sprintf(
                'Listener aggregates do not support %s; declare it via #[NestedSetAggregate] (column-based) instead.',
                $def->operation->value,
            )),
        };
    }

    /** @return list<ListenerAggregateDefinition> */
    private static function listenerDefinitions(): array
    {
        $defs = [];
        foreach (AggregateRegistry::for(static::class) as $def) {
            if ($def instanceof ListenerAggregateDefinition) {
                $defs[] = $def;
            }
        }

        return $defs;
    }

    private static function mergeFixResults(AggregateFixResult $a, AggregateFixResult $b): AggregateFixResult
    {
        $perColumn = $a->perColumn;
        foreach ($b->perColumn as $col => $count) {
            $perColumn[$col] = ($perColumn[$col] ?? 0) + $count;
        }

        return new AggregateFixResult(
            totalRowsUpdated: $a->totalRowsUpdated + $b->totalRowsUpdated,
            perColumn: $perColumn,
        );
    }

    /**
     * Counts stored-vs-computed disagreements for listener aggregate columns.
     *
     * @param  list<ListenerAggregateDefinition>  $definitions
     * @param  array<string, mixed>  $scope
     * @return array<string, int>
     */
    private static function aggregateErrorsForListeners(
        array $definitions,
        array $scope,
        int|string|null $rootId,
    ): array {
        $errors = [];
        foreach ($definitions as $def) {
            if (! $def->isInternal()) {
                $errors[$def->column] = 0;
            }
        }

        if ($definitions === []) {
            return $errors;
        }

        $instance = new static;
        $lftCol = $instance->getLftName();
        $rgtCol = $instance->getRgtName();

        // Skip internal definitions when building the meta — they don't
        // contribute to the user-visible error count and would just waste
        // contribution() calls per node.
        $userDefs = array_values(array_filter(
            $definitions,
            static fn (ListenerAggregateDefinition $d): bool => ! $d->isInternal(),
        ));
        if ($userDefs === []) {
            return $errors;
        }

        $nodeMeta = self::buildListenerNodeMeta(
            $userDefs,
            $scope,
            $rootId,
            $lftCol,
            $rgtCol,
            includeStored: true,
        );

        if ($nodeMeta === []) {
            return $errors;
        }

        foreach ($userDefs as $def) {
            foreach ($nodeMeta as $outer) {
                $outerLft = $outer['lft'];
                $outerRgt = $outer['rgt'];
                /** @var list<int|float> $innerContribs */
                $innerContribs = [];

                foreach ($nodeMeta as $inner) {
                    $innerLft = $inner['lft'];
                    $innerRgt = $inner['rgt'];

                    $inBounds = $def->isInclusive()
                        ? ($innerLft >= $outerLft && $innerRgt <= $outerRgt)
                        : ($innerLft > $outerLft && $innerRgt < $outerRgt);

                    if (! $inBounds) {
                        continue;
                    }

                    $contrib = $inner['contribs'][$def->column] ?? null;
                    if ($contrib !== null) {
                        $innerContribs[] = $contrib;
                    }
                }

                $computed = self::applyListenerOperation($def, $innerContribs);
                $stored = $outer['stored'][$def->column] ?? null;

                if (! TreeAggregateBuilder::aggregatesEqual($stored, $computed)) {
                    $errors[$def->column] = ($errors[$def->column] ?? 0) + 1;
                }
            }
        }

        return $errors;
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
        $instance = self::aggregateAnchorOrFail($anchor);
        $rootId = self::anchorRootId($anchor);
        $scope = $anchor instanceof Model
            ? NestedSetScopeResolver::valuesFor($anchor)
            : [];

        $treeBuilderErrors = TreeAggregateBuilder::aggregateErrors(
            connection: $instance->getConnection(),
            table: $instance->getTable(),
            lftCol: $instance->getLftName(),
            rgtCol: $instance->getRgtName(),
            scope: $scope,
            definitions: AggregateRegistry::for(static::class),
            rootId: $rootId,
            parentIdCol: $instance->getParentIdName(),
            depthCol: $instance->getDepthName(),
            softDeletedColumn: $instance->softDeleteColumn(),
            idCol: $instance->getKeyName(),
        );

        $listenerErrors = self::aggregateErrorsForListeners(
            definitions: self::listenerDefinitions(),
            scope: $scope,
            rootId: $rootId,
        );

        // Columns never overlap between SQL and listener defs.
        $merged = array_merge($treeBuilderErrors, $listenerErrors);

        $nonZero = array_filter($merged, static fn (int $count): bool => $count > 0);
        if ($nonZero !== []) {
            EventDispatcher::dispatch(new AggregateDriftDetected(
                modelClass: static::class,
                anchorId: $rootId,
                perColumn: $nonZero,
                totalDrift: array_sum($nonZero),
            ));
        }

        return $merged;
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
     *     onChunk: function (AggregateFixResult $chunk, int $i, int|string|null $cursor) {
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
            return self::fixAggregatesChunked($anchor, $chunkSize, $onChunk);
        }

        $instance = self::aggregateWriteAnchorOrFail($anchor);
        $rootId = self::anchorRootId($anchor);
        $scope = $anchor instanceof Model
            ? NestedSetScopeResolver::valuesFor($anchor)
            : [];

        $startNs = hrtime(true);
        $sqlResult = TreeAggregateBuilder::fixAggregates(
            connection: $instance->getConnection(),
            table: $instance->getTable(),
            lftCol: $instance->getLftName(),
            rgtCol: $instance->getRgtName(),
            scope: $scope,
            definitions: AggregateRegistry::for(static::class),
            rootId: $rootId,
            parentIdCol: $instance->getParentIdName(),
            depthCol: $instance->getDepthName(),
            softDeletedColumn: $instance->softDeleteColumn(),
            idCol: $instance->getKeyName(),
        );

        $listenerResult = self::fixListenerAggregatesPhp(
            definitions: self::listenerDefinitions(),
            scope: $scope,
            rootId: $rootId,
            outerIds: null,
        );

        $result = self::mergeFixResults($sqlResult, $listenerResult);
        $durationMs = (hrtime(true) - $startNs) / 1_000_000;

        EventDispatcher::dispatch(new FixAggregatesCompleted(
            modelClass: static::class,
            anchorId: $rootId,
            totalRowsUpdated: $result->totalRowsUpdated,
            perColumn: $result->perColumn,
            durationMs: $durationMs,
            chunkSize: null,
            totalChunks: 1,
        ));

        return $result;
    }

    /**
     * Synchronous chunk-loop counterpart to {@see self::fixAggregates()}.
     * Drives `fixAggregatesChunk` from cursor=null until it returns
     * nextAfterId=null, accumulating per-chunk results into one combined
     * AggregateFixResult.
     */
    private static function fixAggregatesChunked(
        ?HasNestedSet $anchor,
        int $chunkSize,
        ?\Closure $onChunk,
    ): AggregateFixResult {
        $totalRows = 0;
        /** @var array<string, int> $perColumn */
        $perColumn = [];

        $cursor = null;
        $chunkIndex = 0;
        $anchorRootId = self::anchorRootId($anchor);
        $loopStartNs = hrtime(true);

        // Non-progress detector: the loop trusts `nextAfterId` to
        // eventually return null. A buggy backend or corrupted index
        // could return the same cursor forever; we abort if the
        // cursor fails to advance across consecutive iterations
        // rather than capping total iterations (which would falsely
        // trip on legitimate small-chunkSize runs over large tables).
        $prevCursor = null;
        $cursorRepeats = 0;

        do {
            $chunkStartNs = hrtime(true);
            /** @var array{result: AggregateFixResult, nextAfterId: int|string|null} $chunk */
            $chunk = static::fixAggregatesChunk($anchor, $cursor, $chunkSize);
            $chunkMs = (hrtime(true) - $chunkStartNs) / 1_000_000;
            $result = $chunk['result'];

            $totalRows += $result->totalRowsUpdated;
            foreach ($result->perColumn as $column => $count) {
                $perColumn[$column] = ($perColumn[$column] ?? 0) + $count;
            }

            $prevCursor = $cursor;
            $cursor = $chunk['nextAfterId'];

            EventDispatcher::dispatch(new FixAggregatesChunkCompleted(
                modelClass: static::class,
                anchorId: $anchorRootId,
                chunkIndex: $chunkIndex,
                chunkSize: $chunkSize,
                rowsUpdated: $result->totalRowsUpdated,
                cursorAfter: $cursor,
                durationMs: $chunkMs,
            ));

            if ($onChunk instanceof \Closure) {
                $onChunk($result, $chunkIndex, $cursor);
            }

            $chunkIndex++;

            if ($cursor !== null && $cursor === $prevCursor) {
                if (++$cursorRepeats > 2) {
                    throw new \RuntimeException(sprintf(
                        'fixAggregates(chunkSize: …): cursor stuck at %s — chunk loop is not advancing.',
                        (string) $cursor,
                    ));
                }
            } else {
                $cursorRepeats = 0;
            }
        } while ($cursor !== null);

        EventDispatcher::dispatch(new FixAggregatesCompleted(
            modelClass: static::class,
            anchorId: $anchorRootId,
            totalRowsUpdated: $totalRows,
            perColumn: $perColumn,
            durationMs: (hrtime(true) - $loopStartNs) / 1_000_000,
            chunkSize: $chunkSize,
            totalChunks: $chunkIndex,
        ));

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
     * Pagination uses `WHERE id > X ORDER BY id LIMIT N`, which assumes
     * the PK is **monotonically ordered** for the model's chosen
     * scheme. Auto-increment bigint, UUIDv7, ULID, and any
     * lexicographically-ascending string all qualify. UUIDv4, nanoid
     * (default), or any other random key will lexicographically reorder
     * rows the loop should have visited — silently skipping or
     * duplicating them. For random PKs use the unchunked
     * {@see self::fixAggregates()} path instead.
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
        $instance = self::aggregateWriteAnchorOrFail($anchor);
        $rootId = self::anchorRootId($anchor);

        if ($chunkSize <= 0) {
            throw new InvalidArgumentException('fixAggregatesChunk: chunkSize must be > 0.');
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
                ->where($instance->getKeyName(), $rootId)
                ->first([$instance->getLftName(), $instance->getRgtName()]);
            if ($rootRow !== null) {
                $query->where($instance->getLftName(), '>=', $rootRow->{$instance->getLftName()})
                    ->where($instance->getRgtName(), '<=', $rootRow->{$instance->getRgtName()});
            }
        }

        $isIntKey = $instance->getKeyType() === 'int';
        $ids = array_values(array_map(
            static function (\stdClass $row) use ($instance, $isIntKey): int|string {
                $value = $row->{$instance->getKeyName()} ?? null;

                if ($isIntKey) {
                    return (int) $value;
                }

                return (string) $value;
            },
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
            softDeletedColumn: $instance->softDeleteColumn(),
            idCol: $instance->getKeyName(),
        );

        $listenerChunkResult = self::fixListenerAggregatesPhp(
            definitions: self::listenerDefinitions(),
            scope: $scope,
            rootId: $rootId,
            outerIds: $ids,
        );

        $result = self::mergeFixResults($result, $listenerChunkResult);

        // A short final chunk (fewer rows than asked for) means we've
        // reached the end of the table — no further dispatch needed.
        $nextAfterId = count($ids) === $chunkSize ? end($ids) : null;

        return ['result' => $result, 'nextAfterId' => $nextAfterId];
    }

    /**
     * Runs `$work` with per-row aggregate maintenance suspended, then
     * fires one `fixAggregates($anchor)` at the outermost exit to
     * repair the cumulative drift in a single pass.
     *
     * Compared to the unwrapped path:
     *
     *  - Every Eloquent event (`saving`/`created`/`saved`/`deleted` etc.)
     *    still fires per row, so observers, mutators, casts, and
     *    mass-assignment guards behave exactly as they would outside
     *    the block. Only the trait's *aggregate-column* side-effects
     *    are deferred.
     *  - The wrapper is re-entrant — nested calls share one deferral
     *    counter, and only the outermost call triggers the final fix.
     *  - Failure-safe — if `$work` throws, the deferral counter is
     *    still decremented in `finally` and the repair still fires
     *    before the exception propagates. Leaving the table half-
     *    repaired would be worse than spending the fix cost.
     *
     * The trade-off: every save inside the closure pays no
     * aggregate-maintenance cost, but the final `fixAggregates`
     * touches every row whose stored aggregates may have drifted.
     * Worthwhile when the closure does many small mutations
     * (N × `appendToNode->save()`, a CSV import through Eloquent,
     * a re-parent script). For one or two saves the unwrapped path
     * is cheaper.
     *
     * ```php
     * Area::withDeferredAggregateMaintenance(function () use ($csv, $parent) {
     *     foreach ($csv as $row) {
     *         $area = new Area($row);
     *         $area->appendToNode($parent)->save();  // events fire,
     *     }                                           // aggregates skipped
     * }, $rootAnchor);                                // one fixAggregates($root) at end
     * ```
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
        // Validate the anchor upfront — scoped models need one for the
        // final fixAggregates call, and a synchronous failure here is
        // friendlier than running the entire closure and only failing
        // at the repair pass.
        self::aggregateWriteAnchorOrFail($anchor);

        self::$deferredDepth++;
        $isOutermost = self::$deferredDepth === 1;
        $closureMs = 0.0;
        $repairMs = 0.0;
        /** @var AggregateFixResult|null $repairResult */
        $repairResult = null;
        $closureFailed = false;

        try {
            if ($isOutermost) {
                // Dispatch inside the try so a throwing listener still
                // hits the finally that decrements $deferredDepth.
                // Without this, a thrown listener would leak the
                // counter and disable aggregate maintenance for the
                // rest of the process.
                EventDispatcher::dispatch(new DeferredMaintenanceStarting(
                    modelClass: static::class,
                    anchorId: self::anchorRootId($anchor),
                ));
            }

            $closureStartNs = hrtime(true);
            $result = $work();
            $closureMs = (hrtime(true) - $closureStartNs) / 1_000_000;

            return $result;
        } catch (\Throwable $e) {
            $closureFailed = true;
            throw $e;
        } finally {
            self::$deferredDepth--;

            // Repair only at the outermost exit — nested calls share
            // the same counter and rely on the outer wrapper to fix.
            if (self::$deferredDepth === 0) {
                // If $work threw, this fires before the exception
                // propagates. Swallow any secondary error so the
                // original throwable wins — losing the original would
                // hide the actual bug.
                try {
                    $repairStartNs = hrtime(true);
                    $repairResult = self::fixAggregates($anchor);
                    $repairMs = (hrtime(true) - $repairStartNs) / 1_000_000;
                } catch (\Throwable $secondary) {
                    // Best effort. The caller can re-run fixAggregates
                    // themselves once they've handled the primary error.
                    error_log(sprintf(
                        'withDeferredAggregateMaintenance: secondary error in fixAggregates after closure failure — %s: %s',
                        $secondary::class,
                        $secondary->getMessage(),
                    ));
                }
            }

            // Only the outermost wrapper emits the boundary event, and
            // only when the user's closure ran without throwing. A
            // throw inside the closure is the user's failure to handle;
            // the event would conflate that with a successful batch
            // boundary.
            if ($isOutermost && ! $closureFailed) {
                EventDispatcher::dispatch(new DeferredAggregateMaintenanceCompleted(
                    modelClass: static::class,
                    anchorId: self::anchorRootId($anchor),
                    rowsFixed: $repairResult === null ? 0 : $repairResult->totalRowsUpdated,
                    closureDurationMs: $closureMs,
                    repairDurationMs: $repairMs,
                ));
            }
        }
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
            $message = sprintf(
                '%s declares a scope (%s); pass an anchor node to queueFixAggregates() so the job knows which tree to repair.',
                static::class,
                implode(', ', $scopeColumns),
            );
            EventDispatcher::dispatch(new ScopeViolationDetected(
                modelClass: static::class,
                stage: 'queue_dispatch',
                message: $message,
            ));
            throw new ScopeViolationException($message);
        }

        $job = new FixAggregatesJob(
            modelClass: static::class,
            anchorId: self::anchorRootId($anchor),
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

        EventDispatcher::dispatch(new FixAggregatesJobDispatched(
            modelClass: static::class,
            anchorId: self::anchorRootId($anchor),
            chunkSize: $chunkSize !== null && $chunkSize > 0 ? $chunkSize : null,
            onConnection: $connection,
            onQueue: $queue,
        ));

        return $job;
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
        $definitions = AggregateRegistry::for(static::class);

        if ($definitions === []) {
            return null;
        }

        $instance = new static;
        $scope = $anchor instanceof Model
            ? NestedSetScopeResolver::valuesFor($anchor)
            : [];

        $sqlResult = TreeAggregateBuilder::fixAggregates(
            connection: $instance->getConnection(),
            table: $instance->getTable(),
            lftCol: $instance->getLftName(),
            rgtCol: $instance->getRgtName(),
            scope: $scope,
            definitions: $definitions,
            rootId: $rootId,
            parentIdCol: $instance->getParentIdName(),
            depthCol: $instance->getDepthName(),
            softDeletedColumn: $instance->softDeleteColumn(),
            idCol: $instance->getKeyName(),
        );

        $listenerResult = self::fixListenerAggregatesPhp(
            definitions: self::listenerDefinitions(),
            scope: $scope,
            rootId: $rootId,
            outerIds: null,
        );

        return self::mergeFixResults($sqlResult, $listenerResult);
    }

    private static function aggregateAnchorOrFail(?HasNestedSet $anchor): self
    {
        $scopeColumns = NestedSetScopeResolver::columns(static::class);

        if ($scopeColumns !== [] && ! $anchor instanceof HasNestedSet) {
            $message = sprintf(
                '%s declares a scope (%s); pass an anchor node to scope this operation.',
                static::class,
                implode(', ', $scopeColumns),
            );
            EventDispatcher::dispatch(new ScopeViolationDetected(
                modelClass: static::class,
                stage: 'repair',
                message: $message,
            ));
            throw new ScopeViolationException($message);
        }

        if ($anchor instanceof HasNestedSet && ! $anchor instanceof static) {
            throw new InvalidArgumentException(sprintf(
                '%s aggregate repair: $anchor must be an instance of %s, got %s. '
                .'A cross-class anchor would silently target a different table (or no rows at all).',
                static::class,
                static::class,
                $anchor::class,
            ));
        }

        return new static;
    }

    /**
     * Variant of {@see aggregateAnchorOrFail()} for mutating-repair
     * entry points (fixAggregates, fixAggregatesChunk). Adds an
     * unsaved-anchor rejection: a null PK silently widens the
     * operation to whole-table/whole-scope, which is almost never
     * what `fixAggregates($anchor)` callers intend. Read paths
     * (aggregateErrors) stay permissive — stub anchors are a
     * legitimate scope-carrier pattern there.
     */
    private static function aggregateWriteAnchorOrFail(?HasNestedSet $anchor): self
    {
        $instance = self::aggregateAnchorOrFail($anchor);

        if ($anchor instanceof Model && $anchor->getKey() === null) {
            throw new InvalidArgumentException(sprintf(
                '%s::fixAggregates: $anchor has no primary key — was it saved? '
                .'Pass a persisted anchor to scope the repair to its subtree, '
                .'or omit the anchor to repair the whole table.',
                static::class,
            ));
        }

        return $instance;
    }

    /**
     * Returns the soft-delete column name for models that use Eloquent's
     * SoftDeletes trait, or null otherwise. The aggregate-maintenance
     * delta path passes this to {@see DeltaMaintenance} so per-mutation
     * updates skip trashed ancestors — snapshot semantics: a trashed
     * ancestor's stored aggregate stays frozen at trash time, then gets
     * re-synced on restore.
     */
    private function softDeleteColumn(): ?string
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            return null;
        }

        // Reflection: SoftDeletes is in the hierarchy at runtime, but
        // PHPStan analyses each concrete model class in isolation and
        // can prove `getDeletedAtColumn` doesn't exist for non-soft-
        // delete fixtures. Reflection bypasses that static check
        // without an ignore.
        $column = (new \ReflectionMethod($this, 'getDeletedAtColumn'))->invoke($this);

        return is_string($column) ? $column : null;
    }

    private static function anchorRootId(?HasNestedSet $anchor): int|string|null
    {
        if (! $anchor instanceof Model) {
            return null;
        }

        $key = $anchor->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}
