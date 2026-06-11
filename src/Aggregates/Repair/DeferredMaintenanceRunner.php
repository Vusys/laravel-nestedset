<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Repair;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\Events\Aggregates\DeferredAggregateMaintenanceCompleted;
use Vusys\NestedSet\Events\Aggregates\DeferredMaintenanceStarting;
use Vusys\NestedSet\Events\Aggregates\FixAggregatesJobDispatched;
use Vusys\NestedSet\Events\Diagnostics\ScopeViolationDetected;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Jobs\FixAggregatesJob;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * The deferred / async corner of the repair surface:
 *
 *  - {@see withDeferred()} — runs a closure with per-row aggregate
 *    maintenance suspended, then issues one `fixAggregates($anchor)`
 *    at the outermost exit to repair cumulative drift in a single
 *    pass. Re-entrant; failure-safe.
 *  - {@see queueFixAggregates()} — dispatches a
 *    {@see FixAggregatesJob} so a heavily-drifted tree can be
 *    repaired off the request hot path.
 *
 * The per-class deferral counter lives on the trait
 * (`HasNestedSetAggregates::$deferredDepth`) so each using class gets
 * its own counter. This class drives it through
 * `$modelClass::aggregateDeferredDepth()` /
 * `incrementAggregateDeferredDepth()` /
 * `decrementAggregateDeferredDepth()` — small accessor methods on the
 * trait that intentionally only this class calls.
 */
final class DeferredMaintenanceRunner
{
    /**
     * @template T
     *
     * @param  class-string<Model&MaintainsTreeAggregates>  $modelClass
     * @param  \Closure(): T  $work
     * @return T
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function withDeferred(
        string $modelClass,
        \Closure $work,
        ?HasNestedSet $anchor,
    ): mixed {
        // Validate the anchor upfront — scoped models need one for the
        // final fixAggregates call, and a synchronous failure here is
        // friendlier than running the entire closure and only failing
        // at the repair pass. Skipped when the model declares no
        // aggregate columns: there's nothing to repair, so demanding a
        // scope anchor would block no-aggregate flows that defer (e.g.
        // bulkInsertTree seeding scoped roots).
        if (AggregateRegistry::for($modelClass) !== []) {
            AggregateAnchor::writeAnchorOrFail($modelClass, $anchor);
        }

        $modelClass::incrementAggregateDeferredDepth();
        $isOutermost = $modelClass::aggregateDeferredDepth() === 1;
        $closureMs = 0.0;
        $repairMs = 0.0;
        /** @var AggregateFixResult|null $repairResult */
        $repairResult = null;
        $closureFailed = false;

