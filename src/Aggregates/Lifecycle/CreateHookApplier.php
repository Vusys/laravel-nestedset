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
use Vusys\NestedSet\Aggregates\Listeners\ListenerMaintenance;
use Vusys\NestedSet\Aggregates\Numeric;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Repair\AggregateAnchor;
use Vusys\NestedSet\Aggregates\Strategy\DeltaMaintenance;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * `created` hook applier. A newly-inserted node has just been placed
 * in the tree (or it has not been — see
 * {@see HasNestedSet::isPlacedInTree()} for the guard). For
 * each inclusive SUM/COUNT/MIN/MAX/BitOr/BitXor declaration, push the
 * node's contribution to its ancestor chain and to its own row in one
 * UPDATE.
 */
final class CreateHookApplier
{
    /**
     * @param  Model&MaintainsTreeAggregates  $node
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

        $deltas = [];
        $extremes = [];
        /** @var array<string, AggregateDefinition> $chainRecomputes */
        $chainRecomputes = [];

        foreach (AggregateRegistry::for($modelClass) as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }

            // Lazy aggregates skip the eager create path entirely; the
            // ancestor-chain invalidation at the end of this method
            // takes care of them.
            if ($definition->lazy) {
                continue;
            }

            // Exclusive aggregates: route through the chain-recompute
            // path. The delta math differs per-function and per-filter.
            if (! $definition->inclusive) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            // Raw filter: predicate can't be evaluated in PHP.
            if ($definition->filter instanceof FilterPredicate
                && $definition->filter->getKind() === FilterPredicateKind::Raw) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            if ($definition->function->requiresChainRecompute()) {
                $chainRecomputes[$definition->column] = $definition;

                continue;
            }

            if ($definition->function === AggregateFunction::Sum && $definition->source !== null) {
                if (self::filterExcludes($node, $definition->filter)) {
                    continue;
                }
                $value = $definition->sourceTransform->applyPhp(
                    $node->getAttribute($definition->source),
                    $definition->weight !== null
                        ? Numeric::asNumericOrNull($node->getAttribute($definition->weight))
                        : null,
                );
                if ($value != 0) {
                    $deltas[$definition->column] = $value;
                }

                continue;
            }

            if ($definition->function === AggregateFunction::Count) {
                if (self::filterExcludes($node, $definition->filter)) {
                    continue;
                }
                if ($definition->source !== null && $node->getAttribute($definition->source) === null) {
                    continue;
                }
                $deltas[$definition->column] = 1;

                continue;
            }

            if (($definition->function === AggregateFunction::Max
                || $definition->function === AggregateFunction::Min)
                && $definition->source !== null
            ) {
                if (self::filterExcludes($node, $definition->filter)) {
                    continue;
                }
                // SQL MIN/MAX ignore NULL — a NULL source contributes
                // nothing to the parent's extremum. Without this guard
                // `asNumericOrZero` would inject 0 as a candidate and
                // lower an ancestor's stored MAX (or clobber its NULL).
                $raw = $node->getAttribute($definition->source);
                if ($raw === null) {
                    continue;
                }
                // Type-preserving read — decimal-cast sources lose the
                // fractional part under asIntOrZero and the captured
                // extreme propagates upward as the truncated value.
                $value = Numeric::asNumericOrZero($raw);
                $extremes[$definition->column] = [
                    'function' => $definition->function,
                    'value' => $value,
                ];

                continue;
            }

            // BitOr / BitAnd / BitXor are all handled by the
            // requiresChainRecompute() branch above — bitwise rollups
            // recompute the affected subtree rather than apply a per-bit
            // delta.
        }

        /** @var array<string, ListenerAggregateDefinition> $exclusiveListenerDefs */
        $exclusiveListenerDefs = [];

        foreach (AggregateRegistry::for($modelClass) as $definition) {
            if (! $definition instanceof ListenerAggregateDefinition) {
                continue;
            }

            if ($definition->lazy) {
                continue;
            }

            if (! $definition->isInclusive()) {
                $exclusiveListenerDefs[$definition->column] = $definition;

                continue;
            }

            $op = $definition->operation;

            // Companion-derived display columns (Avg, Variance, Stddev,
            // GeometricMean, HarmonicMean) maintained by auto-promoted
            // Sum / Sum_sq / Count companions which iterate this loop
            // as separate ListenerAggregateDefinition entries.
            if (in_array($op, [
                AggregateFunction::Avg,
                AggregateFunction::Variance,
                AggregateFunction::Stddev,
                AggregateFunction::GeometricMean,
                AggregateFunction::HarmonicMean,
            ], true)) {
                if ($op !== AggregateFunction::Avg) {
                    // AVG's display is composed inline by DeltaMaintenance
                    // from the companion Sum + Count, so it does NOT need
                    // a chain pass. Variance / Stddev / GeoMean / HarmonicMean
                    // need the per-row contribution list in PHP.
                    $exclusiveListenerDefs[$definition->column] = $definition;
                }

                continue;
            }

            $listener = $definition->makeListener();
            $raw = $listener->contribution($node);
            $contrib = ListenerMaintenance::resolveContribution($definition, $raw, $node->getAttributes());
            $value = Numeric::contributionOrZero($contrib);

            if ($op === AggregateFunction::Sum) {
                if ($value != 0) {
                    $deltas[$definition->column] = $value;
                }
            } elseif ($op === AggregateFunction::Count) {
                if ($contrib !== null) {
                    $deltas[$definition->column] = 1;
                }
            } elseif ($contrib !== null) {
                // Min / Max — only remaining ops after the companion-derived
                // bucket above.
                $extremes[$definition->column] = ['function' => $op, 'value' => $value];
            }
        }

        $lazyColumns = LazyAggregateAccess::allLazyColumns($modelClass);

        if ($deltas === [] && $extremes === [] && $chainRecomputes === [] && $exclusiveListenerDefs === [] && $lazyColumns === []) {
            return;
        }

        $bounds = $node->getBounds();
        $softDeletedColumn = AggregateAnchor::softDeleteColumn($node);

        $preSnapshot = ChangeFeedRecorder::capture(
            node: $node,
            stage: 'on_create',
            bounds: $bounds,
            includeSelf: true,
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
        }

        if ($chainRecomputes !== []) {
            LifecycleSupport::applyChainRecompute($node, $bounds, $scope, $chainRecomputes);
        }

        if ($exclusiveListenerDefs !== []) {
            ListenerCalculator::chainRecompute(
                node: $node,
                bounds: $bounds,
                scope: $scope,
                definitions: $exclusiveListenerDefs,
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

        LifecycleSupport::dispatchAggregatesRecomputed($node, 'on_create');
    }

    /**
     * Whether a filter predicate excludes the new node. Evaluates against
     * cast attribute values, not the raw attribute set: filters compare to
     * declared values (e.g. `true`) while getAttributes() returns the raw
     * stored form (`1` for a boolean column created with `active = 1`), so
     * a raw read drifts permanently against the SQL filter's view. Matches
     * the cast-aware read DeltaCapture uses on the update path.
     */
    private static function filterExcludes(Model $node, ?FilterPredicate $filter): bool
    {
        if (! $filter instanceof FilterPredicate) {
            return false;
        }

        $cast = [];
        foreach ($filter->watchColumns() as $column) {
            $cast[$column] = $node->getAttribute($column);
        }

        return $filter->evaluateFor($cast) !== true;
    }
}
