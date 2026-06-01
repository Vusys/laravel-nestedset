<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Listeners;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceTransform;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Numeric;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Query\Aggregates\Maintenance\AggregateDiffer;
use Vusys\NestedSet\Query\Aggregates\Maintenance\AggregateValueComparator;

/**
 * PHP-side maintenance for listener aggregate columns.
 *
 * Listener aggregates compute their contribution per row in user PHP
 * (`#[NestedSetAggregateListener]`), so there is no SQL expression we can
 * push down to the database the way {@see AggregateDiffer}
 * does for column-based aggregates. This class streams the in-scope
 * subtree once, calls each listener's `contribution()` per node, folds the
 * results per outer node, and writes back any drift it finds.
 *
 * Three public entry points the trait calls:
 *  - {@see fixListenerAggregatesPhp()} — the writeback pass used by both
 *    `fixAggregates()` and `fixAggregatesChunk()`.
 *  - {@see aggregateErrorsForListeners()} — read-only drift report used by
 *    `aggregateErrors()`.
 *  - {@see applyListenerOperation()} — pure helper that folds a flat list
 *    of contributions into a single value per `AggregateFunction`. Also
 *    used by the trait's per-mutation `applyListenerChainRecompute()`.
 *
 * Plus {@see mergeFixResults()}, which combines the SQL-side and
 * listener-side fix results into the single `AggregateFixResult` the
 * trait's public maintenance API returns.
 */
