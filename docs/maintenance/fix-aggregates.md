# Repairing Aggregates

`fixAggregates()` is the recompute-from-source pass that restores stored aggregate columns to the values a fresh recomputation would yield. It's the dual of [drift](../aggregates/drift.html): drift describes what goes wrong, `fixAggregates` describes how to put it right.

## What the repair does

Before — a tree where `cost_total` has drifted on the ancestor chain (a raw `UPDATE` to `Bonuses.cost` skipped the trait):

```ns-tree
Engineering {stale}
  Salaries {cost=26000}
  Bonuses {cost=4000}
  Tools {cost=1500}
```

Calling:

```php
Category::fixAggregates();
```

…walks the tree from `parent_id`, recomputes every aggregate column from the source data, and writes back any row whose stored value disagrees. After the call, the stored columns match the Σ on every row, and the `{stale}` chip drops away:

```ns-tree
Engineering
  Salaries {cost=26000}
  Bonuses {cost=4000}
  Tools {cost=1500}
```

The returned `AggregateFixResult` reports `totalRowsUpdated = 1` (just `Engineering` — `Salaries`, `Bonuses`, `Tools` were already correct; they're leaves with no descendants to roll up over). Running the same call a second time finds zero drift and writes nothing — `fixAggregates()` is idempotent, which is why it's safe to schedule defensively.

`fixAggregates()` is fast on most trees but a heavily-drifted 1M-row table still measures in tens of seconds — not the kind of work you want on the synchronous response path. `queueFixAggregates()` hands it to a worker instead:

```php
// Fire and forget — uses Laravel's default queue connection / queue name.
Category::queueFixAggregates();

// Scoped models: anchor required (same rule as the sync method).
MenuItem::queueFixAggregates($anchor);

// Per-call routing overrides (also configurable globally — see below).
Category::queueFixAggregates(onConnection: 'redis', onQueue: 'aggregates-low');
```

Defaults come from `config/nestedset.php`:

```php
'queue' => [
    'connection' => env('NESTEDSET_QUEUE_CONNECTION'),  // null → default connection
    'queue' => env('NESTEDSET_QUEUE'),                   // null → default queue
],
```

The dispatched `Vusys\NestedSet\Jobs\FixAggregatesJob` carries the model class and an optional anchor id; its `handle()` just calls the same `Model::fixAggregates($anchor)` you'd call synchronously, so it inherits every Phase K+ optimisation automatically. The job is **idempotent** — a second run on a clean tree finds zero drift and writes nothing — so dispatching defensively after a batch operation is safe.

## Chunked self-redispatch

For very large trees where even a single repair job would exceed your queue's per-job time budget, pass a `chunkSize` and the job will process one bounded slice and re-dispatch itself with an advanced cursor until the table is covered:

```php
// Process 1,000 outer rows per dispatch. The job re-queues itself
// (on the same connection/queue) after each chunk until done.
Category::queueFixAggregates(chunkSize: 1_000);
```

Each chunk runs one chunked `fixAggregates` constrained to its outer-id slice, so total work scales linearly in `chunkSize` regardless of total table size. The chain terminates automatically when a chunk returns fewer rows than `chunkSize` — no completion handler to register, no manual cursor to track. Combine with a smaller chunk size to keep individual jobs well under your worker's `--timeout`.

## Deferred maintenance for batch mutations

If you're doing many small mutations through Eloquent — a CSV import, a re-parenting script, a re-numbering migration — every save normally triggers a per-row aggregate update on the ancestor chain. For N saves that's N × ancestor-chain UPDATEs. `withDeferredAggregateMaintenance()` suspends those side-effects for the duration of a closure and fires one `fixAggregates()` at the end:

```php
Category::withDeferredAggregateMaintenance(function () use ($csv, $parent) {
    foreach ($csv as $row) {
        $category = new Category($row);
        $category->appendToNode($parent)->save();  // saving/created/saved fire,
    }                                              // aggregate side-effects deferred
}, $rootAnchor);                                   // one fixAggregates($root) at the end
```

Signature:

```php
public static function withDeferredAggregateMaintenance(
    Closure $work,
    ?HasNestedSet $anchor = null,
): mixed
```

`$anchor` is the node passed to the closing `fixAggregates($anchor)` call. **Scoped models require it** — calling without an anchor throws `ScopeViolationException` synchronously (before the closure runs), same rule as direct `fixAggregates()`. Unscoped models can pass `null` and the repair covers the whole tree.

The wrapper returns whatever the closure returns — `mixed`, generically typed (`@template T` → `T`) so the inferred return type matches the closure body.

### What still fires inside the closure

- Every Eloquent event (`saving` / `created` / `saved` / `deleted` / `restoring` / `restored`)
- Mutators, casts, mass-assignment guards, observers — exactly as they would outside the block

### What's deferred

- The trait's per-row aggregate-column updates on the ancestor chain (`articles_total`, `articles_count_all`, etc.)
- All the MIN/MAX recompute and AVG companion writes that normally piggy-back on each save

### Re-entrant

Nested calls share one counter and only the outermost call triggers the final repair — fine to wrap a higher-level batch around a callee that already defers.

### Failure-safe

If the closure throws, the counter still decrements and `fixAggregates()` still fires before the exception propagates — leaving the table half-repaired would be worse than spending the fix cost. A secondary error inside the repair is logged via `error_log` (so the primary exception wins) and the caller is responsible for re-running `fixAggregates()` once they've handled the primary failure.

### Observability

The outermost call dispatches `DeferredMaintenanceStarting` on entry and `DeferredAggregateMaintenanceCompleted` on a successful exit (carrying `rowsFixed`, `closureDurationMs`, `repairDurationMs`). A throw inside the closure skips the completion event — that signal is reserved for batches that ran to completion, so listeners can use it as a "batch boundary" marker. The package's own `bulkInsertTree()` uses this same wrapper internally — see the [Bulk Insertion](../tree-operations/bulk-insertion.html) docs for that integration.

Trade-off: this trades N small ancestor UPDATEs for one all-at-once repair pass. The repair touches every row whose stored aggregates may have drifted, so it's worth it when N is large (CSV imports, scripts, fixture seeding) and a poor fit for one-or-two saves where the per-row update is already cheap.

## Sync chunked repair with progress

When you'd rather drive the loop yourself — e.g. a CLI command streaming progress to stdout — pass the same `chunkSize` to the synchronous `fixAggregates()` plus an `onChunk` callback:

```php
$result = Category::fixAggregates(
    chunkSize: 1_000,
    onChunk: function ($chunkResult, int $chunkIndex, ?int $cursor) {
        $this->output->writeln(sprintf(
            'Chunk %d: %d rows updated (cursor=%s)',
            $chunkIndex,
            $chunkResult->totalRowsUpdated,
            $cursor ?? 'end',
        ));
    },
);

// $result is the merged total across every chunk.
```

The callback receives the per-chunk `AggregateFixResult`, the zero-based chunk index, and the cursor (last id processed, or `null` on the final chunk). Each chunk is independently atomic at the database level — if the process is killed mid-loop you can re-run and the remaining drift will be detected and repaired on the next pass.
