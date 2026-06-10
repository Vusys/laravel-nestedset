<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Repair;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
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
        // at the repair pass.
        AggregateAnchor::writeAnchorOrFail($modelClass, $anchor);

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
                    $repairResult = $modelClass::fixAggregates($anchor);
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
