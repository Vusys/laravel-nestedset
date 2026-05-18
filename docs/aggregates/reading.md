# Reading Values

The stored column is a single-row read — effectively free. The fresh
counterpart recomputes from the source column via a correlated subquery
— useful for audit reports or drift detection.

```php
// Single-row fresh recomputation
$category->articles_total;                        // stored
$category->freshAggregate('articles_total');      // recomputed from source

// Collection-level fresh selects (overlay stored values)
Category::query()->withFreshAggregates()->get();
Category::query()->withFreshAggregates(['articles_total', 'articles_max'])->get();

// Ad-hoc fresh aggregate without declaring a column
use Vusys\NestedSet\Aggregates\Aggregate;
Category::query()->withFreshAggregates([
    'descendants_total' => Aggregate::sum('articles')->exclusive(),
])->get();
```

## When to reach for `freshAggregate()`

| Situation | Use |
|---|---|
| Rendering a tree, hundreds of nodes | stored column — it's already there |
| Drift audit / scheduled health check | `freshAggregate()` or `aggregateErrors()` |
| One-off report with a predicate you haven't declared | `withFreshAggregates([alias => Aggregate::…])` |
| Source column was just touched outside Eloquent | `freshAggregate()` until the next [repair pass](../maintenance/fix-aggregates.html) |
