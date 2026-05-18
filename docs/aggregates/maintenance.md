# Maintenance

Aggregates ride the package's existing lifecycle events:

| Mutation                  | Path                                      | Extra UPDATEs |
|---------------------------|-------------------------------------------|---------------|
| Insert leaf               | cheap-delta (SUM/COUNT/MIN/MAX) + AVG ratio | 1            |
| Source-column update      | cheap-delta + recompute for invalidated extremum | 1 or 2 |
| Delete                    | delta subtract + recompute for invalidated extremum | 1 or 2 |
| Move (`appendToNode` etc.)| delta on old chain + delta on new chain   | 2            |
| Soft-delete restore       | delta re-add to current chain             | 1            |

MIN/MAX use a SELECT-then-UPDATE recompute path when the change may
have invalidated the stored extremum — controlled by the
`nestedset.aggregate_locking` config flag (`'auto'` / `'always'` /
`'never'`; see [Configuration](../reference/config.html)).

## Integrity tooling

Mirrors the tree-repair API:

```php
Category::aggregateErrors();
// ['articles_total' => 0, 'articles_count_all' => 0, 'articles_avg' => 0, ...]

Category::aggregatesAreBroken();    // bool

Category::fixAggregates();
// → AggregateFixResult { totalRowsUpdated: 0, perColumn: [...] }
```

`fixTree()` runs `fixAggregates()` as a final step — corrupted lft/rgt
plus drifted aggregates are repairable in one call. The result carries
the aggregate stats alongside the tree stats:

```php
$result = Category::fixTree();
$result->nodesUpdated;       // tree side
$result->errors;             // post-repair tree errors
$result->aggregatesFixed;    // AggregateFixResult — null on no-aggregate models
```

Scoped models require an anchor on `aggregateErrors`,
`aggregatesAreBroken`, and `fixAggregates` (same as `fixTree`).

## Adding aggregates to an existing model

1. Add `#[NestedSetAggregate(...)]` declarations to the model class.
2. Add `$table->nestedSetAggregate('col_name', type: ...)` to a new
   migration; run it.
3. Add the matching cast to `$casts`.
4. Run `YourModel::fixAggregates()` once to backfill stored values from
   the source data. On scoped models, run per anchor.
5. Deploy.

After the backfill, every subsequent mutation through Eloquent keeps
the stored values current.
