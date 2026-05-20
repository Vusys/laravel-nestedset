# Production Notes

## Routing fresh-aggregate reads to a read replica

`withFreshAggregates()` runs an aggregation per outer row — on a balanced-fanout tree at N=10K it's the most expensive read the package emits. If you have read replicas, route these reads off the primary:

```php
Category::query()
    ->withFreshAggregates()
    ->useReadPdo()        // ← stays on Laravel's read connection
    ->get();
```

Caveat: Eloquent automatically routes any query inside an open transaction to the **write** PDO regardless of `useReadPdo()`, to avoid replication-lag visibility issues. If you wrap the read in a transaction (or call it from inside one), it lands on the primary anyway. For genuine replica routing, the read needs to live outside a transaction boundary.

Pair with the `nestedset.aggregate_locking` config flag — `'never'` is safe on a read-only path; the locking modes only matter for the write path.

## MariaDB: disabling `split_materialized`

The fresh-aggregate read path uses a derived-table JOIN on MariaDB so the subquery is materialised once per outer query rather than once per row. MariaDB's optimizer can convert that derived JOIN into a LATERAL DERIVED via `split_materialized`, which collapses the materialise-once advantage and runs ~3× slower in practice. `withMariaDbSplitMaterializedOff()` prepends a `SET STATEMENT optimizer_switch='split_materialized=off' FOR …` to the next compiled SQL — scoped to the one statement, no session-state mutation:

```php
Category::query()
    ->withFreshAggregates()
    ->withMariaDbSplitMaterializedOff()
    ->get();
```

No-op on MySQL/PostgreSQL/SQLite — the `SET STATEMENT` prefix is MariaDB-specific syntax. Only reach for it if profiling shows the fresh-aggregate path running unexpectedly slow on MariaDB.

## Telemetry

The package fires typed events on Laravel's event bus around its meaningful operations. Listen via standard `Event::listen()` to wire metrics (Datadog, New Relic, OpenTelemetry), errors (Sentry, Bugsnag), or audit logs.

Events come in two flavours:

- **Telemetry events** carry only scalar / array payloads (model class, ids, durations, counts). Safe to listen on with `ShouldQueue`.
- **Model-carrying events** carry live Eloquent model instances and/or descendant-id lists for in-process decoration, indexing, cache priming. Not queue-safe by default — see the per-event docblock for the recommended capture-and-forward pattern.

