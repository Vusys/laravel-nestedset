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

## Ad-hoc aliases are in-memory only

Aliases passed to `withFreshAggregates([...])` that don't match a
declared aggregate column live only on the in-memory model:

```php
$node = Category::query()
    ->withFreshAggregates([
        'descendants_total' => Aggregate::sum('articles')->exclusive(),
    ])
    ->find(1);

$node->descendants_total;   // computed value, present
$node->save();              // does NOT write descendants_total —
                            // there is no schema column for it
$node->refresh();
$node->descendants_total;   // attribute is gone after refresh
```

The package writes only declared aggregate columns. Ad-hoc aliases
are intended for one-off reads (reports, audits); persisting them
would require declaring the column and the aggregate on the model.

When the alias **does** match a declared aggregate column,
`withFreshAggregates()` overlays the freshly-computed value on top
of the stored attribute under the same name. Beware: on a
subsequent `save()`, the model's own dirty tracking treats the
fresh value as the new stored value. If a brief drift window
produced a different fresh result than the stored value, that
drift gets persisted. The safe pattern is to use a separate alias
when you need both the stored and fresh side-by-side:

```php
$node = Category::query()
    ->withFreshAggregates([
        'articles_total_fresh' => Aggregate::sum('articles'),
    ])
    ->find(1);

$node->articles_total;        // stored value, untouched
$node->articles_total_fresh;  // in-memory only, dropped on refresh()
```
