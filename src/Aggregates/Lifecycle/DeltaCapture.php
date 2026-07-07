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
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
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
     * @param  Model&MaintainsTreeAggregates  $node
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

            // Evaluate the filter predicate against CAST attribute values
            // on BOTH sides. getAttributes() returns RAW (uncast) values
            // while getOriginal() casts, so a boolean cast over a TINYINT
            // column made the new side see `1` and the old side see
            // `true` — disagreeing under evaluateFor's strict comparison
            // and capturing a phantom enter/leave-filter delta (permanent
            // drift) while the SQL side kept the row in-filter. Reading
            // each watch column through the model's cast keeps both sides
            // consistent with each other and with the filter value's
            // declared type.
            $newPred = true;
            $oldPred = true;
            if ($definition->filter instanceof FilterPredicate) {
                $castNew = [];
                $castOld = [];
                foreach ($definition->filter->watchColumns() as $filterCol) {
                    $castNew[$filterCol] = $node->getAttribute($filterCol);
                    $castOld[$filterCol] = $node->getOriginal($filterCol);
                }
                $newPred = $definition->filter->evaluateFor($castNew) ?? true;
                $oldPred = $definition->filter->evaluateFor($castOld) ?? true;
            }

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

            if (($definition->function === AggregateFunction::Max
                || $definition->function === AggregateFunction::Min)
                && $source !== null
            ) {
                // A row contributes a MIN/MAX candidate only when it passes
                // the filter AND its source is non-NULL — SQL MIN/MAX ignore
                // NULL. Coercing NULL to 0 here (the pre-#178 footgun) routes
                // null↔value transitions to the wrong branch: a freshly-set
                // value is missed and a dropped holder leaves stale data.
                $rawNew = $node->getAttribute($source);
                $rawOld = $node->getOriginal($source);
                $newHas = $newPred && $rawNew !== null;
                $oldHas = $oldPred && $rawOld !== null;

                $extreme = static fn (int|float $value): array => [
                    'function' => $definition->function,
                    'value' => $value,
                ];
                $recompute = static fn (int|float $filterValue): array => [
                    'function' => $definition->function,
                    'source' => $source,
                    'filterValue' => $filterValue,
                    'filter' => $definition->filter,
                ];

                if ($newHas && ! $oldHas) {
                    $state->extremes[$definition->column] = $extreme(Numeric::asNumericOrZero($rawNew));
                } elseif (! $newHas && $oldHas) {
                    $state->recomputes[$definition->column] = $recompute(Numeric::asNumericOrZero($rawOld));
                } elseif ($newHas && $oldHas) {
                    $delta = Numeric::asNumericOrZero($rawNew) - Numeric::asNumericOrZero($rawOld);
                    $extends = $definition->function === AggregateFunction::Max ? $delta > 0 : $delta < 0;
                    $loses = $definition->function === AggregateFunction::Max ? $delta < 0 : $delta > 0;
                    if ($extends) {
                        $state->extremes[$definition->column] = $extreme(Numeric::asNumericOrZero($rawNew));
                    } elseif ($loses) {
                        $state->recomputes[$definition->column] = $recompute(Numeric::asNumericOrZero($rawOld));
                    }
                }

                continue;
            }

            // BitOr / BitAnd / BitXor all route through the chain-recompute
            // branch above (requiresChainRecompute() is true for every
            // bitwise kind), so no per-bit delta is captured here.
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

            // getRawOriginal(), not getOriginal(): setRawAttributes()
            // expects RAW (un-cast) attribute values. getOriginal()
            // returns CAST values, which round-trip back through the
            // cast wrong for array/json/encrypted columns (an array cast
            // would re-encode an already-decoded array, etc.). The
            // listener then reads a corrupted old snapshot.
            $oldSnapshot = new $modelClass;
            $oldSnapshot->setRawAttributes($node->getRawOriginal(), true);
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

            // A null contribution means the row does not participate
            // (listener returned null, or the filter rejected the row),
            // mirroring a NULL SQL source. contributionOrZero collapses
            // both old and new to 0, so the value↔null transitions must
            // be decided on the raw contribution, not the coerced value —
            // otherwise a dropped holder pushes a fabricated 0 candidate
            // and a freshly-appearing candidate is missed.
            $newHas = $newContrib !== null;
            $oldHas = $oldContrib !== null;

            if ($op === AggregateFunction::Max) {
                if ($newHas && ! $oldHas) {
                    $state->extremes[$definition->column] = ['function' => AggregateFunction::Max, 'value' => $newVal];
                } elseif (! $newHas && $oldHas) {
                    $state->listenerRecomputes[$definition->column] = $definition;
                } elseif ($newHas && $oldHas) {
                    if ($newVal > $oldVal) {
                        $state->extremes[$definition->column] = ['function' => AggregateFunction::Max, 'value' => $newVal];
                    } elseif ($newVal < $oldVal) {
                        $state->listenerRecomputes[$definition->column] = $definition;
                    }
                }

                continue;
            }

            // Min: only remaining op after Sum/Count/Avg/Max continues above.
            if ($newHas && ! $oldHas) {
                $state->extremes[$definition->column] = ['function' => AggregateFunction::Min, 'value' => $newVal];
            } elseif (! $newHas && $oldHas) {
                $state->listenerRecomputes[$definition->column] = $definition;
            } elseif ($newHas && $oldHas) {
                if ($newVal < $oldVal) {
                    $state->extremes[$definition->column] = ['function' => AggregateFunction::Min, 'value' => $newVal];
                } elseif ($newVal > $oldVal) {
                    $state->listenerRecomputes[$definition->column] = $definition;
                }
            }
        }
    }

    /**
     * `saved` hook: issue the delta UPDATE captured in
     * {@see capture()}. Touches self + ancestors so the node's own
     * stored aggregate stays in sync alongside the rollup.
     *
     * @param  Model&MaintainsTreeAggregates  $node
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
        $lazyInvalidations = $state->lazyInvalidations;

        $state->clearCapture();

        if ($deltas === [] && $extremes === [] && $recomputes === [] && $listenerRecomputes === [] && $chainRecomputes === [] && $lazyInvalidations === []) {
            return;
        }

        if (! $node->isPlacedInTree()) {
            // Defensive: existing-model updates on unplaced rows
            // (lft/rgt = 0) shouldn't propagate to every other row.
            return;
        }

        $modelClass = $node::class;
        // Re-read the current lft/rgt/depth before banding the delta onto
        // the ancestor chain. A source-column save touches only the dirty
        // source column, so the DB row keeps its true bounds — but the
        // in-memory instance may be stale (an earlier move/append shifted
        // this row after it was loaded). Using the stale bounds would apply
        // the delta to the wrong ancestor set, permanently drifting the
        // aggregates. Mirrors the same FOR UPDATE re-read the `deleting`
        // hook performs before subtracting a node's stored subtotal.
        self::refreshBoundsFromDatabase($node);
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
     * Re-reads lft/rgt/depth from the DB into $node so the delta UPDATE
     * bands on the row's current bounds, not a stale in-memory snapshot.
     * No-op if the row can't be located. Locks the row for the rest of
     * the transaction (non-SQLite, when one is open) so a concurrent
     * gap-shift can't slide the bounds between this re-read and the
     * delta UPDATE that follows — matching the discipline used elsewhere
     * on every stale-bounds re-read.
     *
     * @param  Model&MaintainsTreeAggregates  $node
     */
    private static function refreshBoundsFromDatabase(Model $node): void
    {
        $key = $node->getKey();
        if ($key === null) {
            return;
        }

        $columns = [$node->getLftName(), $node->getRgtName(), $node->getDepthName()];

        $connection = $node->getConnection();
        $query = $connection->table($node->getTable())
            ->where($node->getKeyName(), $key);

        if ($connection->getDriverName() !== 'sqlite' && $connection->transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $row = $query->first($columns);
        if ($row === null) {
            return;
        }

        foreach ($columns as $column) {
            $node->setAttribute($column, $row->{$column});
            $node->syncOriginalAttribute($column);
        }
    }

    /**
     * Validates that any GeometricMean or HarmonicMean aggregate
     * source value satisfies its positivity / non-zero constraint.
     *
     * @param  Model&MaintainsTreeAggregates  $node
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
