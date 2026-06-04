<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Lifecycle;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\ChangeFeed\ChangeFeedRecorder;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceTransform;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicateKind;
use Vusys\NestedSet\Aggregates\Lazy\LazyAggregateAccess;
use Vusys\NestedSet\Aggregates\Listeners\ListenerCalculator;
use Vusys\NestedSet\Aggregates\Listeners\ListenerMaintenance;
use Vusys\NestedSet\Aggregates\Numeric;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Repair\AggregateAnchor;
use Vusys\NestedSet\Aggregates\Sql\AggregateSqlEmitter;
use Vusys\NestedSet\Aggregates\Strategy\DeltaMaintenance;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateSourceConstraintViolationException;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * The `saving` → `saved` delta-capture pipeline for source-column
 * updates on existing rows. Two entry points:
 *
 *  - {@see capture()} — runs in the `saving` hook. For each SUM/COUNT
 *    aggregate whose source column is dirty, compute the signed delta
 *    (or routing decision) and stash it on the model's
 *    {@see CapturedMutation} state object.
 *  - {@see apply()} — runs in the `saved` hook. Drains the captured
 *    state and issues one delta UPDATE per ancestor chain plus any
 *    recomputes / chain recomputes / listener recomputes / lazy
 *    invalidations the capture phase queued.
 *
 * Inserts go through {@see CreateHookApplier} instead — at `saving`
 * time a new model has no `getOriginal()` baseline, so dirty tracking
 * cannot distinguish "freshly set" from "changed".
 */
