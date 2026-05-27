<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\Aggregates;

use Vusys\NestedSet\Concerns\HasNestedSetAggregates;

/**
 * Fires once per Eloquent lifecycle hook (create, delete, restore,
 * move) when an aggregate-maintenance pass has just run for the
 * node. The triggering node's id is the focal point; the ancestor
 * chain whose aggregate columns were touched can be derived from
 * the node's current/previous bounds.
 *
 * Applications that cache derived aggregate values (denormalised
 * roll-ups, dashboard widgets, expensive reports) can use this as
 * a precise cache-invalidation signal: invalidate the cached
 * rollups for the node's ancestor chain instead of invalidating
 * everything on every mutation.
 *
 * Does NOT fire when no aggregate columns are declared on the
 * model, or when maintenance is deferred via
 * {@see HasNestedSetAggregates::withDeferredAggregateMaintenance()}
 * (the closing repair fires {@see DeferredAggregateMaintenanceCompleted}
 * instead).
 *
 * Queue-safe: payload is scalar.
 */
final readonly class NodeAggregatesRecomputed
{
    /**
     * @param  list<string>  $columns  user-facing aggregate columns declared on the model
     */
    public function __construct(
        public string $modelClass,
        public int|string $nodeId,
        public array $columns,
        /** One of 'on_create', 'on_delete', 'on_restore', 'move'. */
        public string $stage,
    ) {}
}
