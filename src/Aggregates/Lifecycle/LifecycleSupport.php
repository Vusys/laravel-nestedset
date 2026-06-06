<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Lifecycle;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Numeric;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Repair\AggregateAnchor;
use Vusys\NestedSet\Aggregates\Strategy\ColumnSpec;
use Vusys\NestedSet\Aggregates\Strategy\RecomputeMaintenance;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\Aggregates\NodeAggregatesRecomputed;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\NodeBounds;

/**
 * Shared helpers called from every aggregate lifecycle hook:
 *
 *  - {@see applyChainRecompute()} — bulk recompute for raw-filter /
 *    exclusive / collection-aggregate SQL definitions.
 *  - {@see applyCapturedRecomputes()} — Min/Max ancestor recompute
 *    with `stored = previous_value` cheap-skip.
 *  - {@see applyMoveRecomputes()} — variant for the before-move hook
 *    that excludes the moving subtree from the inner scan.
 *  - {@see collectMoveSubtreeContribution()} — walks the registry and
 *    produces the (deltas, recomputes, candidate-extremes) triple the
 *    before- and after-move hooks consume.
 *  - {@see dispatchAggregatesRecomputed()} — single-event dispatch
 *    per hook for {@see NodeAggregatesRecomputed} subscribers.
 */
final class LifecycleSupport
{
    /**
     * Bulk-recomputes the listed raw-filter aggregate columns across
     * the ancestor chain of `$bounds`. Used wherever a per-row
     * mutation may change which rows pass the (un-PHP-evaluable) raw
     * predicate — source/watch column update, create, delete, move,
     * restore.
     *
     * @param  Model&HasNestedSet  $node
     * @param  array<string, AggregateDefinition>  $definitions
     * @param  array<string, mixed>  $scope
     */
    public static function applyChainRecompute(
        Model $node,
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
            $columns[] = new ColumnSpec(
                column: $aggregateColumn,
                function: $definition->function,
                // RecomputeMaintenance reads inner_a.<source>; for COUNT(*) the
                // source is null in the definition, but the helper handles
                // empty string specially.
                source: $definition->source ?? '',
                inclusive: $definition->inclusive,
                filter: $definition->filter,
                // Variance / Stddev recomputes need the sample flag to pick
                // the right denominator; the SumSq companion of those kinds
                // needs its Square source-transform so the inner SQL emits
                // `SUM(x * x)` instead of `SUM(x)`. Without these two fields,
                // chain recomputes triggered by raw-filter / exclusive /
                // move / restore paths silently fall back to population
                // maths and rebuild SumSq as a plain Sum.
                sample: $definition->sample,
                sourceTransform: $definition->sourceTransform,
                definition: $definition,
            );
        }

