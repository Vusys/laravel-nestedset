# Maths aggregates — variance & stddev

The variance and stddev aggregates roll up the spread of a numeric source column over a subtree. Both come in **population** and **sample** variants.

```php
use Vusys\NestedSet\Attributes\NestedSetAggregate;

#[NestedSetAggregate(column: 'price_variance', variance: 'price')]
#[NestedSetAggregate(column: 'price_stddev',   stddev:   'price')]
#[NestedSetAggregate(column: 'price_var_samp', variance: 'price', sample: true)]
#[NestedSetAggregate(column: 'price_std_samp', stddev:   'price', sample: true)]
class Product extends Model implements HasNestedSet
{
    use NodeTrait;
}
```

Or via the method-override form:

```php
protected function nestedSetAggregates(): array
{
    return [
        Aggregate::variance('price')->into('price_variance'),
        Aggregate::stddev('price', sample: true)->into('price_std_samp'),
    ];
}
```

## Schema

The `nestedSetAggregate()` migration macro allocates the display column plus three internal companions per declaration (Sum, SumSq, Count):

```php
$table->nestedSetAggregate('price_variance', type: 'variance');
$table->nestedSetAggregate('price_stddev',   type: 'stddev');
```

`type: 'variance'` and `type: 'stddev'` are identical in storage shape — both produce a nullable decimal display column and three internal companions: `__sum` and `__count` as signed `bigint`, and `__sum_sq` as `decimal(38, 0)` (the sum-of-squares grows quadratically with subtree size and needs the wider range). The macro picks the type name purely so migrations stay self-documenting.

If you want the variance and the stddev over the same source, declare both as separate `nestedSetAggregate(...)` calls. Each declaration owns its own companion triple — there's no implicit sharing across declarations.

## How it stays in sync

Variance and stddev are derived in SQL from their three delta-maintained companions:

- `Sum`  — accumulates `source`.
- `SumSq` — accumulates `source * source`. Internally a SUM companion with a "square" source transform; the maintenance machinery applies the transform on every contributing row.
- `Count` — counts contributing rows.

On every mutation the same `UPDATE` that adjusts the companions also rewrites the display column using the textbook `E[X²] − E[X]²` form:

```text
variance_pop  = (n·SumSq − Sum²) / n²
variance_samp = (n·SumSq − Sum²) / n(n−1)
stddev        = SQRT(variance)
```

This is a single statement per mutation — no second pass, no second query.

## Empty subtree / single value semantics

- **Empty subtree** — every variant returns `NULL`.
- **Single value** — population variance and stddev are `0`; sample variance and sample stddev are `NULL` (the `n − 1` denominator collapses to zero).
- **Inclusive** is the default. An exclusive variance over a leaf is always `NULL` for the same reason.

## Numerical-stability caveat

The package computes variance from the standard `E[X²] − E[X]²` form rather than the numerically stable Welford recurrence. This is the same formula databases use for native `VAR_POP` / `VAR_SAMP`, and it composes cleanly with the existing companion-column maintenance — but it can lose precision when source values are clustered tightly around a large mean (sensor timestamps, large monetary values with tiny variation).

For typical nested-set workloads — prices, ratings, durations, counts that vary by orders of magnitude *within* a subtree — this never matters. If you do hit the failure mode, reach for `withFreshAggregates()`, which delegates to the database's native function on PostgreSQL / MySQL / MariaDB:

```php
$rows = Product::query()
    ->withDepth()
    ->withFreshAggregates([
        'price_variance' => Aggregate::variance('price'),
    ])
    ->get();
```

The SQL guards against the closely related "tiny negative variance" case (a near-zero `n·SumSq − Sum²` that floating-point rounds below zero) by clamping with `CASE WHEN var < 0 THEN 0 ELSE var END` before taking the square root — without it, PostgreSQL would error on `SQRT(-0.0000001)` while the other backends would silently return `NULL`. The clamp produces `0`, which is also what Welford would compute for a constant sequence.

## Limitations

- **Listener aggregates** (variance over a PHP-computed contribution) are not supported in this milestone. Use SQL aggregates for variance and stddev, or maintain Sum + SumSq + Count manually and derive the variance in your application code.
- **Exclusive variance / stddev** route through the chain-recompute path (the same path used for exclusive Avg). This is correct but slower than the cheap inline delta — prefer inclusive declarations unless your domain genuinely needs the exclusive value.
