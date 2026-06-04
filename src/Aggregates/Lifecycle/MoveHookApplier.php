<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Lifecycle;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\ChangeFeed\ChangeFeedRecorder;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicateKind;
use Vusys\NestedSet\Aggregates\Lazy\LazyAggregateAccess;
use Vusys\NestedSet\Aggregates\Listeners\ListenerCalculator;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Repair\AggregateAnchor;
use Vusys\NestedSet\Aggregates\Strategy\DeltaMaintenance;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Path A move-hook applier — two entry points wrapping the
 * before/after-move halves of the maintenance cycle.
 *
 * {@see applyBeforeMove()} runs while pre-move bounds are still
 * accurate so bounds-based WHEREs match the OLD ancestor chain. It
 * subtracts the moving subtree's contribution from that chain.
 *
 * {@see applyAfterMove()} runs after the structural SQL shifted
 * bounds. It adds the moving subtree's contribution to the NEW
 * ancestor chain.
 *
 * Both hooks share the {@see LifecycleSupport::collectMoveSubtreeContribution()}
 * walker so the same registry interpretation feeds both halves.
 *
 * The change-feed pre-snapshot taken in `applyBeforeMove()` is
 * dispatched at the end of the same hook, and a separate pre/post
 * pair runs around `applyAfterMove()` for the new chain.
 */
final class MoveHookApplier
{
    /**
     * Path A "before-move" hook: the OLD ancestor chain loses this
     * subtree's contribution.
     *
     * @param  Model&HasNestedSet  $node
     */
    public static function applyBeforeMove(Model $node, NodeBounds $from): void
    {
        if ($node::aggregateDeferredDepth() > 0) {
            return;
        }

        $modelClass = $node::class;
        $softDeletedColumn = AggregateAnchor::softDeleteColumn($node);

        $preSnapshot = ChangeFeedRecorder::capture(
            node: $node,
            stage: 'move',
            bounds: $from,
            includeSelf: false,
        );

        [$sumCountDeltas, $minMaxByFunction] = LifecycleSupport::collectMoveSubtreeContribution($node);

        // Don't early-return on empty inclusive deltas alone: a moving
        // leaf with tickets=0 contributes nothing to inclusive Sums,
        // but the chain recompute still needs to drop it from every
        // exclusive descendants_count / descendants_max on the old
        // ancestor chain.
        $scope = NestedSetScopeResolver::valuesFor($node);

        if ($sumCountDeltas !== []) {
            $negative = array_map(static fn (int|float $v): int|float => -$v, $sumCountDeltas);
            DeltaMaintenance::apply(
                connection: $node->getConnection(),
                table: $node->getTable(),
                lftCol: $node->getLftName(),
                rgtCol: $node->getRgtName(),
                bounds: $from,
                deltas: $negative,
                includeSelf: false,
                scope: $scope,
                avgs: AggregateRegistry::avgCompanionsFor($modelClass),
                softDeletedColumn: $softDeletedColumn,
                variances: AggregateRegistry::varianceCompanionsFor($modelClass),
                weightedAvgs: AggregateRegistry::weightedAvgCompanionsFor($modelClass),
                bools: AggregateRegistry::boolCompanionsFor($modelClass),
                means: AggregateRegistry::meanCompanionsFor($modelClass),
            );
        }

        if ($minMaxByFunction !== []) {
            // Exclude self's pre-move subtree from the inner MIN/MAX
            // scan. The move SQL hasn't run yet, so A1's rows are still
            // physically present; we have to logically exclude them so
            // the recompute reflects the post-move ancestor state.
            LifecycleSupport::applyMoveRecomputes($node, $from, $minMaxByFunction, $scope, excludeBounds: $from);
        }

        /** @var array<string, ListenerAggregateDefinition> $listenerChainSpecs */
        $listenerChainSpecs = [];
        foreach (AggregateRegistry::for($modelClass) as $def) {
            if (! $def instanceof ListenerAggregateDefinition) {
                continue;
            }
            if ($def->lazy) {
                continue;
            }
            // Exclusive listener defs (any function), inclusive Min/Max
            // listener defs (extremum may have been held by the moving
            // subtree), and inclusive companion-derived display ops all
            // need a chain recompute on the old chain.
            if (! $def->isInclusive()
                || $def->operation === AggregateFunction::Max
                || $def->operation === AggregateFunction::Min
                || $def->operation === AggregateFunction::Variance
                || $def->operation === AggregateFunction::Stddev
                || $def->operation === AggregateFunction::GeometricMean
                || $def->operation === AggregateFunction::HarmonicMean) {
                $listenerChainSpecs[$def->column] = $def;
            }
        }
        if ($listenerChainSpecs !== []) {
            ListenerCalculator::chainRecompute(
                node: $node,
                bounds: $from,
                scope: $scope,
                definitions: $listenerChainSpecs,
                includeSelf: false,
                excludeBounds: $from,
            );
        }

        /** @var array<string, AggregateDefinition> $chainRecomputes */
        $chainRecomputes = [];
        foreach (AggregateRegistry::for($modelClass) as $def) {
            if (! $def instanceof AggregateDefinition) {
                continue;
            }
            if ($def->lazy) {
                continue;
            }
            // Exclusive defs (any function/filter), inclusive defs with
            // a raw filter, and bitwise rollups all ride the
            // chain-recompute path on the old chain.
            $isRawFilter = $def->filter instanceof FilterPredicate
                && $def->filter->getKind() === FilterPredicateKind::Raw;
            if (! $def->inclusive || $isRawFilter || $def->function->requiresChainRecompute()) {
                $chainRecomputes[$def->column] = $def;
            }
        }
        if ($chainRecomputes !== []) {
            LifecycleSupport::applyChainRecompute(
                node: $node,
                bounds: $from,
                scope: $scope,
                definitions: $chainRecomputes,
                excludeBounds: $from,
            );
        }

        $lazyColumns = LazyAggregateAccess::allLazyColumns($modelClass);
        if ($lazyColumns !== []) {
            LazyAggregateAccess::invalidate(
                node: $node,
                columnNames: $lazyColumns,
                bounds: $from,
                scope: $scope,
                softDeletedColumn: $softDeletedColumn,
            );
        }

        ChangeFeedRecorder::dispatch($node, $preSnapshot);
    }