        RecomputeMaintenance::apply(
            connection: $node->getConnection(),
            table: $node->getTable(),
            lftCol: $node->getLftName(),
            rgtCol: $node->getRgtName(),
            bounds: $bounds,
            columns: $columns,
            scope: $scope,
            filterEquals: [],
            locking: RecomputeMaintenance::lockingFromConfig(),
            excludeBounds: $excludeBounds,
            softDeletedColumn: AggregateAnchor::softDeleteColumn($node),
            idCol: $node->getKeyName(),
        );
    }

    /**
     * @param  Model&HasNestedSet  $node
     * @param  array<string, array{function: AggregateFunction, source: string, filterValue: int|float, filter: FilterPredicate|null}>  $recomputes
     * @param  array<string, mixed>  $scope
     */
    public static function applyCapturedRecomputes(
        Model $node,
        array $recomputes,
        array $scope,
    ): void {
        $columns = [];
        $filterEquals = [];

        foreach ($recomputes as $aggregateColumn => $spec) {
            $columns[] = new ColumnSpec(
                column: $aggregateColumn,
                function: $spec['function'],
                source: $spec['source'],
                inclusive: true,
                filter: $spec['filter'],
            );
            $filterEquals[$aggregateColumn] = $spec['filterValue'];
        }

        RecomputeMaintenance::apply(
            connection: $node->getConnection(),
            table: $node->getTable(),
            lftCol: $node->getLftName(),
            rgtCol: $node->getRgtName(),
            bounds: $node->getBounds(),
            columns: $columns,
            scope: $scope,
            filterEquals: $filterEquals,
            locking: RecomputeMaintenance::lockingFromConfig(),
            softDeletedColumn: AggregateAnchor::softDeleteColumn($node),
            idCol: $node->getKeyName(),
        );
    }

    /**
     * @param  Model&HasNestedSet  $node
     * @param  array<string, array{function: AggregateFunction, source: string, filterValue: int|float, filter: FilterPredicate|null}>  $minMaxByFunction
     * @param  array<string, mixed>  $scope
     */
    public static function applyMoveRecomputes(
        Model $node,
        NodeBounds $bounds,
        array $minMaxByFunction,
        array $scope,
        ?NodeBounds $excludeBounds = null,
    ): void {
        $columns = [];
        $filterEquals = [];

        foreach ($minMaxByFunction as $aggregateColumn => $spec) {
            $columns[] = new ColumnSpec(
                column: $aggregateColumn,
                function: $spec['function'],
                source: $spec['source'],
                inclusive: true,
                filter: $spec['filter'],
            );
            $filterEquals[$aggregateColumn] = $spec['filterValue'];
        }

        RecomputeMaintenance::apply(
            connection: $node->getConnection(),
            table: $node->getTable(),
            lftCol: $node->getLftName(),
            rgtCol: $node->getRgtName(),
            bounds: $bounds,
            columns: $columns,
            scope: $scope,
            filterEquals: $filterEquals,
            locking: RecomputeMaintenance::lockingFromConfig(),
            excludeBounds: $excludeBounds,
            softDeletedColumn: AggregateAnchor::softDeleteColumn($node),
            idCol: $node->getKeyName(),
        );
    }

    /**
     * Walks the registry and collects what each aggregate path needs
     * from the moving node's stored values for a move:
     *
     *   [0] SUM/COUNT deltas (positive — caller negates for old chain)
     *   [1] MIN/MAX recompute specs (filter by self's stored extremum)
     *   [2] MIN/MAX cheap-delta candidates (extend new chain)
     *
     * Same data is read by both before- and after-move hooks; this
     * helper keeps them in sync.
     *
     * @param  Model&HasNestedSet  $node
     * @return array{0: array<string, int|float>, 1: array<string, array{function: AggregateFunction, source: string, filterValue: int|float, filter: FilterPredicate|null}>, 2: array<string, array{function: AggregateFunction, value: int|float}>}
     */
    public static function collectMoveSubtreeContribution(Model $node): array
    {
        $sumCount = [];
        $minMaxRecomputes = [];
        $extremes = [];

        foreach (AggregateRegistry::for($node::class) as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }
            if (! $definition->inclusive) {
                continue;
            }
            // Lazy aggregates feed the invalidation path, not delta
            // arithmetic — their stored value is NULL between mutations
            // and would poison the SUM/COUNT delta if forwarded here.
            if ($definition->lazy) {
                continue;
            }

            if ($definition->function === AggregateFunction::Sum
                || $definition->function === AggregateFunction::Count) {
                // Preserve numeric type — decimal Sum companions of
                // WeightedAvg / GeometricMean / HarmonicMean would lose
                // their fraction under numeric().
                $value = Numeric::asNumericOrZero($node->getAttribute($definition->column));
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
                $rawStored = $node->getAttribute($definition->column);
                if ($rawStored === null) {
                    continue;
                }
                $stored = Numeric::asNumericOrZero($rawStored);
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

        foreach (AggregateRegistry::for($node::class) as $definition) {
            if (! $definition instanceof ListenerAggregateDefinition) {
                continue;
            }
            if (! $definition->isInclusive()) {
                continue;
            }

            $op = $definition->operation;

            // AVG and the companion-derived display ops (Variance /
            // Stddev / GeoMean / HarmonicMean) all skip this pass:
            // their auto-promoted Sum / Sum_sq / Count / Sum_log /
            // Sum_recip companions iterate the loop as separate
            // definitions and produce the delta / candidate-extreme
            // entries. The display columns themselves are recomputed
            // by ListenerCalculator::chainRecompute(), which the
            // before- and after-move hooks queue separately. Without
            // this skip, Variance/etc. would fall into the Min/Max
            // branch below and the stored value would be (mis)written
            // via DeltaMaintenance::buildExtremeSetClauses(),
            // corrupting the column on every move.
            if (in_array($op, [
                AggregateFunction::Avg,
                AggregateFunction::Variance,
                AggregateFunction::Stddev,
                AggregateFunction::GeometricMean,
                AggregateFunction::HarmonicMean,
            ], true)) {
                continue;
            }

            if ($op === AggregateFunction::Sum || $op === AggregateFunction::Count) {
                $value = Numeric::asNumericOrZero($node->getAttribute($definition->column));
                if ($value != 0) {
                    $sumCount[$definition->column] = $value;
                }

                continue;
            }

            // Min / Max — only remaining ops after Sum/Count/Avg/Variance/
            // Stddev/GeoMean/HarmonicMean above. Stored NULL means the
            // moved subtree has no matching contributions; skip so the
            // cheap-delta doesn't propagate a fake 0 candidate (would
            // clobber the destination's NULL).
            $rawStored = $node->getAttribute($definition->column);
            if ($rawStored === null) {
                continue;
            }
            $stored = Numeric::asNumericOrZero($rawStored);
            $minMaxRecomputes[$definition->column] = [
                'function' => $op,
                'source' => $definition->column,
                'filterValue' => $stored,
                'filter' => null,
            ];
            $extremes[$definition->column] = ['function' => $op, 'value' => $stored];
        }

        return [$sumCount, $minMaxRecomputes, $extremes];
    }

    /**
     * Dispatches {@see NodeAggregatesRecomputed} for one lifecycle
     * hook, naming every declared user-facing aggregate column on the
     * model. No-op when the model declares no aggregates or has no
     * primary key. Stage is one of 'on_create', 'on_delete',
     * 'on_restore', 'move'.
     *
     * @param  Model&HasNestedSet  $node
     */
    public static function dispatchAggregatesRecomputed(Model $node, string $stage): void
    {
        $definitions = AggregateRegistry::for($node::class);

        if ($definitions === []) {
            return;
        }

        $key = $node->getKey();
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
            modelClass: $node::class,
            nodeId: $key,
            columns: $columns,
            stage: $stage,
        ));
    }
}
