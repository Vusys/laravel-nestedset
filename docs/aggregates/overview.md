# Precalculated Aggregate Columns

Sometimes you want a node to carry rolled-up data about its subtree — a total, a count, an average, a min/max — without re-running an aggregate query every time the tree is rendered. Declare the columns and the package keeps them in sync as the tree mutates.

For the examples in this section, imagine a `Category` model from a blog or shop. Each category has an `articles` integer for the number of articles directly tagged with it; the aggregates roll those counts up the tree.

```php
use Vusys\NestedSet\Attributes\NestedSetAggregate;

#[NestedSetAggregate(column: 'articles_total',     sum:   'articles')]
#[NestedSetAggregate(column: 'articles_count_all', count: true)]
#[NestedSetAggregate(column: 'articles_avg',       avg:   'articles')]
#[NestedSetAggregate(column: 'articles_min',       min:   'articles')]
#[NestedSetAggregate(column: 'articles_max',       max:   'articles')]
class Category extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;
}
```

For a tree:

```text
Electronics  (articles = 4)
├── Computers  (articles = 2)
│   ├── Laptops   (articles = 8)
│   └── Desktops  (articles = 3)
└── Phones     (articles = 12)
```

…the stored aggregates on `Electronics` are:

```php
$electronics->refresh()->articles_total;     // 29  (4 + 2 + 8 + 3 + 12)
$electronics->articles_count_all;            // 5   (self + 4 descendants)
$electronics->articles_avg;                  // 5.8 (29 / 5)
$electronics->articles_min;                  // 2
$electronics->articles_max;                  // 12
```

Every node carries its own subtree's rollup — `Computers` independently reports `articles_total = 13` and `articles_count_all = 3`. Inserts, source-column updates, deletes, moves and soft-delete restores all keep the stored values current.

## In this section

- [Migration & Setup](setup.html) — adding aggregate columns and the model conventions that keep them honest
- [Reading Values](reading.html) — stored vs fresh recomputation
- [Declaring Aggregates](declaring.html) — attribute and method-override forms
- [Filtered Aggregates](filtered.html) — equality, not-null, and raw SQL filters
- [Collection Aggregates](text-and-json.html) — distinctCount, stringAgg, jsonAgg, jsonObjectAgg
- [Top-K Aggregate](top-k.html) — the K rows with the largest ranking value across the subtree
- [Listener Aggregates](listeners.html) — PHP-computed contributions
- [Variance & Stddev](maths.html) — statistical roll-ups, both population and sample
- [Weighted Average & Boolean Rollups](weighted-avg-and-booleans.html) — `weightedAvg(value, weight)`, `boolOr`, `boolAnd`
- [Geometric & Harmonic Mean](means.html) — `geometricMean` / `harmonicMean` with the positivity / non-zero constraint
- [Median, Percentile & Quantiles](quantiles.html) — fresh-read-only quantiles via `withFreshAggregates()`
- [Bitwise Aggregates](bitwise.html) — bitOr / bitAnd / bitXor over integer source columns
- [Lazy Aggregates](lazy.html) — defer recompute to first read with `lazy: true` and an optional `ttl`
- [Recipes](recipes.html) — common shapes
- [Maintenance](maintenance.html) — what runs when, plus integrity tooling
- [Drift & Limitations](drift.html) — when stored values can lag and how to mitigate
