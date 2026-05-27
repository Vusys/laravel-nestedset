<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\BulkInsert;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasBulkInsert;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Fires once at the top of {@see HasBulkInsert::bulkInsertTree()},
 * before the in-memory plan walk and before any SQL runs.
 *
 * Carries the raw nested input array so listeners can inspect /
 * audit what's about to be imported (e.g. log "user X is about to
 * import a tree of N nodes" or attach feature-flag context). The
 * input is still mutable from the application's perspective — the
 * package has not yet consumed it — but mutations made to the
 * array referenced by the event do NOT affect the import because
 * PHP passes the array by value into the event constructor.
 *
 * Not queue-safe by default: the raw input can contain anything
 * the application chose to seed with (Stringables, value objects,
 * etc.) and may not serialise cleanly.
 */
final readonly class BulkInsertTreeStarting
{
    /**
     * @param  list<array<string, mixed>>  $tree  the raw nested input as the caller passed it
     */
    public function __construct(
        public string $modelClass,
        public (Model&HasNestedSet)|null $appendTo,
        public array $tree,
    ) {}
}
