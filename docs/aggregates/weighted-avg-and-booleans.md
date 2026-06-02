# Weighted Average and Boolean Rollups

Two delta-maintainable families that don't fit the standard `SUM / COUNT / AVG / MIN / MAX` shape but show up often in real dashboards.

## Weighted average

`Aggregate::weightedAvg(value, weight)` rolls up `Σ(weight · value) / Σ(weight)` across a subtree. The display column reads NULL when the subtree's total weight is zero (which matches the SQL convention for `0 / 0`).

### Why a plain `Avg` would lie

A retailer's order categories — a few high-volume, low-price items and one boutique line:

```ns-tree
Orders
  Bulk Office Supplies {price=5, qty=1000}
  Standard Shoes {price=50, qty=200}
  Custom Watches {price=2000, qty=10}
```

A plain `AVG(price)` over the subtree would give `685` — but that wildly overstates what customers are actually paying *per item*, because there are 100× more bulk supplies than watches. The weighted average `Σ(qty · price) / Σ(qty)` honours the quantities: `(1000·5 + 200·50 + 10·2000) / (1000+200+10) = 35000 / 1210 ≈ 28.93`. Maintained on `Orders.price_wavg`, kept current by one delta UPDATE per mutation that touches either `price` or `qty`.

```php
use Vusys\NestedSet\Attributes\NestedSetAggregate;

#[NestedSetAggregate(column: 'price_wavg', weightedAvg: 'price', weight: 'qty')]
class OrderCategory extends Model implements HasNestedSet
{
    use NodeTrait;
}
```

Migration:

```php
$table->decimal('price', 10, 2)->nullable();
$table->decimal('qty', 10, 2)->nullable();

$table->nestedSet();
$table->nestedSetAggregate('price_wavg', type: 'weighted_avg');
```

The macro allocates the user-facing `price_wavg` column **plus** two delta-maintainable companions: `price_wavg__sum_wx` (= `Σ(qty · price)`) and `price_wavg__sum_w` (= `Σ(qty)`). Every mutation updates both companions and writes the derived display value from them in a single SET clause — so a weighted-average read is a plain column read and a write is one delta UPDATE per ancestor chain.

Updating either the value column or the weight column triggers a delta capture; the package watches both. Rows where either column is NULL contribute nothing to either companion (matching SQL's `SUM(NULL) = 0` convention).

`weightedAvg` requires the value and weight columns to differ. A column weighted by itself collapses to a plain average only when the source is constant; declare `Aggregate::avg($source)` directly if that's what you meant.

## Boolean rollups: `boolOr` / `boolAnd`

`boolOr` answers "does ANY descendant carry a truthy value here?" and `boolAnd` answers "do ALL descendants?". Both share the same companion pair (`__sum` of the bool-as-int + `__count`) so a single declaration of each costs one delta UPDATE on every mutation.

### Reading any / all from a flag tree

A feature-flag tree where some leaves are enabled and some aren't:

```ns-tree
Beta features {any_active=true, all_active=false}
  Dark mode {active=true}
  Realtime sync {any_active=true, all_active=false}
    Push notifications {active=true}
    SMS fallback {active=false}
  Voice control {active=false}
```

Read the `any_active` and `all_active` chips on each branch row — they're the stored values in `boolOr` and `boolAnd` columns. `Realtime sync` has `any_active = true` (Push is enabled) and `all_active = false` (SMS isn't). The root `Beta features` is `any_active = true, all_active = false` for the same reasons rolled up the whole subtree.

If your UI hides a "Realtime sync" navigation entry when no descendant feature is enabled, the read is a single column lookup — `where('any_active', true)` — no recursive walk and no per-row `EXISTS` subquery. The flip happens on `save()`: enable Voice control and `Beta features.all_active` flips to `true` in the same UPDATE that flips `Voice control.active`.

```php
#[NestedSetAggregate(column: 'any_active', boolOr: 'active')]
#[NestedSetAggregate(column: 'all_active', boolAnd: 'active')]
class FeatureFlagNode extends Model implements HasNestedSet
{
    use NodeTrait;
}
```

Migration:

```php
$table->boolean('active')->default(false);

$table->nestedSet();
$table->nestedSetAggregate('any_active', type: 'bool_or');
$table->nestedSetAggregate('all_active', type: 'bool_and');
```

Storage types are backend-native booleans: native `BOOLEAN` on PostgreSQL, `TINYINT(1)` on MySQL / MariaDB, `INTEGER` on SQLite. The maintenance SET clauses write the portable SQL keywords `TRUE` / `FALSE` / `NULL` so Eloquent's `boolean` cast reads back consistently across backends.

Semantics on an empty subtree:

- `boolOr` → NULL ("no contributors, so 'any?' is undefined").
- `boolAnd` → NULL (same reason).

The exact reason a row is FALSE is preserved — `boolOr = FALSE` means "every contributor is false", not "no contributors". Empty is explicitly NULL, never FALSE.

## Filtering and recompute

Both families compose with the standard filter modifiers (`filter`, `filterNotNull`, `filterRaw`), and both ride on the standard companion delta-maintenance machinery. `fixAggregates()` recomputes the companions **and** the user-facing display column in one pass, so drift detection works identically to `AVG` / `Variance`.

Listener aggregates (`#[NestedSetAggregateListener]`) do **not** support these kinds — the contribution-per-row contract carries one numeric value, not the `(value, weight)` pair or the boolean rollup state. Use a SQL column declaration for these instead.