        try {
            if ($isOutermost) {
                // Dispatch inside the try so a throwing listener still
                // hits the finally that decrements the counter.
                EventDispatcher::dispatch(new DeferredMaintenanceStarting(
                    modelClass: $modelClass,
                    anchorId: AggregateAnchor::rootIdOf($anchor),
                ));
            }

            $closureStartNs = hrtime(true);
            $result = $work();
            $closureMs = (hrtime(true) - $closureStartNs) / 1_000_000;

            return $result;
        } catch (\Throwable $e) {
            $closureFailed = true;
            throw $e;
        } finally {
            $modelClass::decrementAggregateDeferredDepth();

            // Repair only at the outermost exit — nested calls share
            // the same counter and rely on the outer wrapper to fix.
            if ($modelClass::aggregateDeferredDepth() === 0) {
                try {
                    $repairStartNs = hrtime(true);
                    // Repair from the anchor's TREE ROOT, not the anchor's
                    // own subtree. A mutation inside the closure changes
                    // the rollups of every ancestor up to the root, but
                    // fixAggregates($anchor) only bands the anchor's
                    // subtree — so a mid-tree anchor left every ancestor
                    // above it drifted. Resolving to the root repairs the
                    // whole tree the anchor belongs to. (Unscoped forests
                    // that mutate sibling trees inside the closure should
                    // pass a null anchor for a whole-table repair.)
                    $repairResult = $modelClass::fixAggregates(self::resolveTreeRoot($anchor));
                    $repairMs = (hrtime(true) - $repairStartNs) / 1_000_000;
                } catch (\Throwable $secondary) {
                    // If $work itself threw, the repair fires before that
                    // exception propagates — swallow any secondary error
                    // so the original throwable (the actual bug) wins.
                    // But on the SUCCESS path there is no primary
                    // exception: a swallowed repair failure would leave
                    // the batch's drift permanently unrepaired while the
                    // caller returns normally. Rethrow so it surfaces.
                    if (! $closureFailed) {
                        throw $secondary;
                    }
                    error_log(sprintf(
                        'withDeferredAggregateMaintenance: secondary error in fixAggregates after closure failure — %s: %s',
                        $secondary::class,
                        $secondary->getMessage(),
                    ));
                }
            }

            // Only the outermost wrapper emits the boundary event, and
            // only when the user's closure ran without throwing.
            if ($isOutermost && ! $closureFailed) {
                EventDispatcher::dispatch(new DeferredAggregateMaintenanceCompleted(
                    modelClass: $modelClass,
                    anchorId: AggregateAnchor::rootIdOf($anchor),
                    rowsFixed: $repairResult === null ? 0 : $repairResult->totalRowsUpdated,
                    closureDurationMs: $closureMs,
                    repairDurationMs: $repairMs,
                ));
            }
        }
    }

    /**
     * Resolves $anchor to the root of the tree it belongs to, so the
     * deferred repair covers every ancestor whose rollup the closure's
     * mutations may have changed — not just the anchor's own subtree.
     *
     * Structural maintenance is NOT deferred (only aggregates are), so
     * the anchor's lft/rgt are accurate here; the root is the
     * ancestor-or-self with a NULL parent_id in the same scope. Returns
     * the original anchor unchanged when it is already a root, has been
     * deleted, or the root can't be resolved — every fallback preserves
     * the prior (subtree-only) behaviour rather than risking a wrong
     * band.
     */
    private static function resolveTreeRoot(?HasNestedSet $anchor): ?HasNestedSet
    {
        if (! $anchor instanceof Model) {
            return $anchor;
        }

        $key = $anchor->getKey();
        if ($key === null) {
            return $anchor;
        }

        $lftCol = $anchor->getLftName();
        $rgtCol = $anchor->getRgtName();
        $parentCol = $anchor->getParentIdName();
        $keyName = $anchor->getKeyName();

        $fresh = $anchor->getConnection()
            ->table($anchor->getTable())
            ->where($keyName, $key)
            ->first([$lftCol, $rgtCol, $parentCol]);

        if ($fresh === null || $fresh->{$parentCol} === null) {
            return $anchor;
        }

        $scope = NestedSetScopeResolver::valuesFor($anchor);

        $query = $anchor->getConnection()
            ->table($anchor->getTable())
            ->where($lftCol, '<=', $fresh->{$lftCol})
            ->where($rgtCol, '>=', $fresh->{$rgtCol})
            ->whereNull($parentCol);
        foreach ($scope as $column => $value) {
            $query->where($column, '=', $value);
        }

        $rootRow = $query->first([$keyName]);
        if ($rootRow === null) {
            return $anchor;
        }

        $modelClass = $anchor::class;
        $rootAnchor = new $modelClass;
        $rootAnchor->setAttribute($keyName, $rootRow->{$keyName});
        foreach ($scope as $column => $value) {
            $rootAnchor->setAttribute($column, $value);
        }
        $rootAnchor->exists = true;

        return $rootAnchor;
    }

    /**
     * @param  class-string<Model&MaintainsTreeAggregates>  $modelClass
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function queueFixAggregates(
        string $modelClass,
        ?HasNestedSet $anchor,
        ?string $onConnection,
        ?string $onQueue,
        ?int $chunkSize,
    ): FixAggregatesJob {
        // Scope check first so the violation event carries the
        // queue_dispatch stage label — downstream observability filters
        // on this to distinguish queued from synchronous repair faults.
        $scopeColumns = NestedSetScopeResolver::columns($modelClass);
        if ($scopeColumns !== [] && ! $anchor instanceof HasNestedSet) {
            $message = sprintf(
                '%s declares a scope (%s); pass an anchor node to queueFixAggregates() so the job knows which tree to repair.',
                $modelClass,
                implode(', ', $scopeColumns),
            );
            EventDispatcher::dispatch(new ScopeViolationDetected(
                modelClass: $modelClass,
                stage: 'queue_dispatch',
                message: $message,
            ));
            throw new ScopeViolationException($message);
        }

        // Reject unsaved or cross-class anchors at dispatch — a queued
        // job picked up minutes later would silently run unbounded
        // (null anchor id) or target a foreign table. The synchronous
        // fixAggregates path already enforces this; mirror it here.
        AggregateAnchor::writeAnchorOrFail($modelClass, $anchor);

        $job = new FixAggregatesJob(
            modelClass: $modelClass,
            anchorId: AggregateAnchor::rootIdOf($anchor),
            chunkSize: $chunkSize !== null && $chunkSize > 0 ? $chunkSize : null,
        );

        $configConnection = config('nestedset.queue.connection');
        $connection = $onConnection ?? (is_string($configConnection) ? $configConnection : null);
        if ($connection !== null) {
            $job->onConnection($connection);
        }

        $configQueue = config('nestedset.queue.queue');
        $queue = $onQueue ?? (is_string($configQueue) ? $configQueue : null);
        if ($queue !== null) {
            $job->onQueue($queue);
        }

        // Dispatch eagerly via the global helper rather than returning
        // a PendingDispatch — PendingDispatch fires its dispatch in
        // __destruct, which can run after the test framework has torn
        // down the container.
        dispatch($job);

        EventDispatcher::dispatch(new FixAggregatesJobDispatched(
            modelClass: $modelClass,
            anchorId: AggregateAnchor::rootIdOf($anchor),
            chunkSize: $chunkSize !== null && $chunkSize > 0 ? $chunkSize : null,
            onConnection: $connection,
            onQueue: $queue,
        ));

        return $job;
    }
}
