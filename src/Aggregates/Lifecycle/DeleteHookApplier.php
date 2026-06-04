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
use Vusys\NestedSet\Aggregates\Numeric;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Repair\AggregateAnchor;
use Vusys\NestedSet\Aggregates\Strategy\DeltaMaintenance;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * `deleted` hook applier (hard and soft delete). Subtracts the
 * subtree's stored aggregate from each ancestor.
 *
 * For inclusive SUM/COUNT, `$node->{aggregate_column}` already holds
 * the inclusive subtree total — which is exactly what every ancestor
 * needs to lose. Self is excluded from the UPDATE: its row is gone
 * (hard delete) or being preserved with `deleted_at` set (soft delete).
 */
final class DeleteHookApplier
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
        $bounds = $node->getBounds();
        $softDeletedColumn = AggregateAnchor::softDeleteColumn($node);

        $preSnapshot = ChangeFeedRecorder::capture(
            node: $node,
            stage: 'on_delete',
            bounds: $bounds,
            includeSelf: false,
        );

        $deltas = [];
        $minMaxRecomputes = [];
        /** @var array<string, array{function: AggregateFunction, value: int}> $bitwise */
        $bitwise = [];
        /** @var array<string, AggregateDefinition> $chainRecomputes */
        $chainRecomputes = [];

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
                // Preserve numeric type — Sum companions of WeightedAvg /
                // GeometricMean / HarmonicMean hold decimal sums.
                $value = Numeric::asNumericOrZero($node->getAttribute($definition->column));
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
                // matches this node's stored extremum.
                $stored = Numeric::asNumericOrZero($node->getAttribute($definition->column));
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
                // contribution exactly.
                $stored = $node->getAttribute($definition->column);
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
                $chainRecomputes[$definition->column] = $definition;
            }
        }

        /** @var array<string, ListenerAggregateDefinition> $listenerChainDefs */
        $listenerChainDefs = [];

        foreach (AggregateRegistry::for($modelClass) as $definition) {
            if (! $definition instanceof ListenerAggregateDefinition) {
                continue;
            }

            if ($definition->lazy) {
                continue;
            }

            if (! $definition->isInclusive()) {
                $listenerChainDefs[$definition->column] = $definition;

                continue;
            }

            $op = $definition->operation;

            if ($op === AggregateFunction::Avg) {
                continue;
            }

            if ($op === AggregateFunction::Sum || $op === AggregateFunction::Count) {
                $value = Numeric::asNumericOrZero($node->getAttribute($definition->column));
                if ($value != 0) {
                    $deltas[$definition->column] = -$value;
                }

                continue;
            }

            // Remaining ops: Min / Max and the companion-derived display ops
            // (Variance / Stddev / GeoMean / HarmonicMean). All ride the
            // chain-recompute path.
            $listenerChainDefs[$definition->column] = $definition;
        }

        $scope = NestedSetScopeResolver::valuesFor($node);

        if ($deltas !== [] || $bitwise !== []) {
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
                bitwise: $bitwise,
                softDeletedColumn: $softDeletedColumn,
                variances: AggregateRegistry::varianceCompanionsFor($modelClass),
                weightedAvgs: AggregateRegistry::weightedAvgCompanionsFor($modelClass),
                bools: AggregateRegistry::boolCompanionsFor($modelClass),
                means: AggregateRegistry::meanCompanionsFor($modelClass),
            );
        }

        if ($minMaxRecomputes !== []) {
            LifecycleSupport::applyCapturedRecomputes($node, $minMaxRecomputes, $scope);
        }

        if ($chainRecomputes !== []) {
            // The deleted row is already gone from the table at this
            // point, so the subquery naturally excludes it.
            LifecycleSupport::applyChainRecompute(
                node: $node,
                bounds: $bounds,
                scope: $scope,
                definitions: $chainRecomputes,
            );
        }

        if ($listenerChainDefs !== []) {
            ListenerCalculator::chainRecompute(
                node: $node,
                bounds: $bounds,
                scope: $scope,
                definitions: $listenerChainDefs,
                includeSelf: false,
            );
        }

        $lazyColumns = LazyAggregateAccess::allLazyColumns($modelClass);
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

        LifecycleSupport::dispatchAggregatesRecomputed($node, 'on_delete');
    }
}
