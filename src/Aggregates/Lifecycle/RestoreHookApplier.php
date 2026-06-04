<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Lifecycle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\ChangeFeed\ChangeFeedRecorder;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicateKind;
use Vusys\NestedSet\Aggregates\Lazy\LazyAggregateAccess;
use Vusys\NestedSet\Aggregates\Listeners\ListenerCalculator;
use Vusys\NestedSet\Aggregates\Numeric;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Repair\AggregateAnchor;
use Vusys\NestedSet\Aggregates\Strategy\DeltaMaintenance;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * `restored` hook applier. Two flavours:
 *
 *  - Soft-delete models: the subtree's stored aggregates were left
 *    intact during the cascade soft-delete, but the ancestor chain's
 *    aggregates were decremented by the delete. Restore re-syncs via
 *    (1) `fixAggregates(self)` over the now-live subtree, then (2)
 *    a chain recompute on ancestors for every declared aggregate.
 *  - Non-soft-delete models: re-add the subtree's contribution via the
 *    delta path (mirror of {@see DeleteHookApplier}, but additive).
 */
final class RestoreHookApplier
{
    /**
     * @param  Model&HasNestedSet  $node
     */
    public static function apply(Model $node): void
    {
        if ($node::aggregateDeferredDepth() > 0) {
            return;
        }

        if (! $node->isPlacedInTree()) {
            return;
        }

        $modelClass = $node::class;
        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);

        if ($usesSoftDeletes) {
            self::applySoftDeleteRestore($node);

            return;
        }

