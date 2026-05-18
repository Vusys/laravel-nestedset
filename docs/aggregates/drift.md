# Drift & Limitations

## When aggregates can drift

Aggregate columns are maintained through **Eloquent's event lifecycle**.
Anything that mutates the source column without firing those events
leaves the stored aggregates out of sync until the next repair pass.
This is the same property `counterCache`, observer-driven side
effects, and most "computed column" packages have — it's not
nestedset-specific.

The two real-world ways this happens:

```php
// 1. Raw query builder bypasses Eloquent entirely.
DB::table('areas')->where('id', 1)->update(['tickets' => 99]);

// 2. Bulk INSERT / migration that touches the source column directly.
DB::statement('UPDATE areas SET tickets = tickets + 1 WHERE rgt < 100');
```

Both modify the source, neither fires `saving` / `saved`, neither
triggers ancestor-chain delta UPDATEs. The stored aggregates now
disagree with what a fresh recomputation would return.

## Detection and repair

**Detect drift** at any time via the integrity API:

```php
Area::aggregateErrors();      // ['tickets_total' => 3, 'tickets_count_all' => 0, ...]
Area::aggregatesAreBroken();  // bool
```

**Repair** either synchronously or asynchronously:

```php
// Sync — runs in the current process, returns when done.
Area::fixAggregates();

// Sync + chunked + progress — for CLI commands on large tables.
Area::fixAggregates(chunkSize: 1_000, onChunk: function ($r, $i) {
    echo "Chunk {$i}: {$r->totalRowsUpdated} rows\n";
});

// Async — hands the repair to a Laravel queue worker. Self-redispatches
// per chunk; idempotent if run twice.
Area::queueFixAggregates(chunkSize: 1_000);
```

**Recommended mitigation pattern for workloads that mix Eloquent and
raw SQL writes:** schedule a defensive repair on a cron interval that
matches your drift tolerance. The chunked queue path makes this safe
even on multi-million-row tables:

```php
// app/Console/Kernel.php
$schedule->call(fn () => Area::queueFixAggregates(chunkSize: 1_000))
    ->hourly();
```

The job is idempotent — running it against a clean tree finds zero
drift and writes nothing. Safe to fire defensively.

## Limitations and footguns

- **Soft-delete cascade preserves stored aggregates on the soft-deleted
  subtree;** ancestor chain is decremented. `restored` re-adds.
- **`replicate()` clones reset every aggregate column** to the
  function's empty element (0 for SUM/COUNT, NULL for AVG/MIN/MAX).
  The clone backfills correctly on placement.
- **Plain `Area::create(...)` without `appendToNode()` / `makeRoot()`**
  leaves the row unplaced (`lft = rgt = 0`); aggregate maintenance is
  skipped until the node is placed in the tree. Check the state with
  `$node->isPlacedInTree(): bool` — returns false when both `lft` and
  `rgt` are still the migration default.
- **AVG over a nullable source.** `avg: 'col'` uses `AVG(col)` which
  skips NULL rows. If the source is nullable, the auto-promoted COUNT
  companion uses `COUNT(col)` (also non-null-skipping) so the ratio
  stays consistent.
- **MIN/MAX recompute cost.** Deletes and source-decreasing updates
  that invalidate the stored extremum trigger a SELECT-then-UPDATE
  recompute. Cheap-skipped when the change couldn't have affected the
  extremum — but if you have a deep, wide tree with hot MIN/MAX
  columns, expect occasional spikes.

See `tests/Feature/Aggregates/` for executable examples of every
maintenance path.
