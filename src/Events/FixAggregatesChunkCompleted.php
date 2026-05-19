<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

/**
 * Fires once per chunk during a chunked `fixAggregates` run — both
 * the synchronous chunked loop and the queued self-redispatching job
 * emit this. Lets users stream progress to logs/metrics without
 * writing their own `onChunk` callback.
 */
final readonly class FixAggregatesChunkCompleted
{
    public function __construct(
        public string $modelClass,
        public int|string|null $anchorId,
        public int $chunkIndex,
        public int $chunkSize,
        public int $rowsUpdated,
        /** Last id processed in this chunk; null on the chunk that finishes the loop. */
        public int|string|null $cursorAfter,
        public float $durationMs,
    ) {}
}
