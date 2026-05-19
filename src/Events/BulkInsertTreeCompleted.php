<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Vusys\NestedSet\Concerns\HasBulkInsert;

/**
 * Fires once when
 * {@see HasBulkInsert::bulkInsertTree()}
 * finishes — after every row has been saved and the closing
 * `fixAggregates` has run.
 */
final readonly class BulkInsertTreeCompleted
{
    public function __construct(
        public string $modelClass,
        public int|string|null $anchorId,
        public int $rowsInserted,
        public float $durationMs,
    ) {}
}