final class ListenerMaintenance
{
    /**
     * PHP-based fix pass for listener aggregate columns.
     *
     * Loads all in-scope Eloquent models once, computes contributions
     * in PHP, aggregates per outer node, and writes drifted rows.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @param  list<ListenerAggregateDefinition>  $definitions
     * @param  array<string, mixed>  $scope
     * @param  list<int|string>|null  $outerIds  null = fix all
     */
    public static function fixListenerAggregatesPhp(
        string $modelClass,
        array $definitions,
        array $scope,
        int|string|null $rootId,
        ?array $outerIds,
    ): AggregateFixResult {
        if ($definitions === []) {
            return new AggregateFixResult(totalRowsUpdated: 0, perColumn: []);
        }

        $instance = new $modelClass;
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
            $modelClass,
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

        // Sort by lft ASC once — this is pre-order over nested-set
        // intervals and the order the DFS pass requires. Subsequent
        // walks per definition are O(N) over the sorted list.
        usort($nodeMeta, static fn (array $a, array $b): int => $a['lft'] <=> $b['lft']);

        $outerFilter = $outerIds === null
            ? null
            : array_fill_keys($outerIds, true);

        // keyed by node id: ['id' => mixed, 'updates' => array<string, mixed>]
        $toUpdate = [];

        foreach ($definitions as $def) {
            $computedByKey = self::walkAndAggregate($nodeMeta, $def);

            foreach ($nodeMeta as $outer) {
                $outerKey = $outer['key'];

                if ($outerFilter !== null && ! isset($outerFilter[$outerKey])) {
                    continue;
                }

                $computed = $computedByKey[$outerKey] ?? null;
                $stored = $outer['stored'][$def->column] ?? null;

                if (! AggregateValueComparator::aggregatesEqual($stored, $computed)) {
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
     * Counts stored-vs-computed disagreements for listener aggregate columns.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @param  list<ListenerAggregateDefinition>  $definitions
     * @param  array<string, mixed>  $scope
     * @return array<string, int>
     */
    public static function aggregateErrorsForListeners(
        string $modelClass,
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

        $instance = new $modelClass;
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
            $modelClass,
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

        usort($nodeMeta, static fn (array $a, array $b): int => $a['lft'] <=> $b['lft']);

        foreach ($userDefs as $def) {
            $computedByKey = self::walkAndAggregate($nodeMeta, $def);

            foreach ($nodeMeta as $outer) {
                $computed = $computedByKey[$outer['key']] ?? null;
                $stored = $outer['stored'][$def->column] ?? null;

                if (! AggregateValueComparator::aggregatesEqual($stored, $computed)) {
                    $errors[$def->column] = ($errors[$def->column] ?? 0) + 1;
                }
            }
        }

        return $errors;
    }

    /**
     * One O(N) DFS pass over a `lft`-sorted node-meta list, producing
     * each node's stored aggregate value for the given definition.
     *
     * Algorithm: walk the sorted list maintaining a stack of currently
     * open ancestor frames. Each frame carries the node's own
     * contribution and a {@see ListenerAccumulator} over its
     * descendants so far. When the current node's `lft` exceeds a
     * frame's `rgt`, the frame closes — we finalise its display value
     * and merge the full subtree roll-up into its parent frame. After
     * the loop, any remaining frames are popped and finalised the same
     * way. Push and pop happen exactly once per node, so total work is
     * linear in node count.
     *
     * For exclusive aggregates, the stored value is the descendants-only
     * roll-up (finalised before the node's own contribution is folded
     * in); for inclusive aggregates, the own contribution is folded
     * first, then the accumulator is finalised. Either way the same
     * fully-merged accumulator is what propagates into the parent.
     *
     * @param  list<array{key: int|string, lft: int, rgt: int, contribs: array<string, int|float|null>, stored: array<string, mixed>}>  $nodeMeta
     * @return array<int|string, int|float|null> keyed by node primary key
     */
    private static function walkAndAggregate(array $nodeMeta, ListenerAggregateDefinition $def): array
    {
        /** @var array<int|string, int|float|null> $result */
        $result = [];
        /** @var list<array{rgt: int, key: int|string, own: int|float|null, acc: ListenerAccumulator}> $stack */
        $stack = [];

        foreach ($nodeMeta as $node) {
            $nodeLft = $node['lft'];

            while ($stack !== [] && $stack[array_key_last($stack)]['rgt'] < $nodeLft) {
                $closed = array_pop($stack);
                self::finalizeFrame($closed, $def, $result, $stack);
            }

            $stack[] = [
                'rgt' => $node['rgt'],
                'key' => $node['key'],
                'own' => $node['contribs'][$def->column] ?? null,
                'acc' => new ListenerAccumulator($def->operation),
            ];
        }

        while ($stack !== []) {
            $closed = array_pop($stack);
            self::finalizeFrame($closed, $def, $result, $stack);
        }

        return $result;
    }

    /**
     * Records the closed frame's display value and merges its full
     * subtree accumulator into the parent frame (if any).
     *
     * @param  array{rgt: int, key: int|string, own: int|float|null, acc: ListenerAccumulator}  $closed
     * @param  array<int|string, int|float|null>  $result
     * @param  list<array{rgt: int, key: int|string, own: int|float|null, acc: ListenerAccumulator}>  $stack
     */
    private static function finalizeFrame(
        array $closed,
        ListenerAggregateDefinition $def,
        array &$result,
        array &$stack,
    ): void {
        $acc = $closed['acc'];

        if ($def->isInclusive()) {
            $acc->addContribution($closed['own']);
            $result[$closed['key']] = $acc->finalize();
        } else {
            $result[$closed['key']] = $acc->finalize();
            $acc->addContribution($closed['own']);
        }

        if ($stack !== []) {
            $stack[array_key_last($stack)]['acc']->merge($acc);
        }
    }

    /**
     * Applies the listener's operation to a flat list of contributions.
     *
     * @param  list<int|float>  $contributions  (nulls already filtered out)
     */
    public static function applyListenerOperation(
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
            AggregateFunction::Variance => self::variance($contributions),
            AggregateFunction::Stddev => self::stddev($contributions),
            AggregateFunction::GeometricMean => self::geometricMean($contributions),
            AggregateFunction::HarmonicMean => self::harmonicMean($contributions),
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
                $def->operation->value,
            )),
        };
    }

    /**
     * Population variance over the supplied contributions, using the
     * textbook `E[X²] − E[X]²` form. Returns null on empty input.
     *
     * @param  list<int|float>  $contributions
     */
    private static function variance(array $contributions): ?float
    {
        if ($contributions === []) {
            return null;
        }

        $n = count($contributions);
        $sum = (float) array_sum($contributions);
        $sumSq = 0.0;
        foreach ($contributions as $c) {
            $sumSq += $c * $c;
        }

        // Same clamp as the SQL side — tiny negative results from
        // floating-point cancellation get folded to 0 so callers
        // (and stddev's sqrt) don't see "impossible" negative variance.
        $variance = ($n * $sumSq - $sum * $sum) / ($n * $n);

        return $variance < 0 ? 0.0 : $variance;
    }

    /**
     * Population standard deviation — sqrt of {@see variance()}.
     *
     * @param  list<int|float>  $contributions
     */
    private static function stddev(array $contributions): ?float
    {
        $variance = self::variance($contributions);

        return $variance === null ? null : sqrt($variance);
    }

    /**
     * Geometric mean — `EXP(Σ LN(x) / n)` over strictly positive
     * contributions. Non-positive contributions are excluded from both
     * the log-sum and the count, mirroring the SQL `__sum_log` / `__count`
     * companion shape (the count carries the Ln transform so it ticks
     * only on rows that contributed to the log-sum).
     *
     * @param  list<int|float>  $contributions
     */
    private static function geometricMean(array $contributions): ?float
    {
        $sumLog = 0.0;
        $count = 0;
        foreach ($contributions as $c) {
            if ($c > 0) {
                $sumLog += log((float) $c);
                $count++;
            }
        }

        return $count === 0 ? null : exp($sumLog / $count);
    }

    /**
     * Harmonic mean — `n / Σ(1/x)` over non-zero contributions. Zero
     * contributions are excluded from both the reciprocal-sum and the
     * count, mirroring the SQL `__sum_recip` / `__count` companion shape.
     *
     * @param  list<int|float>  $contributions
     */
    private static function harmonicMean(array $contributions): ?float
    {
        $sumRecip = 0.0;
        $count = 0;
        foreach ($contributions as $c) {
            if ($c != 0) {
                $sumRecip += 1.0 / $c;
                $count++;
            }
        }

        if ($count === 0 || $sumRecip == 0.0) {
            return null;
        }

        return $count / $sumRecip;
    }

    public static function mergeFixResults(AggregateFixResult $a, AggregateFixResult $b): AggregateFixResult
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
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @param  list<ListenerAggregateDefinition>  $definitions
     * @param  array<string, mixed>  $scope
     * @return list<array{key: int|string, lft: int, rgt: int, contribs: array<string, int|float|null>, stored: array<string, mixed>}>
     */
    private static function buildListenerNodeMeta(
        string $modelClass,
        array $definitions,
        array $scope,
        int|string|null $rootId,
        string $lftCol,
        string $rgtCol,
        bool $includeStored,
    ): array {
        $query = $modelClass::query();

        foreach ($scope as $col => $value) {
            $query->where($col, $value);
        }

        if ($rootId !== null) {
            $instance = new $modelClass;
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

        // Cache one listener instance per distinct listenerClass — multiple
        // definitions over the same listener (e.g. a Variance display plus
        // its auto-promoted __sum / __sum_sq / __count companions) share
        // the same contribution() output, so we call it once per node and
        // apply each definition's filter + sourceTransform afterwards.
        $listeners = [];
        foreach ($definitions as $def) {
            $listeners[$def->listenerClass] ??= $def->makeListener();
        }

        $meta = [];
        foreach ($query->cursor() as $node) {
            $key = $node->getKey();
            if (! is_int($key) && ! is_string($key)) {
                continue;
            }

            $rawByClass = [];
            $attributes = $node->getAttributes();

            $contribs = [];
            $stored = [];
            foreach ($definitions as $def) {
                $rawByClass[$def->listenerClass] ??= $listeners[$def->listenerClass]->contribution($node);
                $contribs[$def->column] = self::resolveContribution(
                    $def,
                    $rawByClass[$def->listenerClass],
                    $attributes,
                );
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
     * Applies the definition's row filter and source transform to a raw
     * contribution. Returns null when the row is excluded — either by the
     * filter rejecting it, the raw contribution being null, or a domain
     * restriction (Ln on non-positive, Recip on zero). Returning null
     * means the row contributes nothing to this column's accumulator,
     * matching the SUM(NULL)/COUNT(NULL) skip semantic on the SQL side.
     *
     * @param  array<string, mixed>  $attributes  raw node attributes for filter eval
     */
    public static function resolveContribution(
        ListenerAggregateDefinition $definition,
        int|float|null $raw,
        array $attributes,
    ): int|float|null {
        if ($definition->filter !== null && $definition->filter->evaluateFor($attributes) === false) {
            return null;
        }

        if ($raw === null) {
            return null;
        }

        return match ($definition->sourceTransform) {
            CompanionSourceTransform::Identity => $raw,
            CompanionSourceTransform::Square => $raw * $raw,
            CompanionSourceTransform::Ln => $raw > 0 ? log((float) $raw) : null,
            CompanionSourceTransform::Recip => $raw != 0 ? 1.0 / $raw : null,
            CompanionSourceTransform::AsInt => $raw != 0 ? 1 : 0,
            CompanionSourceTransform::TimesWeight => throw new \LogicException(
                'CompanionSourceTransform::TimesWeight is not supported for listener aggregates; '
                .'WeightedAvg routes through SQL aggregates only.',
            ),
        };
    }
}
