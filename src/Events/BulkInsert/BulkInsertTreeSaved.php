<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\BulkInsert;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Concerns\HasBulkInsert;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Fires once inside {@see HasBulkInsert::bulkInsertTree()} after
 * every row has been saved, the transaction has committed, AND the
 * closing `fixAggregates($anchor)` pass has run — so stored aggregate
 * columns in the database are fully rolled up by the time listeners
 * see this event.
 *
 * Carries the full list of saved models — id-populated, bounds set,
 * scope columns copied. The two main use cases this is built for:
 *
 *  - bulk-indexing the freshly-imported subtree into an external
 *    store (Algolia, Meilisearch, Elasticsearch, …) in one shot,
 *    where N individual `created` listeners would be N round-trips;
 *  - decoration / cache priming where you want every saved node
 *    in one logical batch instead of N independent callbacks.
 *
 * Important: the in-memory `$nodes` array was captured during the
 * per-row save loop, *before* `fixAggregates` ran. Aggregate columns
 * on those instances reflect their pre-roll-up state, not the final
 * DB values. Call `->fresh()` on a node (or re-query) to read the
 * rolled-up totals, or listen for {@see BulkInsertTreeCompleted}
 * which fires immediately after with `$nodeIds` for re-querying.
 *
 * Not queue-safe — carries live model instances.
 */
final readonly class BulkInsertTreeSaved
{
    /**
     * @param  list<Model&HasNestedSet>  $nodes  every saved model, in DFS pre-order
     */
    public function __construct(
        public string $modelClass,
        public int|string|null $anchorId,
        public (Model&HasNestedSet)|null $appendTo,
        public array $nodes,
    ) {}
}
