<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\BulkInsert;

use Vusys\NestedSet\Concerns\HasBulkInsert;

/**
 * Fires once when
 * {@see HasBulkInsert::bulkInsertTree()}
 * finishes — after every row has been saved and the closing
 * `fixAggregates` has run.
 *
 * Queue-safe: payload is scalars + a list of ids. For listeners
 * that want the actual saved model instances (decorating, indexing
 * in-process) listen for {@see BulkInsertTreeSaved} instead.
 */
final readonly class BulkInsertTreeCompleted
{
    /**
     * @param  list<int|string>  $nodeIds  primary keys of every saved row, in DFS pre-order
     */
    public function __construct(
        public string $modelClass,
        public int|string|null $anchorId,
        public int $rowsInserted,
        public float $durationMs,
        public array $nodeIds = [],
    ) {}
}