final class DeltaCapture
{
    /**
     * `saving` hook (existing models only). For each SUM/COUNT
     * aggregate over a dirty source column, capture the signed delta.
     * MIN/MAX captures route to either a cheap-delta extension or a
     * lost-holder recompute depending on direction.
     *
     * @param  Model&HasNestedSet  $node
     */
    public static function capture(Model $node, CapturedMutation $state): void
    {
        $modelClass = $node::class;

        // Constraint check runs for both inserts (exists=false) and
        // updates (exists=true) so it fires before the row is persisted.
        // Validate even when maintenance is deferred — otherwise
        // withDeferredAggregateMaintenance() would let geom/harm-mean
        // source-domain violations land in the database silently.
        self::validateSourceConstraints($node);

        if ($modelClass::aggregateDeferredDepth() > 0) {
            return;
        }

        $state->clearCapture();

        if (! $node->exists) {
            return;
        }

        foreach (AggregateRegistry::for($modelClass) as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }

            // Lazy aggregates short-circuit every eager path below —
            // their stored column and `<column>_computed_at` stamp are
            // nulled on every affected ancestor and recomputed on the
            // next read. The dirty check here over-approximates safely:
            // missing a dirty signal would leak stale data, but
            // invalidating an already-NULL column is a no-op.
            if ($definition->lazy) {
                $watchCols = LazyAggregateAccess::watchColumnsForSql($definition);
                if ($watchCols !== [] && $node->isDirty($watchCols)) {
                    $state->lazyInvalidations[] = $definition->column;
                }

                continue;
            }

            // Exclusive aggregates: queue a chain recompute when a
            // watched column is dirty. Delta arithmetic would need
            // per-function subtree-contribution composition; recompute
            // is uniform across functions.
            if (! $definition->inclusive) {
                $watchCols = $definition->triggerColumns();
                if ($watchCols !== [] && $node->isDirty($watchCols)) {
                    $state->chainRecomputes[$definition->column] = $definition;
                }

                continue;
            }

            // Raw predicates can't be evaluated in PHP, so we can't
            // produce a signed delta. Queue an ancestor-chain recompute
            // instead.
            if ($definition->filter?->getKind() === FilterPredicateKind::Raw) {
                $watchCols = $definition->triggerColumns();
                if ($watchCols !== [] && $node->isDirty($watchCols)) {
                    $state->chainRecomputes[$definition->column] = $definition;
                }

                continue;
            }

            // Collection aggregate kinds: any change to a contributing column
            // triggers a full subtree recompute up the ancestor chain.
            if ($definition->function->requiresChainRecompute()) {
                $watchCols = array_unique(array_merge(
                    AggregateSqlEmitter::watchColumns($definition),
                    $definition->filter?->watchColumns() ?? [],
                ));
                if ($watchCols !== [] && $node->isDirty($watchCols)) {
                    $state->chainRecomputes[$definition->column] = $definition;
                }

                continue;
            }

            $triggerCols = $definition->triggerColumns();
            if ($triggerCols === []) {
                continue;
            }
            if (! $node->isDirty($triggerCols)) {
                continue;
            }

            // Evaluate filter against new and old attribute sets.
            $newPred = $definition->filter instanceof FilterPredicate
                ? ($definition->filter->evaluateFor($node->getAttributes()) ?? true)
                : true;
            $oldPred = $definition->filter instanceof FilterPredicate
                ? ($definition->filter->evaluateFor($node->getOriginal()) ?? true)
                : true;

            $source = $definition->source;

            if ($definition->function === AggregateFunction::Sum && $source !== null) {
                $newSource = $definition->sourceTransform->applyPhp(
                    $node->getAttribute($source),
                    $definition->weight !== null
                        ? Numeric::asNumericOrNull($node->getAttribute($definition->weight))
                        : null,
                );
                $oldSource = $definition->sourceTransform->applyPhp(
                    $node->getOriginal($source),
                    $definition->weight !== null
                        ? Numeric::asNumericOrNull($node->getOriginal($definition->weight))
                        : null,
                );
                $delta = ($newPred ? $newSource : 0) - ($oldPred ? $oldSource : 0);

                if ($delta !== 0) {
                    $state->deltas[$definition->column] = $delta;
                }

                continue;
            }

            if ($definition->function === AggregateFunction::Count) {
                if ($source === null) {
                    $delta = ($newPred ? 1 : 0) - ($oldPred ? 1 : 0);
                } elseif ($definition->sourceTransform === CompanionSourceTransform::Identity) {
                    $newContrib = ($newPred && ($node->getAttribute($source) !== null)) ? 1 : 0;
                    $oldContrib = ($oldPred && ($node->getOriginal($source) !== null)) ? 1 : 0;
                    $delta = $newContrib - $oldContrib;
                } else {
                    // Non-Identity transform on a Count companion (today:
                    // Ln for GeometricMean, Recip for HarmonicMean) — count
                    // only rows whose transformed value would land in the
                    // sibling Sum companion. applyPhp returns 0 for rows
                    // the transform skips (LN of ≤ 0, 1/0), so equating
                    // against zero is the right "did this row contribute"
                    // test.
                    $newTransformed = $definition->sourceTransform->applyPhp($node->getAttribute($source));
                    $oldTransformed = $definition->sourceTransform->applyPhp($node->getOriginal($source));
                    $newContrib = ($newPred && $newTransformed != 0) ? 1 : 0;
                    $oldContrib = ($oldPred && $oldTransformed != 0) ? 1 : 0;
                    $delta = $newContrib - $oldContrib;
                }

                if ($delta !== 0) {
                    $state->deltas[$definition->column] = $delta;
                }

                continue;
            }

            if ($definition->function === AggregateFunction::Max && $source !== null) {
                $newSource = Numeric::asNumericOrZero($node->getAttribute($source));
                $oldSource = Numeric::asNumericOrZero($node->getOriginal($source));

                if ($newPred && ! $oldPred) {
                    $state->extremes[$definition->column] = [
                        'function' => AggregateFunction::Max,
                        'value' => $newSource,
                    ];
                } elseif (! $newPred && $oldPred) {
                    $state->recomputes[$definition->column] = [
                        'function' => AggregateFunction::Max,
                        'source' => $source,
                        'filterValue' => $oldSource,
                        'filter' => $definition->filter,
                    ];
                } elseif ($newPred && $oldPred) {
                    $delta = $newSource - $oldSource;
                    if ($delta > 0) {
                        $state->extremes[$definition->column] = [
                            'function' => AggregateFunction::Max,
                            'value' => $newSource,
                        ];
                    } elseif ($delta < 0) {
                        $state->recomputes[$definition->column] = [
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
                $newSource = Numeric::asNumericOrZero($node->getAttribute($source));
                $oldSource = Numeric::asNumericOrZero($node->getOriginal($source));

                if ($newPred && ! $oldPred) {
                    $state->extremes[$definition->column] = [
                        'function' => AggregateFunction::Min,
                        'value' => $newSource,
                    ];
                } elseif (! $newPred && $oldPred) {
                    $state->recomputes[$definition->column] = [
                        'function' => AggregateFunction::Min,
                        'source' => $source,
                        'filterValue' => $oldSource,
                        'filter' => $definition->filter,
                    ];
                } elseif ($newPred && $oldPred) {
                    $delta = $newSource - $oldSource;
                    if ($delta < 0) {
                        $state->extremes[$definition->column] = [
                            'function' => AggregateFunction::Min,
                            'value' => $newSource,
                        ];
                    } elseif ($delta > 0) {
                        $state->recomputes[$definition->column] = [
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
                $newSource = Numeric::asIntOrZero($node->getAttribute($source));
                $oldSource = Numeric::asIntOrZero($node->getOriginal($source));
                $newContrib = $newPred ? $newSource : 0;
                $oldContrib = $oldPred ? $oldSource : 0;
                $xorDelta = $oldContrib ^ $newContrib;

                if ($xorDelta !== 0) {
                    $state->bitwise[$definition->column] = [
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
                $state->chainRecomputes[$definition->column] = $definition;

                continue;
            }
        }

        foreach (AggregateRegistry::for($modelClass) as $definition) {
            if (! $definition instanceof ListenerAggregateDefinition) {
                continue;
            }

            $listener = $definition->makeListener();
            $watchCols = array_values(array_unique(array_merge(
                $listener->watchColumns(),
                $definition->filter?->watchColumns() ?? [],
            )));
            if ($watchCols === []) {
                continue;
            }
            if (! $node->isDirty($watchCols)) {
                continue;
            }

            // Lazy listener aggregate: route through the invalidation
            // path same as SQL lazy.
            if ($definition->lazy) {
                $state->lazyInvalidations[] = $definition->column;

                continue;
            }

            // Exclusive listener: route to chain recompute.
            if (! $definition->isInclusive()) {
                $state->listenerRecomputes[$definition->column] = $definition;

                continue;
            }

            $op = $definition->operation;

            // Companion-derived display columns: Avg is composed inline
            // by DeltaMaintenance from its Sum + Count companions; the
            // other companion-derived ops (Variance / Stddev / GeoMean /
            // HarmonicMean) need a chain pass over the subtree because
            // their display formula isn't expressible as a function of
            // the per-row delta alone.
            if ($op === AggregateFunction::Avg) {
                continue;
            }
            if (in_array($op, [
                AggregateFunction::Variance,
                AggregateFunction::Stddev,
                AggregateFunction::GeometricMean,
                AggregateFunction::HarmonicMean,
            ], true)) {
                $state->listenerRecomputes[$definition->column] = $definition;

                continue;
            }

            $oldSnapshot = new $modelClass;
            $oldSnapshot->setRawAttributes($node->getOriginal(), true);
            $oldContrib = ListenerMaintenance::resolveContribution(
                $definition,
                $listener->contribution($oldSnapshot),
                $oldSnapshot->getAttributes(),
            );
            $oldVal = Numeric::contributionOrZero($oldContrib);

            $newContrib = ListenerMaintenance::resolveContribution(
                $definition,
                $listener->contribution($node),
                $node->getAttributes(),
            );
            $newVal = Numeric::contributionOrZero($newContrib);

            if ($op === AggregateFunction::Sum) {
                $delta = $newVal - $oldVal;
                if ($delta != 0) {
                    $state->deltas[$definition->column] = $delta;
                }

                continue;
            }

            if ($op === AggregateFunction::Count) {
                $oldCounted = $oldContrib !== null ? 1 : 0;
                $newCounted = $newContrib !== null ? 1 : 0;
                $delta = $newCounted - $oldCounted;
                if ($delta !== 0) {
                    $state->deltas[$definition->column] = $delta;
                }

                continue;
            }

            if ($op === AggregateFunction::Max) {
                if ($newVal > $oldVal) {
                    $state->extremes[$definition->column] = ['function' => AggregateFunction::Max, 'value' => $newVal];
                } elseif ($newVal < $oldVal) {
                    $state->listenerRecomputes[$definition->column] = $definition;
                }

                continue;
            }

            // Min: only remaining op after Sum/Count/Avg/Max continues above.
            if ($newVal < $oldVal) {
                $state->extremes[$definition->column] = ['function' => AggregateFunction::Min, 'value' => $newVal];
            } elseif ($newVal > $oldVal) {
                $state->listenerRecomputes[$definition->column] = $definition;
            }
        }
    }

    /**
     * `saved` hook: issue the delta UPDATE captured in
     * {@see capture()}. Touches self + ancestors so the node's own
     * stored aggregate stays in sync alongside the rollup.
     *
     * @param  Model&HasNestedSet  $node
     */
    public static function apply(Model $node, CapturedMutation $state): void
    {
        if ($node::aggregateDeferredDepth() > 0) {
            return;
        }

        $deltas = $state->deltas;
        $extremes = $state->extremes;
        $recomputes = $state->recomputes;
        $listenerRecomputes = $state->listenerRecomputes;
        $chainRecomputes = $state->chainRecomputes;
        $bitwise = $state->bitwise;
        $lazyInvalidations = $state->lazyInvalidations;

        $state->clearCapture();

        if ($deltas === [] && $extremes === [] && $recomputes === [] && $listenerRecomputes === [] && $chainRecomputes === [] && $bitwise === [] && $lazyInvalidations === []) {
            return;
        }

        if (! $node->isPlacedInTree()) {
            // Defensive: existing-model updates on unplaced rows
            // (lft/rgt = 0) shouldn't propagate to every other row.
            return;
        }

        $modelClass = $node::class;
        $bounds = $node->getBounds();
        $softDeletedColumn = AggregateAnchor::softDeleteColumn($node);

        $state->changeFeedPreSnapshot = ChangeFeedRecorder::capture(
            node: $node,
            stage: 'on_update',
            bounds: $bounds,
            includeSelf: true,
        );

        $scope = NestedSetScopeResolver::valuesFor($node);

        DeltaMaintenance::apply(
            connection: $node->getConnection(),
            table: $node->getTable(),
            lftCol: $node->getLftName(),
            rgtCol: $node->getRgtName(),
            bounds: $bounds,
            deltas: $deltas,
            includeSelf: true,
            scope: $scope,
            avgs: AggregateRegistry::avgCompanionsFor($modelClass),
            extremes: $extremes,
            bitwise: $bitwise,
            softDeletedColumn: $softDeletedColumn,
            variances: AggregateRegistry::varianceCompanionsFor($modelClass),
            weightedAvgs: AggregateRegistry::weightedAvgCompanionsFor($modelClass),
            bools: AggregateRegistry::boolCompanionsFor($modelClass),
            means: AggregateRegistry::meanCompanionsFor($modelClass),
        );

        if ($recomputes !== []) {
            LifecycleSupport::applyCapturedRecomputes($node, $recomputes, $scope);
        }

        if ($chainRecomputes !== []) {
            LifecycleSupport::applyChainRecompute($node, $bounds, $scope, $chainRecomputes);
        }

        if ($listenerRecomputes !== []) {
            ListenerCalculator::chainRecompute(
                node: $node,
                bounds: $bounds,
                scope: $scope,
                definitions: $listenerRecomputes,
                includeSelf: true,
            );
        }

        if ($lazyInvalidations !== []) {
            LazyAggregateAccess::invalidate(
                node: $node,
                columnNames: $lazyInvalidations,
                bounds: $bounds,
                scope: $scope,
                softDeletedColumn: $softDeletedColumn,
            );
        }

        ChangeFeedRecorder::dispatch($node, $state->changeFeedPreSnapshot);
        $state->changeFeedPreSnapshot = null;
    }

    /**
     * Validates that any GeometricMean or HarmonicMean aggregate
     * source value satisfies its positivity / non-zero constraint.
     *
     * @param  Model&HasNestedSet  $node
     *
     * @throws AggregateSourceConstraintViolationException
     */
    private static function validateSourceConstraints(Model $node): void
    {
        $modelClass = $node::class;

        foreach (AggregateRegistry::for($modelClass) as $definition) {
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

            if ($node->exists && ! $node->isDirty($source)) {
                continue;
            }

            $value = $node->getAttribute($source);
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
                    $modelClass,
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
                    $modelClass,
                    $source,
                    $source,
                ));
            }
        }
    }
}
