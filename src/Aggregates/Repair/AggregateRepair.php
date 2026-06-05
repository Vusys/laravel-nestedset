<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Repair;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Aggregates\Lazy\LazyAggregateAccess;
use Vusys\NestedSet\Aggregates\Listeners\ListenerCalculator;
use Vusys\NestedSet\Aggregates\Listeners\ListenerMaintenance;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\Aggregates\AggregateDriftDetected;
use Vusys\NestedSet\Events\Aggregates\FixAggregatesChunkCompleted;
use Vusys\NestedSet\Events\Aggregates\FixAggregatesCompleted;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Jobs\FixAggregatesJob;
use Vusys\NestedSet\Query\Aggregates\Maintenance\AggregateDiffer;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * The full aggregate-repair public surface — drift reporting, sync
 * repair, chunked repair, fixTree's post-rebuild reconcile, plus
 * shared anchor and root-id helpers. Each call mirrors the matching
 * trait static (e.g. `Area::fixAggregates($anchor)` ⇒
 * `AggregateRepair::fixAggregates(Area::class, $anchor)`).
 */
final class AggregateRepair
{
    /**
     * Returns per-column counts of stored aggregate columns that
     * disagree with their freshly-computed values over the source.
     * Empty array on a model with no aggregate declarations.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @return array<string, int>
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function aggregateErrors(string $modelClass, ?HasNestedSet $anchor): array
    {
        $instance = AggregateAnchor::anchorOrFail($modelClass, $anchor);
        $rootId = AggregateAnchor::rootIdOf($anchor);
        $scope = $anchor instanceof Model
            ? NestedSetScopeResolver::valuesFor($anchor)
            : [];

        $treeBuilderErrors = AggregateDiffer::aggregateErrors(
            connection: $instance->getConnection(),
            table: $instance->getTable(),
            lftCol: $instance->getLftName(),
            rgtCol: $instance->getRgtName(),
            scope: $scope,
            definitions: AggregateRegistry::for($modelClass),
            rootId: $rootId,
            parentIdCol: $instance->getParentIdName(),
            depthCol: $instance->getDepthName(),
            softDeletedColumn: AggregateAnchor::softDeleteColumn($instance),
            idCol: $instance->getKeyName(),
        );

        $listenerErrors = ListenerMaintenance::aggregateErrorsForListeners(
            modelClass: $modelClass,
            definitions: ListenerCalculator::listenerDefinitions($modelClass),
            scope: $scope,
            rootId: $rootId,
        );

        $merged = array_merge($treeBuilderErrors, $listenerErrors);

        $nonZero = array_filter($merged, static fn (int $count): bool => $count > 0);
        if ($nonZero !== []) {
            EventDispatcher::dispatch(new AggregateDriftDetected(
                modelClass: $modelClass,
                anchorId: $rootId,
                perColumn: $nonZero,
                totalDrift: array_sum($nonZero),
            ));
        }

        return $merged;
    }

    /**
     * Recomputes every declared aggregate column (including internal
     * AVG companions) from the source data and overwrites stored
     * values that have drifted. Returns a structured count per column.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function fixAggregates(
        string $modelClass,
        ?HasNestedSet $anchor,
        ?int $chunkSize = null,
        ?\Closure $onChunk = null,
    ): AggregateFixResult {
        if ($chunkSize !== null && $chunkSize > 0) {
            return self::fixAggregatesChunked($modelClass, $anchor, $chunkSize, $onChunk);
        }

        $instance = AggregateAnchor::writeAnchorOrFail($modelClass, $anchor);
        $rootId = AggregateAnchor::rootIdOf($anchor);
        $scope = $anchor instanceof Model
            ? NestedSetScopeResolver::valuesFor($anchor)
            : [];
        $softDeletedColumn = AggregateAnchor::softDeleteColumn($instance);

        $startNs = hrtime(true);
        $sqlResult = AggregateDiffer::fixAggregates(
            connection: $instance->getConnection(),
            table: $instance->getTable(),
            lftCol: $instance->getLftName(),
            rgtCol: $instance->getRgtName(),
            scope: $scope,
            definitions: AggregateRegistry::for($modelClass),
            rootId: $rootId,
            parentIdCol: $instance->getParentIdName(),
            depthCol: $instance->getDepthName(),
            softDeletedColumn: $softDeletedColumn,
            idCol: $instance->getKeyName(),
        );

        $listenerResult = ListenerMaintenance::fixListenerAggregatesPhp(
            modelClass: $modelClass,
            definitions: ListenerCalculator::listenerDefinitions($modelClass),
            scope: $scope,
            rootId: $rootId,
            outerIds: null,
        );

        LazyAggregateAccess::stampForFix($instance, $scope, $rootId, null, $softDeletedColumn);

        $result = ListenerMaintenance::mergeFixResults($sqlResult, $listenerResult);
        $durationMs = (hrtime(true) - $startNs) / 1_000_000;

        EventDispatcher::dispatch(new FixAggregatesCompleted(
            modelClass: $modelClass,
            anchorId: $rootId,
            totalRowsUpdated: $result->totalRowsUpdated,
            perColumn: $result->perColumn,
            durationMs: $durationMs,
            chunkSize: null,
            totalChunks: 1,
        ));

        return $result;
    }

    /**
     * Repairs a single chunk of stored aggregate columns and returns
     * the cursor to feed into the next chunk (or null if this was the
     * last). Used by {@see FixAggregatesJob} to
     * break a long-running repair into a series of short, self-re-
     * dispatching jobs.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @return array{result: AggregateFixResult, nextAfterId: int|string|null}
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function fixAggregatesChunk(
        string $modelClass,
        ?HasNestedSet $anchor,
        int|string|null $afterId,
        int $chunkSize,
    ): array {
        $instance = AggregateAnchor::writeAnchorOrFail($modelClass, $anchor);
        $rootId = AggregateAnchor::rootIdOf($anchor);
        $softDeletedColumn = AggregateAnchor::softDeleteColumn($instance);

        if ($chunkSize <= 0) {
            throw new InvalidArgumentException('fixAggregatesChunk: chunkSize must be > 0.');
        }

        $key = $instance->getKeyName();
        $scope = $anchor instanceof Model
            ? NestedSetScopeResolver::valuesFor($anchor)
            : [];

        $query = $instance->getConnection()
            ->table($instance->getTable())
            ->select($key)
            ->orderBy($key)
            ->limit($chunkSize);

        if ($afterId !== null) {
            $query->where($key, '>', $afterId);
        }
        foreach ($scope as $col => $value) {
            $query->where($col, '=', $value);
        }
        if ($rootId !== null) {
            $rootRow = $instance->getConnection()
                ->table($instance->getTable())
                ->where($instance->getKeyName(), $rootId)
                ->first([$instance->getLftName(), $instance->getRgtName()]);
            // Missing-row guard: a queued chunk picked up by a worker
            // minutes after dispatch may find the anchor row gone (hard
            // delete, scope move). Without this throw the chunk would
            // run unbounded over the whole scope — a silently widened
            // repair on a multi-million-row table.
            if ($rootRow === null) {
                throw new \RuntimeException(sprintf(
                    '%s::fixAggregatesChunk: anchor id %s not found — was the row deleted? '
                    .'Refusing to widen the repair to the whole scope.',
                    $modelClass,
                    (string) $rootId,
                ));
            }
            $query->where($instance->getLftName(), '>=', $rootRow->{$instance->getLftName()})
                ->where($instance->getRgtName(), '<=', $rootRow->{$instance->getRgtName()});
        }

        $isIntKey = $instance->getKeyType() === 'int';
        $ids = array_values(array_map(
            static function (\stdClass $row) use ($instance, $isIntKey): int|string {
                $value = $row->{$instance->getKeyName()} ?? null;

                if ($isIntKey) {
                    return (int) $value;
                }

                return (string) $value;
            },
            $query->get()->all(),
        ));

        $result = AggregateDiffer::fixAggregates(
            connection: $instance->getConnection(),
            table: $instance->getTable(),
            lftCol: $instance->getLftName(),
            rgtCol: $instance->getRgtName(),
            scope: $scope,
            definitions: AggregateRegistry::for($modelClass),
            rootId: $rootId,
            outerIds: $ids,
            softDeletedColumn: $softDeletedColumn,
            idCol: $instance->getKeyName(),
        );

        $listenerChunkResult = ListenerMaintenance::fixListenerAggregatesPhp(
            modelClass: $modelClass,
            definitions: ListenerCalculator::listenerDefinitions($modelClass),
            scope: $scope,
            rootId: $rootId,
            outerIds: $ids,
        );

        LazyAggregateAccess::stampForFix($instance, $scope, $rootId, $ids, $softDeletedColumn);

        $result = ListenerMaintenance::mergeFixResults($result, $listenerChunkResult);

        $nextAfterId = count($ids) === $chunkSize ? end($ids) : null;

        return ['result' => $result, 'nextAfterId' => $nextAfterId];
    }

    /**
     * Internal — called from `HasTreeRepair::fixTree()` after the
     * structural repair so stored aggregates match the rebuilt tree.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     */
    public static function runForFixTree(
        string $modelClass,
        ?HasNestedSet $anchor,
        int|string|null $rootId,
    ): ?AggregateFixResult {
        $definitions = AggregateRegistry::for($modelClass);

        if ($definitions === []) {
            return null;
        }

        $instance = new $modelClass;
        $scope = $anchor instanceof Model
            ? NestedSetScopeResolver::valuesFor($anchor)
            : [];

        $sqlResult = AggregateDiffer::fixAggregates(
            connection: $instance->getConnection(),
            table: $instance->getTable(),
            lftCol: $instance->getLftName(),
            rgtCol: $instance->getRgtName(),
            scope: $scope,
            definitions: $definitions,
            rootId: $rootId,
            parentIdCol: $instance->getParentIdName(),
            depthCol: $instance->getDepthName(),
            softDeletedColumn: AggregateAnchor::softDeleteColumn($instance),
            idCol: $instance->getKeyName(),
        );

        $listenerResult = ListenerMaintenance::fixListenerAggregatesPhp(
            modelClass: $modelClass,
            definitions: ListenerCalculator::listenerDefinitions($modelClass),
            scope: $scope,
            rootId: $rootId,
            outerIds: null,
        );

        return ListenerMaintenance::mergeFixResults($sqlResult, $listenerResult);
    }

