<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\Aggregates;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vusys\NestedSet\Events\EventDispatcher;

/**
 * Per-row, per-column change-feed event for maintained aggregate
 * columns. Fires once for every (ancestor row, aggregate column)
 * pair whose stored value moved during an aggregate-maintenance
 * pass — i.e. one event per CDC-style row change.
 *
 * Pairs with the broader {@see NodeAggregatesRecomputed} (which
 * fires once per lifecycle hook naming every declared aggregate
 * column on the model). Use this one to mirror aggregate values
 * to an external store (Redis, Kafka, Reverb, search index)
 * without polling. Use {@see NodeAggregatesRecomputed} for coarse
 * cache invalidation where per-row granularity is overkill.
 *
 * Opt-in by listener presence: the firing site short-circuits via
 * {@see EventDispatcher::hasListeners()} when no listener is
 * registered, so the package pays no SELECT cost for users who
 * don't want the feed. When at least one listener is attached,
 * each maintenance pass issues one extra `SELECT id, lft, col…`
 * over the targeted ancestor chain before and after the UPDATE
 * to capture old/new values.
 *
 * Internal companion columns (auto-promoted alongside AVG /
 * Variance / WeightedAvg etc.) are NOT included — events only
 * fire for user-declared aggregate columns.
 *
 * Queue-safe: payload is scalar.
 */
final readonly class NestedSetAggregateChanged implements ShouldDispatchAfterCommit
{
    /**
     * @param  int|float|bool|string|null  $oldValue  raw value read from the database before the
     *                                                maintenance UPDATE. Numeric aggregate columns
     *                                                may come back as strings on some PDO drivers
     *                                                (e.g. PostgreSQL DECIMAL); the listener can
     *                                                cast as needed.
     * @param  int|float|bool|string|null  $newValue  raw value read after the UPDATE.
     * @param  list<int|string>  $ancestorChain  ids of every row whose aggregate columns were
     *                                           targeted by this maintenance pass, ordered
     *                                           deepest-first (the triggering node or its
     *                                           closest ancestor at index 0, root last).
     *                                           Identical across every event emitted by the
     *                                           same mutation — consumers can coalesce on it.
     */
    public function __construct(
        public string $modelClass,
        public int|string $nodeId,
        public string $column,
        public int|float|bool|string|null $oldValue,
        public int|float|bool|string|null $newValue,
        public array $ancestorChain,
        /** One of 'on_create', 'on_update', 'on_delete', 'move', 'on_restore'. */
        public string $stage,
    ) {}
}
