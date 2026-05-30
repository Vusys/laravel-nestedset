# Geometric and Harmonic Mean

Two delta-maintainable mean aggregates for source columns where the arithmetic mean is the wrong question. Both are companion-derived (same machinery as `Avg`, `Variance`, `Stddev`, `WeightedAvg`) so a read is a plain column read and a write is one delta UPDATE per ancestor chain.

- **Geometric mean** — `EXP(Σ LN(source) / n)`. Reach for it when source values multiply rather than add (compound growth rates, ratios, scale factors). The geometric mean of two numbers `a` and `b` is `sqrt(a · b)` — closer to the smaller of the two than the arithmetic mean.
- **Harmonic mean** — `n / Σ(1/source)`. Reach for it when source values are rates over a common unit and you want the average rate (parallel resistance, average speed across equal distances). The harmonic mean weighs small values more heavily than the arithmetic mean.

```php
use Vusys\NestedSet\Attributes\NestedSetAggregate;

#[NestedSetAggregate(column: 'growth_geomean', geometricMean: 'growth_rate')]
#[NestedSetAggregate(column: 'speed_harmean',  harmonicMean:  'speed_kph')]
class Segment extends Model implements HasNestedSet
{
    use NodeTrait;
}
```

Or via the method-override form:

```php
protected function nestedSetAggregates(): array
{
    return [
        Aggregate::geometricMean('growth_rate')->into('growth_geomean'),
        Aggregate::harmonicMean('speed_kph')->into('speed_harmean'),
    ];
}
```

## Migration

```php
$table->decimal('growth_rate', 10, 4)->nullable();
$table->decimal('speed_kph',   10, 4)->nullable();

$table->nestedSet();
$table->nestedSetAggregate('growth_geomean', type: 'geometric_mean');
$table->nestedSetAggregate('speed_harmean',  type: 'harmonic_mean');
```

Each declaration allocates the user-facing display column **plus** two delta-maintainable companions:

| Display column          | Companions allocated                              |
|-------------------------|---------------------------------------------------|
| `geometricMean(src)`    | `*__sum_log` (= `Σ LN(src)`), `*__count`          |
| `harmonicMean(src)`     | `*__sum_recip` (= `Σ 1/src`), `*__count`          |

The display column is a nullable `decimal(12, 4)`. The `__sum_log` / `__sum_recip` companions are `decimal(30, 10)` — wider fractional precision than the standard `decimal_sum` companion (4 digits) because `LN(x)` and `1/x` are irrational and 4 fractional digits accumulate visible rounding error across a deep subtree. The `__count` companion is the standard bigint sum_count shape, but counts only rows that actually contributed (positive for geometric, non-zero for harmonic), so the display formula uses the right `n`.

The display column reads NULL when the subtree contributed no rows — empty subtree, every contributor filtered out by the positivity / non-zero constraint, or harmonic with a zero reciprocal sum.

## The positivity / non-zero constraint

Both functions have a domain restriction.

- `geometricMean` requires **strictly positive** source values. `LN(0)` is undefined; `LN(negative)` is undefined over the reals.
- `harmonicMean` requires **non-zero** source values. `1 / 0` is undefined.

By default, a save whose source column violates the constraint throws `AggregateSourceConstraintViolationException` — synchronous, with the model class, column name, and offending value in the message. Catch it at the call site or let it propagate.

```php
$segment = new Segment(['growth_rate' => -0.1]);   // throws on save:
$segment->appendToNode($parent)->save();           // AggregateSourceConstraintViolationException
```

Opt into silent-skip semantics with `allowNonPositive()` (fluent form) or `allowNonPositive: true` (attribute form) if your data legitimately includes those values and you want them excluded from the rollup rather than rejected:

```php
#[NestedSetAggregate(
    column: 'growth_geomean',
    geometricMean: 'growth_rate',
    allowNonPositive: true,
)]
class Segment extends Model implements HasNestedSet { use NodeTrait; }

// or
Aggregate::geometricMean('growth_rate')->allowNonPositive()->into('growth_geomean');
```

With the modifier, a non-positive (geometric) or zero (harmonic) row contributes nothing to either companion — the SQL fallback `LN(x)` returns NULL for `x ≤ 0` and `NULLIF(x, 0)` handles the harmonic side; the PHP delta-capture matches that. NULL source values are always skipped regardless of the modifier; the constraint only applies to numeric values inside the domain you'd reasonably expect.

The validation runs in the `saving` hook before any delta capture, so a thrown violation aborts the save atomically — no partial drift to clean up.

## How it stays in sync

Every mutation that touches the source column updates the two companions and writes the display value from them in one SET clause:

```text
geometricMean  = EXP(sum_log / NULLIF(count, 0))
harmonicMean   = count / NULLIF(sum_recip, 0)
```

The companion `__count` is transform-aware — it counts only positive (geometric) or non-zero (harmonic) rows, matching the domain of the matching sum. That means `EXP(sum_log / count)` and `count / sum_recip` always use the right `n` even when `allowNonPositive()` is in effect and some rows contribute nothing.

`fixAggregates()` recomputes the companions **and** the display column in one pass — drift detection and repair work identically to `Avg` / `Variance`.

## Empty subtree and single-value semantics

- **Empty subtree** — every variant returns NULL.
- **Single value** — `geometricMean = x`, `harmonicMean = x` (`EXP(LN(x))` and `x/1` both reduce to `x`).
- **All contributors excluded** — display is NULL. With `allowNonPositive()` and every row being non-positive (geometric) or zero (harmonic), the companion `__count` ends at 0 and the formula divides by NULL.

## Numerical precision

The geometric mean is computed via the `EXP(Σ LN(x) / n)` form (the standard for "compute it in SQL once and store it"). For source values that span more than ~13 orders of magnitude, `LN`-then-`EXP` rounds at the last significant fractional digit of `decimal(30, 10)`. For typical workloads — growth percentages, scale factors, ratios within a couple of orders of magnitude — this never matters. If your domain spans much wider ranges, fall back to `withFreshAggregates()` against the native `EXP(AVG(LN(col)))` SQL, which uses the database's full floating-point precision.

The harmonic mean is computed as `count / Σ(1/x)`. Floating-point precision on `decimal(30, 10)` is more than enough for the realistic range — the precision concerns of the geometric mean don't have a direct analog here.

## Filters

Both compose with the standard filter modifiers — `filter`, `filterNotNull`, `filterRaw` — and the package watches the source column for delta maintenance automatically. The same `filterRawWatches` rule applies: list every column the raw SQL references or the stored mean will silently drift.

## Limitations

### Listener aggregates do not support geometric / harmonic mean

The contribution-per-row contract carries one numeric value with no positivity context; declare these over a real SQL source column.

### `exclusive: true` routes through chain recompute

Same trade-off as exclusive `Avg` / `Variance` — correct but slower than the inline delta. Prefer the default inclusive declarations unless the exclusive value is what the domain actually wants.

### Backend support is uniform

Both functions emit `EXP(LN(...))` / `1.0 / col` against ordinary `SUM` / `COUNT` companions — no native `GEOMETRIC_MEAN` / `HARMONIC_MEAN` SQL functions are required, so MySQL, MariaDB, PostgreSQL, and SQLite all behave identically.
