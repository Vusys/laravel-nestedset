# Precalculated Aggregate Columns

Sometimes you want a node to carry rolled-up data about its subtree — a
total, a count, an average, a min/max — without re-running an aggregate
query every time the tree is rendered. Declare the columns and the
package keeps them in sync as the tree mutates.

```php
use Vusys\NestedSet\Attributes\NestedSetAggregate;

#[NestedSetAggregate(column: 'tickets_total',     sum:   'tickets')]
#[NestedSetAggregate(column: 'tickets_count_all', count: true)]
#[NestedSetAggregate(column: 'tickets_avg',       avg:   'tickets')]
#[NestedSetAggregate(column: 'tickets_min',       min:   'tickets')]
#[NestedSetAggregate(column: 'tickets_max',       max:   'tickets')]
class Area extends Model implements HasNestedSet
{
    use NodeTrait;
}
```

For a tree where `Root(tickets=100) > A(50) > A1(50)` and `Root > B(25)`:

```php
$root->refresh()->tickets_total;    // 225  (100 + 50 + 50 + 25)
$root->tickets_count_all;           // 4
$root->tickets_avg;                 // 56.25
$root->tickets_min;                 // 25
$root->tickets_max;                 // 100
```

Every node carries its own subtree's rollup. Inserts, source-column
updates, deletes, moves and soft-delete restores all keep the stored
values current.

## In this section

- [Migration & Setup](setup.html) — adding aggregate columns and the
  model conventions that keep them honest
- [Reading Values](reading.html) — stored vs fresh recomputation
- [Declaring Aggregates](declaring.html) — attribute and method-override
  forms
- [Filtered Aggregates](filtered.html) — equality, not-null, and raw
  SQL filters
- [Listener Aggregates](listeners.html) — PHP-computed contributions
- [Recipes](recipes.html) — common shapes
- [Maintenance](maintenance.html) — what runs when, plus integrity tooling
- [Drift & Limitations](drift.html) — when stored values can lag and
  how to mitigate
