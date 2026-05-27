<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\Aggregates\FixAggregatesChunkCompleted;
use Vusys\NestedSet\Events\EventDispatcher;

/**
 * Phase N: queued repair of stored aggregate columns.
 *
 * Carries the model class name and an optional anchor id rather than a
 * serialized model instance — anchors can be deleted between dispatch
 * and execution, and re-querying gives us a clear "anchor missing"
 * exception instead of a half-hydrated SerializesModels failure.
 *
 * The handler just calls `Model::fixAggregates($anchor)` — the heavy
 * lifting (drift detection, chunked CASE-WHEN UPDATE) lives in the
 * package's shared aggregate builder, so this job benefits from every
 * Phase K+ / Q optimization automatically.
 *
 * Idempotent: a second run on an already-clean tree finds zero drift
 * and updates nothing. Safe to dispatch defensively.
 */
final class FixAggregatesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @param  int|null  $chunkSize  When > 0, process up to this many
     *                               outer rows per dispatch and
     *                               re-queue this job with an advanced
     *                               cursor until the table is covered.
     *                               null/0 disables chunking — the
     *                               whole repair runs in one job (the
     *                               original Phase N behaviour).
     * @param  int|string|null  $cursorAfterId  Internal: process rows whose id
     *                                          is strictly greater than this.
     *                                          Set by the re-dispatch path; not
     *                                          typically passed by callers.
     */
    public function __construct(
        public readonly string $modelClass,
        public readonly int|string|null $anchorId = null,
        public readonly ?int $chunkSize = null,
        public readonly int|string|null $cursorAfterId = null,
        /**
         * Index of this chunk within the chain, starting at 0 for the
         * first dispatch and incremented per self-redispatch. Carried
         * through the chain so {@see FixAggregatesChunkCompleted}
         * events on the queue path have a consistent ordering.
         */
        public readonly int $chunkIndex = 0,
    ) {}

    public function handle(): AggregateFixResult
    {
        $modelClass = $this->modelClass;

        $anchor = null;
        if ($this->anchorId !== null) {
            /** @var Model|null $instance */
            $instance = $modelClass::query()->find($this->anchorId);
            if (! $instance instanceof HasNestedSet) {
                throw new \RuntimeException(sprintf(
                    'FixAggregatesJob: anchor id %s not found on %s — the row was deleted between dispatch and execution.',
                    (string) $this->anchorId,
                    $modelClass,
                ));
            }
            $anchor = $instance;
        }

        // `fixAggregates` is declared on the NodeTrait (always present
        // on a HasNestedSet model in practice), not on the contract —
        // so the call site needs runtime narrowing rather than a static
        // type guarantee. method_exists makes the callable concrete
        // enough for PHPStan; the instanceof check on the return value
        // closes the loop.
        if (! method_exists($modelClass, 'fixAggregates')) {
            throw new \RuntimeException(sprintf(
                'FixAggregatesJob: %s has no static fixAggregates() method — does it use NodeTrait?',
                $modelClass,
            ));
        }

        if ($this->chunkSize !== null && $this->chunkSize > 0) {
            return $this->handleChunked($modelClass, $anchor);
        }

        $result = $modelClass::fixAggregates($anchor);

        if (! $result instanceof AggregateFixResult) {
            throw new \RuntimeException(sprintf(
                'FixAggregatesJob: %s::fixAggregates() returned %s; expected AggregateFixResult.',
                $modelClass,
                get_debug_type($result),
            ));
        }

        return $result;
    }

    /**
     * Chunked path: process one slice and (if more remains) re-dispatch
     * this same job with an advanced cursor. The total work is the same
     * as the single-shot path, but each individual job runs in bounded
     * time — friendlier for queue workers with timeouts and easier to
     * observe via `php artisan queue:work` output.
     */
    private function handleChunked(string $modelClass, ?HasNestedSet $anchor): AggregateFixResult
    {
        if (! method_exists($modelClass, 'fixAggregatesChunk')) {
            throw new \RuntimeException(sprintf(
                'FixAggregatesJob: %s has no static fixAggregatesChunk() method — does it use NodeTrait?',
                $modelClass,
            ));
        }

        $startNs = hrtime(true);
        /** @var array{result: AggregateFixResult, nextAfterId: int|string|null} $chunk */
        $chunk = $modelClass::fixAggregatesChunk($anchor, $this->cursorAfterId, $this->chunkSize ?? 0);
        $durationMs = (hrtime(true) - $startNs) / 1_000_000;

        EventDispatcher::dispatch(new FixAggregatesChunkCompleted(
            modelClass: $this->modelClass,
            anchorId: $this->anchorId,
            chunkIndex: $this->chunkIndex,
            chunkSize: $this->chunkSize ?? 0,
            rowsUpdated: $chunk['result']->totalRowsUpdated,
            cursorAfter: $chunk['nextAfterId'],
            durationMs: $durationMs,
        ));

        if ($chunk['nextAfterId'] !== null) {
            // More chunks remain — schedule the next one. Inherit the
            // same queue/connection routing as this job so the chain
            // stays on the configured worker.
            $next = new self(
                modelClass: $this->modelClass,
                anchorId: $this->anchorId,
                chunkSize: $this->chunkSize,
                cursorAfterId: $chunk['nextAfterId'],
                chunkIndex: $this->chunkIndex + 1,
            );
            if (is_string($this->connection)) {
                $next->onConnection($this->connection);
            }
            if (is_string($this->queue)) {
                $next->onQueue($this->queue);
            }
            dispatch($next);
        }

        return $chunk['result'];
    }

    /**
     * A stable, human-readable identifier for this job. Used by some
     * queue backends as the de-duplication key and by `php artisan
     * queue:monitor` output. Including the model class + anchor id
     * makes "two repairs for the same tree" easy to spot.
     */
    public function displayName(): string
    {
        return sprintf(
            'fixAggregates(%s%s)',
            $this->modelClass,
            $this->anchorId === null ? '' : "#{$this->anchorId}",
        );
    }
}
