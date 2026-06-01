# Lazy Aggregates

Stored aggregate columns are normally **eager**: every insert, update, delete, and move recomputes the affected ancestor chain in the same transaction as the mutation. That keeps reads cheap (the column is already on the row) but pays the maintenance cost on every write.

A **lazy** aggregate flips that trade. Writes only *invalidate* the cached value (one fast `UPDATE … SET col = NULL, col_computed_at = NULL` over the ancestor chain); the first read past the invalidation recomputes the value with the same correlated SQL as `withFreshAggregates()`, writes it back, and stamps `<column>_computed_at`. Subsequent reads return the stored value with no extra work.

Use lazy aggregates when writes are bursty and reads on the affected subtree are sparse — typical in batch import paths, scheduled rebuilds, and admin tools.

## Declaring a lazy column

```php
use Vusys\NestedSet\Attributes\NestedSetAggregate;

#[NestedSetAggregate(column: 'articles_total', sum: 'articles', lazy: true)]
#[NestedSetAggregate(column: 'articles_count', count: true, lazy: true)]
class Category extends Model implements HasNestedSet
{
    use NodeTrait;
}
```

`lazy: true` works on the SQL kinds whose value can be recomputed by a single correlated subquery:

- `sum`, `count`, `min`, `max`
- `distinctCount`, `stringAgg`, `jsonAgg`, `jsonObjectAgg`, `topK`
- `bitOr`, `bitAnd`, `bitXor`
- Listener aggregates declared via `#[NestedSetAggregateListener]`

`lazy: true` is **not** supported on the companion-derived display kinds (`avg`, `variance`, `stddev`, `weightedAvg`, `boolOr`, `boolAnd`, `geometricMean`, `harmonicMean`) or fresh-only kinds (`median`, `percentile`). For an `avg`, declare `sum` and `count` companions lazy instead and compute the average at read time, or accept the eager `avg` column.

## Migration shape

The `nestedSetAggregate()` Blueprint macro takes a `lazy:` flag that:

1. Makes the value column nullable (no default 0) — `NULL` is the signal for "needs recompute".
2. Adds a `<column>_computed_at` timestamp companion that tracks when the value was last refreshed.

```php
Schema::create('categories', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->unsignedInteger('articles')->default(0);

    $table->nestedSet(cover: ['articles']);

    $table->nestedSetAggregate('articles_total', lazy: true);
    $table->nestedSetAggregate('articles_count', lazy: true);
});
```

This emits `articles_total`, `articles_total_computed_at`, `articles_count`, and `articles_count_computed_at`.

To declare the columns manually (e.g. with a different stamp name or non-default storage type), follow the same convention: the value column nullable, the stamp column nullable timestamp named `<column>_computed_at`.

## TTL

By default a lazy column stays fresh until the next mutation invalidates it. Pass `ttl: <seconds>` to make the read accessor treat any stamped value older than that age as stale and recompute on access:

```php
#[NestedSetAggregate(column: 'view_count', sum: 'views', lazy: true, ttl: 60)]
```

The TTL is a read-time policy, not a storage shape — the migration is identical to any other lazy column, and the value lives on the model attribute. TTL is useful when the source column changes through paths that don't go through the trait (raw `UPDATE` statements, queued workers, external pipelines) and you want a maximum staleness bound.

`ttl` requires `lazy: true`. `ttl <= 0` throws `AggregateConfigurationException` at registry-build time.

## Reading lazy values

Read the attribute as normal — the accessor handles the recompute transparently:

```php
$root->articles_total;        // recompute + stamp on first read
$root->articles_total;        // stored value, no extra work
$child->save();               // invalidates ancestors
$root->articles_total;        // recompute + stamp again
```

The recompute uses the same correlated SQL as `withFreshAggregates()` and respects scope columns, soft-delete filters, and the aggregate's declared filter clause. It runs inside the read query's connection, not a new transaction.

To bypass the cache for one read (without invalidating the stored value), use `withFreshAggregates()`:

```php
Category::query()->withFreshAggregates()->find($id);
```

To force a refresh on the next read, manually null the stamp column:

```php
DB::table('categories')->where('id', $id)
    ->update(['articles_total_computed_at' => null]);
```

## Concurrency

Two concurrent readers landing on a stale row will each see a `NULL` value and run the recompute. The result is the same value written twice — no drift, no consistency issue, just a little wasted work. If your access pattern includes thundering-herd recomputes, route reads through a cache.

The invalidation `UPDATE` is one statement per inclusivity slice (inclusive + exclusive lazy columns batch separately). Soft-deleted ancestors are skipped — the same rule as eager `DeltaMaintenance`.

## When not to use it

Eager columns are still the right default for read-heavy hierarchies — every read is a single column lookup, no SQL recompute. Reach for lazy when:

- A single write triggers maintenance over a deep ancestor chain you rarely read.
- You're importing thousands of nodes in a tight loop and read-time recompute is cheaper than eager-per-row.
- You're rebuilding a subtree under [`withDeferredAggregateMaintenance()`](maintenance.html#deferred-maintenance) and want subtree reads outside the deferred block to remain fast without forcing a full repair.

If reads are frequent and writes are sparse, the lazy invalidation cost (still one `UPDATE` per write) doesn't earn its keep — stay eager.
