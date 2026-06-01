# Listener Aggregates

When a contribution requires PHP logic that can't be expressed as a SQL column reference — for example `SUM(base_power * level)` where the product is computed per node — declare a **listener aggregate**:

```php
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\TreeAggregateListener;

class WeightedPowerListener implements TreeAggregateListener
{
    public function contribution(Model $node): int|float|null
    {
        return (int) $node->base_power * (int) $node->level;
    }

    /** Columns whose changes should trigger re-aggregation on ancestors. */
    public function watchColumns(): array
    {
        return ['base_power', 'level'];
    }
}
```

Declare it on the model with `#[NestedSetAggregateListener]`:

```php
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;

#[NestedSetAggregateListener(column: 'weighted_power', listener: WeightedPowerListener::class, operation: AggregateFunction::Sum)]
#[NestedSetAggregateListener(column: 'fire_count',     listener: FireCountListener::class,     operation: AggregateFunction::Sum)]
class Monster extends Model implements HasNestedSet { use NodeTrait; }
```

`contribution()` returns this node's value. `null` means "exclude this node" — useful for Min/Max where some nodes have no meaningful value. `watchColumns()` declares which attribute changes trigger incremental maintenance.

Supported operations: `Sum`, `Count`, `Min`, `Max`, `Avg`. `Avg` is auto-promoted into a pair of internal `Sum` + `Count` companions plus the display column — see [Listener AVG](#listener-avg) below.

## What the rollup looks like

Take a monster squad. Each row carries `base_power` and `level`; the listener's `contribution()` returns the product, and `weighted_power` rolls that up the chain. The `weighted_power` chip on every node is the **per-node** contribution (what `contribution($node)` returned), and `Σ weighted_power` on each subtree is the maintained `weighted_power` column on that row:

```ns-tree
Boss Squad
  Champion {weighted_power=50, base_power=10, level=5}
    Lieutenant A {weighted_power=12, base_power=4, level=3}
    Lieutenant B {weighted_power=8, base_power=4, level=2}
  Sergeant {weighted_power=8, base_power=2, level=4}
    Grunt 1 {weighted_power=1, base_power=1, level=1}
    Grunt 2 {weighted_power=2, base_power=1, level=2}
```

Boss Squad itself has no `base_power` / `level` (it's a container), so its own contribution is `null` — but the `Σ weighted_power = 81` on its row is the rollup over the whole squad. Change either `base_power` or `level` on any single row and the package recomputes that row's contribution (via the same PHP call), takes the delta against the stored value, and applies it as one `UPDATE` against the ancestor chain — same shape as a delta-maintainable SQL aggregate.

## Migration

Listener columns use the same macro as SQL aggregates:

```php
$table->nestedSetAggregate('weighted_power');             // integer, NOT NULL, default 0
$table->nestedSetAggregate('fire_count');                  // integer, NOT NULL, default 0
$table->nestedSetAggregate('fire_max', type: 'min_max');  // nullable, for Min/Max
```

**Float contributions need a non-integer column type.** A listener's `contribution()` returns `int|float|null` and the package threads floats end-to-end through the maintenance pipeline. But the *stored column* still has to accept them. If you declared the column as integer via `nestedSetAggregate()` and your listener returns fractional values, the DB will truncate at the write side and your column will drift.

Declare a decimal column manually for float-returning listeners:

```php
// instead of $table->nestedSetAggregate('weighted_score'),
$table->decimal('weighted_score', 14, 4)->default(0);
// or for nullable Min/Max-style:
$table->decimal('weighted_max', 14, 4)->nullable();
```

Cast as `float` (or `decimal:N`) on the model:

```php
protected $casts = [
    'weighted_score' => 'float',
];
```

The aggregate machinery doesn't care which Blueprint helper produced the column — it cares only that the column exists with the declared name and accepts the value range your listener returns.

## Method-override form

```php
use Vusys\NestedSet\Aggregates\ListenerAggregate;

/** @return list<\Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition> */
protected function nestedSetListenerAggregates(): array
{
    return [
        ListenerAggregate::sum(WeightedPowerListener::class)->into('weighted_power'),
        ListenerAggregate::max(FireMaxListener::class)->into('fire_max'),
    ];
}
```

Attribute and method-override forms can coexist; attribute declarations come first.

## Listener AVG

Declare a listener AVG with `AggregateFunction::Avg` and the package auto-promotes two internal companions — a Sum and a Count — that ride the same listener. The display column is then written by the same `avg = sum / NULLIF(count, 0)` SET clause that powers SQL AVG, so the ancestor `UPDATE` stays a single statement.

```php
#[NestedSetAggregateListener(column: 'weighted_avg', listener: WeightedPowerListener::class, operation: AggregateFunction::Avg)]
class Monster extends Model implements HasNestedSet { use NodeTrait; }
```

The companion columns are conventionally suffixed `__sum` and `__count` on the AVG column name. You declare them in the migration alongside the display column. **The `__sum` companion's storage type must accept the same value range your listener returns** — `nestedSetAggregate()` defaults to a `bigint`, which silently truncates float contributions. Match the migration to the listener:

```php
// Display column — nullable, fractional. Use decimal for fixed-precision
// or float for an approximate type. Cast on the model accordingly.
$table->decimal('weighted_avg', 14, 4)->nullable();

// Integer-returning listener → bigint companion is fine:
$table->nestedSetAggregate('weighted_avg__sum');     // bigint, default 0
$table->nestedSetAggregate('weighted_avg__count');   // bigint, default 0

// Float-returning listener → declare __sum as decimal manually:
$table->decimal('weighted_avg__sum', 20, 4)->default(0);   // wider than display — the sum can exceed any single contribution
$table->nestedSetAggregate('weighted_avg__count');         // count is always integral
```

Cast all three on the model:

```php
protected $casts = [
    'weighted_avg'        => 'float',     // or 'decimal:4'
    'weighted_avg__sum'   => 'float',     // match the listener's return type ('integer' if integer-returning)
    'weighted_avg__count' => 'integer',
];
```

The companions are tagged internal — `getAggregateDefinitions()` filters them out, so they don't appear in user-facing introspection. The listener's `contribution()` runs once per node per save and produces both Sum and Count contributions in one call (Count adds `1` when `contribution()` returns non-null, `0` when it returns `null`).

The companion column names must follow the `__sum` / `__count` convention — the **listener-AVG** auto-promotion always derives them from the display column name, so renaming them isn't supported on this path. (SQL-AVG declarations *can* adopt user-named SUM / COUNT columns with a matching filter — see [Declaring → How AVG is computed](declaring.html#how-avg-is-computed). The two paths differ here because the listener carries the contribution per node, leaving no source-column identity for the registry to match an existing companion against.)

## Exclusive listener aggregates

`exclusive: true` opts out of self-inclusion — a node's stored value holds the function's rollup over its **descendants only**. A leaf's exclusive aggregate is always the zero/null element for the function (0 for Sum/Count, `null` for Min/Max). Same semantic as [`exclusive: true` on SQL aggregates](recipes.html#inclusive-vs-exclusive--totals-including-and-below); the contribution per node still comes from `contribution()`.

```php
#[NestedSetAggregateListener(
    column: 'descendants_weighted_power',
    listener: WeightedPowerListener::class,
    operation: AggregateFunction::Sum,
    exclusive: true,
)]
class Monster extends Model implements HasNestedSet { use NodeTrait; }
```

In the method-override form, call `->exclusive()` on the fluent builder before `->into()`:

```php
protected function nestedSetListenerAggregates(): array
{
    return [
        ListenerAggregate::sum(WeightedPowerListener::class)
            ->exclusive()
            ->into('descendants_weighted_power'),
    ];
}
```

## Maintenance

Listener aggregates ride the same lifecycle hooks as SQL aggregates. On each save the package calls `contribution()` on the changed node, computes a delta, and propagates it up the ancestor chain. Min/Max listener columns that may have been invalidated trigger a PHP-based ancestor recompute — the package issues exactly two SELECTs (one to load the ancestor chain, one to load every in-scope node under the topmost ancestor) regardless of chain depth, then computes each ancestor's new extremum in PHP. Listener contributions are cached per node across all Min/Max definitions, so each `contribution()` call runs once per node per recompute.

`fixAggregates()`, `aggregateErrors()`, and `freshAggregate()` all cover listener columns:

```php
Monster::fixAggregates();              // repairs SQL and listener columns together
Monster::aggregateErrors();            // counts drift in both column types
$node->freshAggregate('weighted_power'); // PHP-computed fresh value for one node
```

`replicate()` resets listener columns to `0` (Sum/Count) or `null` (Min/Max) on clones, matching the SQL-aggregate behaviour.

## Listener aggregate limitations

### No `withFreshAggregates()` support

The collection-level fresh-read path is SQL-only and does not cover listener columns. Use `freshAggregate('col')` for a single node, or repair the whole set with `fixAggregates()`.

### `fixAggregates()` is O(N²) for listener columns

It loads every in-scope node and scans each node's subtree in PHP. Use `withDeferredAggregateMaintenance()` for batch mutations to amortise the cost down to one pass.

### Repair / Min-Max recompute holds the bounding-box subtree in PHP memory

`fixAggregates()` loads every in-scope Eloquent model; the Min/Max recompute path loads every in-scope node under the topmost affected ancestor. At N > ~100K nodes this is the more pressing constraint than CPU. Anchored `fixAggregates($subtreeRoot)` and chunked `fixAggregates(chunkSize: …)` both bound the working set.

### Filters are encoded in the listener itself

There is no `filter:` param on `#[NestedSetAggregateListener]`. Return `null` from `contribution()` to exclude a node, or `0` / `1` to count conditionally.
