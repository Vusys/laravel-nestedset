# Drift & Limitations

## When aggregates can drift

Aggregate columns are maintained through **Eloquent's event lifecycle**. Anything that mutates the source column without firing those events leaves the stored aggregates out of sync until the next repair pass. This is the same property `counterCache`, observer-driven side effects, and most "computed column" packages have — it's not nestedset-specific.

### What drift looks like

A healthy tree with `articles_total` rolled up:

```ns-tree
Electronics
  Computers
    Laptops {articles=8}
    Desktops {articles=3}
  Phones {articles=12}
```

The widget's `Σ articles` chip on each ancestor matches what `articles_total` holds in the database — `Electronics.articles_total = 23`, `Computers.articles_total = 11`. Now suppose a raw query bypasses the trait:

```php
DB::table('categories')->where('name', 'Laptops')->update(['articles' => 18]);
```

The leaf's source column moved but no `saving` event fired — the package's per-mutation delta UPDATE never ran. The Σ chip below shows what the column **should** read; the `{stale}` chip marks every ancestor whose stored value is now out of sync:

```ns-tree
Electronics {stale}
  Computers {stale}
    Laptops {articles=18}
    Desktops {articles=3}
  Phones {articles=12}
```

`Phones` is unaffected — its subtree didn't include the raw write. The drift is exactly the ancestor chain of the row that was touched without going through Eloquent. `Category::aggregateErrors()` would report `['articles_total' => 2]` (the two rows whose stored value disagrees with a fresh recomputation), and `Category::fixAggregates()` writes the correct values back in one pass.

The two real-world ways this happens:

```php
// 1. Raw query builder bypasses Eloquent entirely.
DB::table('categories')->where('id', 1)->update(['articles' => 99]);

// 2. Bulk INSERT / migration that touches the source column directly.
DB::statement('UPDATE categories SET articles = articles + 1 WHERE rgt < 100');
```

Both modify the source, neither fires `saving` / `saved`, neither triggers ancestor-chain delta UPDATEs. The stored aggregates now disagree with what a fresh recomputation would return.

## Detection and repair

**Detect drift** at any time via the integrity API:

```php
Category::aggregateErrors();      // ['articles_total' => 3, 'articles_count_all' => 0, ...]
Category::aggregatesAreBroken();  // bool
```

**Repair** either synchronously or asynchronously:

```php
// Sync — runs in the current process, returns AggregateFixResult.
$result = Category::fixAggregates();
$result->totalRowsUpdated;     // int — across every aggregate column
$result->perColumn;            // array<string, int> — drift per column
$result->hasDrift();           // bool — true if any row was updated

// Sync + chunked + progress — for CLI commands on large tables.
// $r is an AggregateFixResult for the chunk; $i is the 0-indexed chunk number.
Category::fixAggregates(chunkSize: 1_000, onChunk: function ($r, $i) {
    echo "Chunk {$i}: {$r->totalRowsUpdated} rows\n";
});

// Async — hands the repair to a Laravel queue worker. Self-redispatches
// per chunk; idempotent if run twice.
Category::queueFixAggregates(chunkSize: 1_000);
```

**Scoped models require an anchor.** For multi-tree models declared with `#[NestedSetScope]`, every repair entry point (`aggregateErrors`, `aggregatesAreBroken`, `fixAggregates`, `queueFixAggregates`) takes an `?HasNestedSet $anchor` as its first argument and throws `ScopeViolationException` if you omit it — repair stays inside a single tree, never walks the whole partitioned table.

```php
MenuItem::fixAggregates($menuRoot);
MenuItem::queueFixAggregates($menuRoot, chunkSize: 1_000);
```

**Recommended mitigation pattern for workloads that mix Eloquent and raw SQL writes:** schedule a defensive repair on a cron interval that matches your drift tolerance. The chunked queue path makes this safe even on multi-million-row tables:

```php
// app/Console/Kernel.php
$schedule->call(fn () => Category::queueFixAggregates(chunkSize: 1_000))
    ->hourly();
```

The job is idempotent — running it against a clean tree finds zero drift and writes nothing. Safe to fire defensively.

## Limitations and footguns

### Soft-delete cascade preserves stored aggregates

The soft-deleted subtree keeps its own rolled-up values; the ancestor chain is decremented. `restored` re-adds.

### `replicate()` resets aggregate columns

Clones reset every aggregate column to the function's empty element. Count-shaped kinds (`Sum`, `Count`, `DistinctCount`) reset to `0`; every other kind — `Avg`, `Min`, `Max`, `Variance`, `Stddev`, `WeightedAvg`, `BoolOr`, `BoolAnd`, `GeometricMean`, `HarmonicMean`, `BitOr`, `BitAnd`, `BitXor`, `StringAgg`, `JsonAgg`, `JsonObjectAgg` — resets to `NULL`. (The behaviour delegates to `AggregateFunction::nullableOnEmpty()`, so the partition matches the storage `nullable` flag exactly.) The clone backfills correctly on placement.

### Unplaced nodes skip aggregate maintenance

Saving a node that hasn't been placed in the tree — `Category::create(...)` or `->save()` without a preceding `appendToNode()` / `makeRoot()` — now throws `UnplacedNodeException`, so a node with `lft = rgt = 0` is no longer a state you can reach through the normal API. Unplaced rows only arise from raw SQL inserts that bypass the model; aggregate maintenance is skipped for them until they're placed. Check the state with `$node->isPlacedInTree(): bool` — returns false when both `lft` and `rgt` are still the migration default.

### AVG over a nullable source

`avg: 'col'` uses `AVG(col)`, which skips NULL rows. If the source is nullable, the auto-promoted COUNT companion uses `COUNT(col)` (which also skips NULLs — i.e. counts only non-NULL rows) so the ratio stays consistent.

### MIN/MAX recompute cost

Deletes and source-decreasing updates that invalidate the stored extremum trigger a SELECT-then-UPDATE recompute. Cheap-skipped when the change couldn't have affected the extremum — but if you have a deep, wide tree with hot MIN/MAX columns, expect occasional spikes. The SELECT-then-UPDATE concurrency behaviour is governed by [`aggregate_locking`](../reference/config.html#aggregate_locking) — default `'auto'` adds a row-level lock on backends that need it, the cost of which scales with subtree size.

See `tests/Feature/Aggregates/` for executable examples of every maintenance path.