    /**
     * Synchronous chunk-loop counterpart to {@see self::fixAggregates()}.
     * Drives `fixAggregatesChunk` from cursor=null until it returns
     * nextAfterId=null, accumulating per-chunk results into one
     * combined AggregateFixResult.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     */
    private static function fixAggregatesChunked(
        string $modelClass,
        ?HasNestedSet $anchor,
        int $chunkSize,
        ?\Closure $onChunk,
    ): AggregateFixResult {
        $totalRows = 0;
        /** @var array<string, int> $perColumn */
        $perColumn = [];

        $cursor = null;
        $chunkIndex = 0;
        $anchorRootId = AggregateAnchor::rootIdOf($anchor);
        $loopStartNs = hrtime(true);

        // Non-progress detector: the loop trusts `nextAfterId` to
        // eventually return null. A buggy backend or corrupted index
        // could return the same cursor forever; we abort if the
        // cursor fails to advance across consecutive iterations
        // rather than capping total iterations (which would falsely
        // trip on legitimate small-chunkSize runs over large tables).
        $prevCursor = null;
        $cursorRepeats = 0;

        do {
            $chunkStartNs = hrtime(true);
            /** @var array{result: AggregateFixResult, nextAfterId: int|string|null} $chunk */
            $chunk = $modelClass::fixAggregatesChunk($anchor, $cursor, $chunkSize);
            $chunkMs = (hrtime(true) - $chunkStartNs) / 1_000_000;
            $result = $chunk['result'];

            $totalRows += $result->totalRowsUpdated;
            foreach ($result->perColumn as $column => $count) {
                $perColumn[$column] = ($perColumn[$column] ?? 0) + $count;
            }

            $prevCursor = $cursor;
            $cursor = $chunk['nextAfterId'];

            EventDispatcher::dispatch(new FixAggregatesChunkCompleted(
                modelClass: $modelClass,
                anchorId: $anchorRootId,
                chunkIndex: $chunkIndex,
                chunkSize: $chunkSize,
                rowsUpdated: $result->totalRowsUpdated,
                cursorAfter: $cursor,
                durationMs: $chunkMs,
            ));

            if ($onChunk instanceof \Closure) {
                $onChunk($result, $chunkIndex, $cursor);
            }

            $chunkIndex++;

            if ($cursor !== null && $cursor === $prevCursor) {
                if (++$cursorRepeats > 2) {
                    throw new \RuntimeException(sprintf(
                        'fixAggregates(chunkSize: …): cursor stuck at %s — chunk loop is not advancing.',
                        (string) $cursor,
                    ));
                }
            } else {
                $cursorRepeats = 0;
            }
        } while ($cursor !== null);

        EventDispatcher::dispatch(new FixAggregatesCompleted(
            modelClass: $modelClass,
            anchorId: $anchorRootId,
            totalRowsUpdated: $totalRows,
            perColumn: $perColumn,
            durationMs: (hrtime(true) - $loopStartNs) / 1_000_000,
            chunkSize: $chunkSize,
            totalChunks: $chunkIndex,
        ));

        return new AggregateFixResult(
            totalRowsUpdated: $totalRows,
            perColumn: $perColumn,
        );
    }
}