        self::applyHardRestore($node);
    }

    /**
     * @param  Model&HasNestedSet  $node
     */
    private static function applySoftDeleteRestore(Model $node): void
    {
        $modelClass = $node::class;
        $bounds = $node->getBounds();
        $softDeletedColumn = AggregateAnchor::softDeleteColumn($node);

        // Soft-delete restore rewrites self's subtree via fixAggregates(self)
        // before the ancestor chain recompute; include the subtree in the
        // snapshot so per-row events fire for restored descendants too.
        $preSnapshot = ChangeFeedRecorder::capture(
            node: $node,
            stage: 'on_restore',
            bounds: $bounds,
            includeSelf: true,
            includeSubtree: true,
        );

        // Step 1: recompute self's subtree from the current live set.
        $modelClass::fixAggregates($node);
        $node->refresh();

        // Step 2: chain-recompute every aggregate on the ancestor chain.
        $scope = NestedSetScopeResolver::valuesFor($node);

        /** @var array<string, AggregateDefinition> $sqlDefs */
        $sqlDefs = [];
        /** @var array<string, ListenerAggregateDefinition> $listenerDefs */
        $listenerDefs = [];
        foreach (AggregateRegistry::for($modelClass) as $def) {
            // Lazy aggregates are populated on self by the fixAggregates()
            // call above and invalidated on the ancestor chain after the
            // chain recomputes; skip them here so they're not double-walked
            // over the chain.
            if ($def->isLazy()) {
                continue;
            }
            if ($def instanceof AggregateDefinition) {
                $sqlDefs[$def->column] = $def;
            } elseif ($def instanceof ListenerAggregateDefinition) {
                $listenerDefs[$def->column] = $def;
            }
        }

        if ($sqlDefs !== []) {
            LifecycleSupport::applyChainRecompute($node, $bounds, $scope, $sqlDefs);
        }
        if ($listenerDefs !== []) {
            ListenerCalculator::chainRecompute(
                node: $node,
                bounds: $bounds,
                scope: $scope,
                definitions: $listenerDefs,
                includeSelf: false,
            );
        }

        $lazyColumns = LazyAggregateAccess::allLazyColumns($modelClass);
        if ($lazyColumns !== []) {
            // Invalidate proper ancestors only — fixAggregates() populated
            // self's lazy columns and stamp.
            LazyAggregateAccess::invalidate(
                node: $node,
                columnNames: $lazyColumns,
                bounds: $bounds,
                scope: $scope,
                softDeletedColumn: $softDeletedColumn,
                excludeSelf: true,
            );
        }

        ChangeFeedRecorder::dispatch($node, $preSnapshot);

        LifecycleSupport::dispatchAggregatesRecomputed($node, 'on_restore');
    }

    /**
     * @param  Model&HasNestedSet  $node
     */
    private static function applyHardRestore(Model $node): void
    {
        $modelClass = $node::class;
        $bounds = $node->getBounds();
        $softDeletedColumn = AggregateAnchor::softDeleteColumn($node);

        $deltas = [];
        $extremes = [];
        /** @var array<string, AggregateDefinition> $chainRecomputes */
        $chainRecomputes = [];

        /** @var array<string, ListenerAggregateDefinition> $listenerChainSpecs */
        $listenerChainSpecs = [];

        foreach (AggregateRegistry::for($modelClass) as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }

            if ($definition->lazy) {
                continue;
            }

            if (! $definition->inclusive) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            if ($definition->filter instanceof FilterPredicate
                && $definition->filter->getKind() === FilterPredicateKind::Raw) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            if ($definition->function->requiresChainRecompute()) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            if ($definition->function === AggregateFunction::Sum
                || $definition->function === AggregateFunction::Count) {
                $value = Numeric::asNumericOrZero($node->getAttribute($definition->column));
                if ($value != 0) {
                    $deltas[$definition->column] = $value;
                }

                continue;
            }

            if (($definition->function === AggregateFunction::Max
                || $definition->function === AggregateFunction::Min)
                && $definition->source !== null
            ) {
                // Stored NULL means the restored subtree's MIN/MAX has
                // no matching rows. Pushing a 0 candidate here would
                // lower an ancestor's stored MAX or clobber its NULL
                // extremum — mirror the SQL MIN/MAX-ignore-NULL rule.
                $raw = $node->getAttribute($definition->column);
                if ($raw === null) {
                    continue;
                }
                $value = Numeric::asNumericOrZero($raw);
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

        foreach (AggregateRegistry::for($modelClass) as $definition) {
            if (! $definition instanceof ListenerAggregateDefinition) {
                continue;
            }

            if ($definition->lazy) {
                continue;
            }

            if (! $definition->isInclusive()) {
                $listenerChainSpecs[$definition->column] = $definition;

                continue;
            }

            $op = $definition->operation;

            if ($op === AggregateFunction::Avg) {
                continue;
            }

            if ($op === AggregateFunction::Sum || $op === AggregateFunction::Count) {
                $value = Numeric::asNumericOrZero($node->getAttribute($definition->column));
                if ($value != 0) {
                    $deltas[$definition->column] = $value;
                }

                continue;
            }

            if (in_array($op, [
                AggregateFunction::Variance,
                AggregateFunction::Stddev,
                AggregateFunction::GeometricMean,
                AggregateFunction::HarmonicMean,
            ], true)) {
                $listenerChainSpecs[$definition->column] = $definition;

                continue;
            }

            // Min / Max — only remaining ops. Stored NULL means the
            // restored subtree has no matching listener contributions.
            $rawStored = $node->getAttribute($definition->column);
            if ($rawStored === null) {
                continue;
            }
            $value = Numeric::asNumericOrZero($rawStored);
            $extremes[$definition->column] = ['function' => $op, 'value' => $value];
        }

        $lazyColumns = LazyAggregateAccess::allLazyColumns($modelClass);

        if ($deltas === [] && $extremes === [] && $chainRecomputes === [] && $listenerChainSpecs === [] && $lazyColumns === []) {
            return;
        }

        $preSnapshot = ChangeFeedRecorder::capture(
            node: $node,
            stage: 'on_restore',
            bounds: $bounds,
            includeSelf: false,
        );

        $scope = NestedSetScopeResolver::valuesFor($node);

        if ($deltas !== [] || $extremes !== []) {
            DeltaMaintenance::apply(
                connection: $node->getConnection(),
                table: $node->getTable(),
                lftCol: $node->getLftName(),
                rgtCol: $node->getRgtName(),
                bounds: $bounds,
                deltas: $deltas,
                includeSelf: false,
                scope: $scope,
                avgs: AggregateRegistry::avgCompanionsFor($modelClass),
                extremes: $extremes,
                softDeletedColumn: $softDeletedColumn,
                variances: AggregateRegistry::varianceCompanionsFor($modelClass),
                weightedAvgs: AggregateRegistry::weightedAvgCompanionsFor($modelClass),
                bools: AggregateRegistry::boolCompanionsFor($modelClass),
                means: AggregateRegistry::meanCompanionsFor($modelClass),
            );
        }

        if ($chainRecomputes !== []) {
            LifecycleSupport::applyChainRecompute($node, $bounds, $scope, $chainRecomputes);
        }

        if ($listenerChainSpecs !== []) {
            ListenerCalculator::chainRecompute(
                node: $node,
                bounds: $bounds,
                scope: $scope,
                definitions: $listenerChainSpecs,
            );
        }

        if ($lazyColumns !== []) {
            LazyAggregateAccess::invalidate(
                node: $node,
                columnNames: $lazyColumns,
                bounds: $bounds,
                scope: $scope,
                softDeletedColumn: $softDeletedColumn,
            );
        }

        ChangeFeedRecorder::dispatch($node, $preSnapshot);

        LifecycleSupport::dispatchAggregatesRecomputed($node, 'on_restore');
    }
}
