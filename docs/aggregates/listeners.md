# Listener Aggregates

When a contribution requires PHP logic that can't be expressed as a SQL
column reference — for example `SUM(base_power * level)` where the
product is computed per node — declare a **listener aggregate**:

```php
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\TreeAggregateListener;

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

`contribution()` returns this node's value. `null` means "exclude this
node" — useful for Min/Max where some nodes have no meaningful value.
`watchColumns()` declares which attribute changes trigger incremental
maintenance.

Supported operations: `Sum`, `Count`, `Min`, `Max`, `Avg`. `Avg` is
auto-promoted into a pair of internal `Sum` + `Count` companions plus
the display column — see [Listener AVG](#content-listener-avg) below.

## Migration

Listener columns use the same macro as SQL aggregates:

```php
$table->nestedSetAggregate('weighted_power');             // integer, NOT NULL, default 0
$table->nestedSetAggregate('fire_count');                  // integer, NOT NULL, default 0
$table->nestedSetAggregate('fire_max', type: 'min_max');  // nullable, for Min/Max
```

**Float contributions need a non-integer column type.** A listener's
`contribution()` returns `int|float|null` and the package threads floats
end-to-end through the maintenance pipeline. But the *stored column*
still has to accept them. If you declared the column as integer via
`nestedSetAggregate()` and your listener returns fractional values, the
DB will truncate at the write side and your column will drift.

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

The aggregate machinery doesn't care which Blueprint helper produced
the column — it cares only that the column exists with the declared
name and accepts the value range your listener returns.

## Method-override form

```php
use Vusys\NestedSet\Aggregates\ListenerAggregate;

/** @return list<\Vusys\NestedSet\Aggregates\ListenerAggregateDefinition> */
protected function nestedSetListenerAggregates(): array
{
    return [
        ListenerAggregate::sum(WeightedPowerListener::class)->into('weighted_power'),
        ListenerAggregate::max(FireMaxListener::class)->into('fire_max'),
    ];
}
```

Attribute and method-override forms can coexist; attribute declarations
come first.

## Listener AVG

Declare a listener AVG with `AggregateFunction::Avg` and the package
auto-promotes two internal companions — a Sum and a Count — that ride
the same listener. The display column is then written by the same
`avg = sum / NULLIF(count, 0)` SET clause that powers SQL AVG, so the
ancestor `UPDATE` stays a single statement.

```php
#[NestedSetAggregateListener(column: 'weighted_avg', listener: WeightedPowerListener::class, operation: AggregateFunction::Avg)]
class Monster extends Model implements HasNestedSet { use NodeTrait; }
```

The companion columns are conventionally suffixed `__sum` and `__count`
on the AVG column name. You declare them in the migration alongside
the display column:

```php
// Display column — nullable, fractional. Use decimal for fixed-precision
// or float for an approximate type. Cast on the model accordingly.
$table->decimal('weighted_avg', 14, 4)->nullable();

// Internal companions — integer (or decimal, if your listener returns floats).
$table->nestedSetAggregate('weighted_avg__sum');
$table->nestedSetAggregate('weighted_avg__count');
```

Cast all three on the model:

```php
protected $casts = [
    'weighted_avg'        => 'float',     // or 'decimal:4'
    'weighted_avg__sum'   => 'integer',
    'weighted_avg__count' => 'integer',
];
```

The companions are tagged internal — `getAggregateDefinitions()`
filters them out, so they don't appear in user-facing introspection.
The listener's `contribution()` runs once per node per save and
produces both Sum and Count contributions in one call (Count adds `1`
when `contribution()` returns non-null, `0` when it returns `null`).

The companion column names must follow the `__sum` / `__count`
convention — the auto-promotion always derives them from the display
column name, so renaming them isn't supported.

## Maintenance

Listener aggregates ride the same lifecycle hooks as SQL aggregates.
On each save the package calls `contribution()` on the changed node,
computes a delta, and propagates it up the ancestor chain. Min/Max
listener columns that may have been invalidated trigger a PHP-based
ancestor recompute — the package issues exactly two SELECTs (one to
load the ancestor chain, one to load every in-scope node under the
topmost ancestor) regardless of chain depth, then computes each
ancestor's new extremum in PHP. Listener contributions are cached per
node across all Min/Max definitions, so each `contribution()` call
runs once per node per recompute.

`fixAggregates()`, `aggregateErrors()`, and `freshAggregate()` all
cover listener columns:

```php
Monster::fixAggregates();              // repairs SQL and listener columns together
Monster::aggregateErrors();            // counts drift in both column types
$node->freshAggregate('weighted_power'); // PHP-computed fresh value for one node
```

`replicate()` resets listener columns to `0` (Sum/Count) or `null`
(Min/Max) on clones, matching the SQL-aggregate behaviour.

## Listener aggregate limitations

- **`withFreshAggregates()` does not cover listener columns** — the
  collection-level fresh-read path is SQL-only. Use
  `freshAggregate('col')` for a single node or repair the whole set
  with `fixAggregates()`.
- **`fixAggregates()` is O(N²) for listener columns** — it loads every
  in-scope node and scans each node's subtree in PHP. Use
  `withDeferredAggregateMaintenance()` for batch mutations to amortise
  the cost down to one pass.
- **Listener repair / Min-Max recompute holds the bounding-box subtree
  in PHP memory.** `fixAggregates()` loads every in-scope Eloquent
  model; the Min/Max recompute path loads every in-scope node under
  the topmost affected ancestor. At N > ~100K nodes this is the more
  pressing constraint than CPU. Anchored `fixAggregates($subtreeRoot)`
  and chunked `fixAggregates(chunkSize: …)` both bound the working
  set.
- **Filters are encoded in the listener itself** — there is no
  `filter:` param on `#[NestedSetAggregateListener]`. Return `null`
  from `contribution()` to exclude a node, or `0` / `1` to count
  conditionally.
