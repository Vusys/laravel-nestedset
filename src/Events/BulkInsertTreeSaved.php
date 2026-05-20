<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Concerns\HasBulkInsert;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Fires once inside {@see HasBulkInsert::bulkInsertTree()} after
 * every row has been saved and the transaction has committed, but
 * **before** the closing `fixAggregates($anchor)` pass runs.
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
 * Stored aggregate columns on the saved models may NOT yet reflect
 * the rolled-up totals at this point — the closing fixAggregates
 * pass runs immediately after. If you need final aggregates, listen
 * for the matching {@see BulkInsertTreeCompleted} instead and
 * refresh the models you care about, or listen for both events and
 * combine.
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
