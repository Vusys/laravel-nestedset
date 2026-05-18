# Reading Values

The stored column is a single-row read — effectively free. The fresh
counterpart recomputes from the source column via a correlated subquery
— useful for audit reports or drift detection.

```php
// Single-row fresh recomputation
$area->tickets_total;                        // stored
$area->freshAggregate('tickets_total');      // recomputed from source

// Collection-level fresh selects (overlay stored values)
Area::query()->withFreshAggregates()->get();
Area::query()->withFreshAggregates(['tickets_total', 'tickets_max'])->get();

// Ad-hoc fresh aggregate without declaring a column
use Vusys\NestedSet\Aggregates\Aggregate;
Area::query()->withFreshAggregates([
    'descendants_total' => Aggregate::sum('tickets')->exclusive(),
])->get();
```