    /**
     * Path A "after-move" hook: the NEW ancestor chain gains this
     * subtree's contribution.
     *
     * @param  Model&HasNestedSet  $node
     */
    public static function applyAfterMove(Model $node, NodeBounds $from, NodeBounds $to, string $action): void
    {
        if ($node::aggregateDeferredDepth() > 0) {
            return;
        }

        $modelClass = $node::class;
        $softDeletedColumn = AggregateAnchor::softDeleteColumn($node);

        [$sumCountDeltas, , $candidateExtremes] = LifecycleSupport::collectMoveSubtreeContribution($node);

        /** @var array<string, AggregateDefinition> $chainRecomputes */
        $chainRecomputes = [];
        /** @var array<string, ListenerAggregateDefinition> $listenerChainSpecs */
        $listenerChainSpecs = [];

        foreach (AggregateRegistry::for($modelClass) as $def) {
            if ($def instanceof AggregateDefinition) {
                if ($def->lazy) {
                    continue;
                }
                $isRawFilter = $def->filter instanceof FilterPredicate
                    && $def->filter->getKind() === FilterPredicateKind::Raw;
                if (! $def->inclusive || $isRawFilter || $def->function->requiresChainRecompute()) {
                    $chainRecomputes[$def->column] = $def;
                }
            } elseif ($def instanceof ListenerAggregateDefinition) {
                if ($def->lazy) {
                    continue;
                }
                if (! $def->isInclusive()
                    || $def->operation === AggregateFunction::Max
                    || $def->operation === AggregateFunction::Min
                    || $def->operation === AggregateFunction::Variance
                    || $def->operation === AggregateFunction::Stddev
                    || $def->operation === AggregateFunction::GeometricMean
                    || $def->operation === AggregateFunction::HarmonicMean) {
                    $listenerChainSpecs[$def->column] = $def;
                }
            }
        }

        $lazyColumns = LazyAggregateAccess::allLazyColumns($modelClass);

        if ($sumCountDeltas === [] && $candidateExtremes === []
            && $chainRecomputes === [] && $listenerChainSpecs === [] && $lazyColumns === []) {
            return;
        }

        $preSnapshot = ChangeFeedRecorder::capture(
            node: $node,
            stage: 'move',
            bounds: $to,
            includeSelf: false,
        );

        $scope = NestedSetScopeResolver::valuesFor($node);

        if ($sumCountDeltas !== [] || $candidateExtremes !== []) {
            DeltaMaintenance::apply(
                connection: $node->getConnection(),
                table: $node->getTable(),
                lftCol: $node->getLftName(),
                rgtCol: $node->getRgtName(),
                bounds: $to,
                deltas: $sumCountDeltas,
                includeSelf: false,
                scope: $scope,
                avgs: AggregateRegistry::avgCompanionsFor($modelClass),
                extremes: $candidateExtremes,
                softDeletedColumn: $softDeletedColumn,
                variances: AggregateRegistry::varianceCompanionsFor($modelClass),
                weightedAvgs: AggregateRegistry::weightedAvgCompanionsFor($modelClass),
                bools: AggregateRegistry::boolCompanionsFor($modelClass),
                means: AggregateRegistry::meanCompanionsFor($modelClass),
            );
        }

        if ($chainRecomputes !== []) {
            LifecycleSupport::applyChainRecompute(
                node: $node,
                bounds: $to,
                scope: $scope,
                definitions: $chainRecomputes,
            );
        }

        if ($listenerChainSpecs !== []) {
            ListenerCalculator::chainRecompute(
                node: $node,
                bounds: $to,
                scope: $scope,
                definitions: $listenerChainSpecs,
            );
        }

        if ($lazyColumns !== []) {
            LazyAggregateAccess::invalidate(
                node: $node,
                columnNames: $lazyColumns,
                bounds: $to,
                scope: $scope,
                softDeletedColumn: $softDeletedColumn,
            );
        }

        ChangeFeedRecorder::dispatch($node, $preSnapshot);

        LifecycleSupport::dispatchAggregatesRecomputed($node, 'move');
    }
}
