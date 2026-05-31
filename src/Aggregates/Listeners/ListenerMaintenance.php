<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Listeners;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Aggregates\AggregateFunction;
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

                if (! AggregateValueComparator::aggregatesEqual($stored, $computed)) {
                    $errors[$def->column] = ($errors[$def->column] ?? 0) + 1;
                }
            }
        }

        return $errors;
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
            AggregateFunction::Percentile,
            AggregateFunction::TopK => throw new AggregateConfigurationException(sprintf(
                'Listener aggregates do not support %s; declare it via #[NestedSetAggregate] (column-based) instead.',
                $def->operation->value,
            )),
        };
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
}
