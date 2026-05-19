<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Vusys\NestedSet\Concerns\HasNestedSetAggregates;

/**
 * Fires once when
 * {@see HasNestedSetAggregates::fixAggregates()}
 * completes its full work. For the chunked path this is the
 * end-of-loop summary; the per-chunk events are emitted as
 * {@see FixAggregatesChunkCompleted} during the run.
 */
final readonly class FixAggregatesCompleted
{
    /**
     * @param  array<string, int>  $perColumn  Per-user-facing-column drift counts.
     */
    public function __construct(
        public string $modelClass,
        public int|string|null $anchorId,
        public int $totalRowsUpdated,
        public array $perColumn,
        public float $durationMs,
        /** Null on the non-chunked path. */
        public ?int $chunkSize,
        /** 1 on the non-chunked path; otherwise the number of chunks the loop produced. */
        public int $totalChunks,
    ) {}
}