Events (all in `Vusys\NestedSet\Events\`):

| Event | Fires when | Carries models? |
|---|---|---|
| `FixTreeCompleted` | `Model::fixTree()` finishes | — |
| `FixAggregatesCompleted` | `Model::fixAggregates()` finishes (sync, single-shot or chunked) | — |
| `FixAggregatesChunkCompleted` | per chunk in sync chunked + per dispatch in queued chunked | — |
| `FixAggregatesJobDispatched` | `Model::queueFixAggregates()` hands a job to the dispatcher | — |
| `BulkInsertTreeStarting` | top of `Model::bulkInsertTree()` before plan walk | raw tree + appendTo anchor |
| `BulkInsertTreePlanned` | after the DFS plan walk, before save loop | plan array + appendTo anchor |
| `BulkInsertNodeSaved` | once per row inside the save loop | saved model + parent reference |
| `BulkInsertTreeSaved` | after all rows saved (before the closing fixAggregates) | `list<HasNestedSet>` of every saved model |
| `BulkInsertTreeCompleted` | `Model::bulkInsertTree()` finishes | nodeIds only (queue-safe) |
| `SubtreeSoftDeleting` / `SubtreeSoftDeleted` | bracketed around the cascade UPDATE that propagates `deleted_at` to descendants | anchor + descendant ids on the `…Deleted` side |
| `SubtreeRestoring` / `SubtreeRestored` | bracketed around the cascade UPDATE that clears `deleted_at` on matching descendants | anchor + descendant ids on the `…Restored` side |
| `SubtreeForceDeleting` / `SubtreeForceDeleted` | bracketed around the cascade DELETE of strict descendants on `forceDelete()` of an interior node | anchor + descendant ids |
| `SoftDeleteMarkerCaptured` | inside `restoring` lifecycle, when the package captures the marker that will be matched on restore | anchor |
| `NodeMoved` | structural mutation of an *existing* node (appendToNode, makeRoot, etc.) — new-node placements use Eloquent's `created` instead | — |
| `SubtreeMoving` / `SubtreeMoved` | bracketed around the structural SQL for an existing-node mutation; carries descendant ids so listeners get the full subtree, not just the anchor | anchor + descendant ids on `…Moved` |
| `NodesSwapped` | `up()` / `down()` sibling swap completes; frames the two `NodeMoved` events as one logical operation | both participants |
| `NodePromotedToRoot` | `makeRoot()` on an existing node | anchor |
| `NodeAggregatesRecomputed` | once per aggregate-maintenance lifecycle hook (create / delete / restore / move), when the model declares aggregates | — |
| `AggregateDriftDetected` | `aggregateErrors()` finds at least one column with non-zero drift | — |
| `TreeIntegrityChecked` | every `isBroken()` / `countErrors()` call, regardless of result | — |
| `DeferredMaintenanceStarting` | outermost entry of `withDeferredAggregateMaintenance()` | — |
| `DeferredAggregateMaintenanceCompleted` | outermost exit of `withDeferredAggregateMaintenance()` after the closing repair | — |
| `ScopeViolationDetected` | immediately before a `ScopeViolationException` is thrown | — |
| `AggregateMaintenanceFailed` | exception escapes one of the trait's aggregate-maintenance hooks — propagates the original, but lets observers see the failure | — |

Model-carrying events do extra work (a bounds SELECT for descendant
ids on cascades and moves) **only when a real listener is registered**
for the event. The check goes through `Event::hasListeners()`, so the
hot path stays clean for callers that don't subscribe.

### Example wirings

```php
use Vusys\NestedSet\Events\FixAggregatesCompleted;
use Vusys\NestedSet\Events\FixAggregatesChunkCompleted;
use Vusys\NestedSet\Events\AggregateMaintenanceFailed;

// Datadog histogram for repair latency
Event::listen(FixAggregatesCompleted::class, function (FixAggregatesCompleted $e): void {
    Datadog::histogram('nestedset.fix_aggregates.duration_ms', $e->durationMs, [
        'model' => $e->modelClass,
        'rows' => $e->totalRowsUpdated,
        'chunks' => $e->totalChunks,
    ]);
});

// Streaming progress to logs for long-running chunked repairs
Event::listen(FixAggregatesChunkCompleted::class, function (FixAggregatesChunkCompleted $e): void {
    Log::info("nestedset chunk {$e->chunkIndex}: {$e->rowsUpdated} rows in {$e->durationMs}ms");
});

// Sentry for hook failures
Event::listen(AggregateMaintenanceFailed::class, function (AggregateMaintenanceFailed $e): void {
    Sentry::captureException($e->exception, [
        'tags' => ['nestedset_stage' => $e->stage, 'nestedset_model' => $e->modelClass],
    ]);
});
```

Telemetry events are simple readonly value objects with scalar / array fields, so queued listeners (`ShouldQueue`) are safe. Two exceptions require synchronous capture-and-forward if you want to queue work:

- `AggregateMaintenanceFailed::$exception` is a `Throwable` and won't serialise cleanly across most queue drivers.
- Model-carrying events (`BulkInsertTreeStarting`, `BulkInsertTreePlanned`, `BulkInsertNodeSaved`, `BulkInsertTreeSaved`, `Subtree*`, `NodesSwapped`, `NodePromotedToRoot`, `SoftDeleteMarkerCaptured`) hold live Eloquent instances. Capture the fields you care about (ids, bounds, scope) synchronously and forward those.

To disable every firing site (e.g. in a very-hot path), set `nestedset.events_enabled => false` in the published config. Default is `true`.
