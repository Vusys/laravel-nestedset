<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Numeric;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Contracts\TreeAggregateListener;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * PHP-side compute paths for listener aggregates that aren't part of
 * {@see ListenerMaintenance} (which handles the writeback fix pass
 * and the per-mutation contribution resolution).
 *
 * Two responsibilities:
 *  - {@see freshAggregate()} — one-node fresh read used by
 *    `freshAggregate($column)` and the lazy refresh path.
 *  - {@see chainRecompute()} — bulk recompute of listener aggregates
 *    across an ancestor chain, called from every lifecycle hook.
 */
final class ListenerCalculator
{
    /**
     * PHP-based fresh read for a single listener aggregate column on
     * the given node.
     *
     * @param  Model&HasNestedSet  $node
     */
    public static function freshAggregate(
        Model $node,
        ListenerAggregateDefinition $definition,
        bool $withTrashed = false,
    ): int|float|null {
        $bounds = $node->getBounds();
        $lftCol = $node->getLftName();
        $rgtCol = $node->getRgtName();
        $scope = NestedSetScopeResolver::valuesFor($node);
        $listener = $definition->makeListener();

        $modelClass = $node::class;
        $query = $modelClass::query();
        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
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
        // memory is O(1) regardless of subtree size. We carry enough
        // state for every supported operation (sum, count, min, max,
        // sum_sq for variance/stddev, sum_log + count_pos for geomean,
        // sum_recip + count_nonzero for harmonic). The final match
        // picks the right one for the listener's declared operation.
        $sum = 0;
        $count = 0;
        $min = null;
        $max = null;
        $sumSq = 0.0;
        $sumLog = 0.0;
        $countPos = 0;
        $sumRecip = 0.0;
        $countNonZero = 0;
        foreach ($query->cursor() as $candidate) {
            $attributes = $candidate->getAttributes();
            $c = ListenerMaintenance::resolveContribution(
                $definition,
                $listener->contribution($candidate),
                $attributes,
            );
            if ($c === null) {
                continue;
            }
            $sum += $c;
            $count++;
            $min = $min === null ? $c : min($min, $c);
            $max = $max === null ? $c : max($max, $c);
            $sumSq += $c * $c;
            if ($c > 0) {
                $sumLog += log((float) $c);
                $countPos++;
            }
            if ($c != 0) {
                $sumRecip += 1.0 / $c;
                $countNonZero++;
            }
        }

        return match ($definition->operation) {
            AggregateFunction::Sum => $sum,
            AggregateFunction::Count => $count,
            AggregateFunction::Min => $min,
            AggregateFunction::Max => $max,
            AggregateFunction::Avg => $count === 0 ? null : $sum / $count,
            AggregateFunction::Variance => $count === 0
                ? null
                : max(0.0, ($count * $sumSq - $sum * $sum) / ($count * $count)),
            AggregateFunction::Stddev => $count === 0
                ? null
                : sqrt(max(0.0, ($count * $sumSq - $sum * $sum) / ($count * $count))),
            AggregateFunction::GeometricMean => $countPos === 0 ? null : exp($sumLog / $countPos),
            AggregateFunction::HarmonicMean => ($countNonZero === 0 || $sumRecip === 0.0)
                ? null
                : $countNonZero / $sumRecip,
            AggregateFunction::BitOr,
            AggregateFunction::BitAnd,
            AggregateFunction::BitXor => throw new \LogicException(
                'Bitwise listener aggregates are not supported — ListenerAggregateDefinition rejects them at construction.',
            ),
            AggregateFunction::WeightedAvg,
            AggregateFunction::BoolOr,
            AggregateFunction::BoolAnd,
            AggregateFunction::DistinctCount,
            AggregateFunction::StringAgg,
            AggregateFunction::JsonAgg,
            AggregateFunction::JsonObjectAgg,
            AggregateFunction::Median,
            AggregateFunction::Percentile,
            AggregateFunction::TopK => throw new AggregateConfigurationException(sprintf(
                'Listener aggregates do not support %s; declare it via #[NestedSetAggregate] (column-based) instead.',
                $definition->operation->value,
            )),
        };
    }

    /**
     * PHP-based recompute of listener aggregate columns for all
     * ancestors of the given bounds. For each ancestor, loads all
     * descendants via Eloquent, calls each listener on each, and folds
     * the contributions according to the operation.
     *
     * @param  Model&HasNestedSet  $node
     * @param  array<string, ListenerAggregateDefinition>  $definitions  column => definition
     * @param  array<string, mixed>  $scope
     */
    public static function chainRecompute(
        Model $node,
        NodeBounds $bounds,
        array $scope,
        array $definitions,
        bool $includeSelf = true,
        ?NodeBounds $excludeBounds = null,
    ): void {
        if ($definitions === []) {
            return;
        }

        $modelClass = $node::class;
        $lftCol = $node->getLftName();
        $rgtCol = $node->getRgtName();

        $ancestorQuery = $modelClass::query()
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

        $nodesQuery = $modelClass::query()
            ->where($lftCol, '>=', $topLft)
            ->where($rgtCol, '<=', $topRgt);
        foreach ($scope as $col => $value) {
            $nodesQuery->where($col, $value);
        }

        // Stream the bounding-box subtree via cursor: build the
        // contribution cache and bounds list in one pass, releasing
        // each hydrated Model immediately. One listener instance per
        // distinct listenerClass — companions share the same
        // contribution() output as their parent display column, so we
        // call it once per node and apply each definition's filter +
        // sourceTransform afterwards via resolveContribution().
        /** @var array<class-string<TreeAggregateListener>, TreeAggregateListener> $listenerByClass */
        $listenerByClass = [];
        /** @var array<string, array<int|string, int|float|null>> $contribCache */
        $contribCache = [];
        foreach ($definitions as $column => $definition) {
            $listenerByClass[$definition->listenerClass] ??= $definition->makeListener();
            $contribCache[$column] = [];
        }

        /** @var list<array{key: int|string, lft: int, rgt: int}> $nodeBounds */
        $nodeBounds = [];
        foreach ($nodesQuery->cursor() as $candidate) {
            $key = $candidate->getKey();
            if (! is_int($key) && ! is_string($key)) {
                continue;
            }
            $nodeBounds[] = [
                'key' => $key,
                'lft' => Numeric::asIntOrZero($candidate->getAttribute($lftCol)),
                'rgt' => Numeric::asIntOrZero($candidate->getAttribute($rgtCol)),
            ];
            $attributes = $candidate->getAttributes();
            $rawByClass = [];
            foreach ($definitions as $column => $definition) {
                $rawByClass[$definition->listenerClass] ??= $listenerByClass[$definition->listenerClass]->contribution($candidate);
                $contribCache[$column][$key] = ListenerMaintenance::resolveContribution(
                    $definition,
                    $rawByClass[$definition->listenerClass],
                    $attributes,
                );
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

                $updates[$column] = ListenerMaintenance::applyListenerOperation($definition, $candidates);
            }

            $node->getConnection()->table($node->getTable())
                ->where($node->getKeyName(), $ancestor->getKey())
                ->update($updates);
        }
    }

    /**
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @return list<ListenerAggregateDefinition>
     */
    public static function listenerDefinitions(string $modelClass): array
    {
        $defs = [];
        foreach (AggregateRegistry::for($modelClass) as $def) {
            if ($def instanceof ListenerAggregateDefinition) {
                $defs[] = $def;
            }
        }

        return $defs;
    }
}
