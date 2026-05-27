# Bulk Tree Insertion

Seeding a large tree by calling `appendToNode()->save()` per node is O(N²) — each save shifts every post-insertion-point row through a CASE-WHEN UPDATE, so the gap-shift cost piles up over the whole insert. `bulkInsertTree()` collapses that to one `makeGap` plus one `fixAggregates`, while keeping every Eloquent guarantee (events, mutators, casts, mass-assignment) and returning fully hydrated models:

```php
$root = new Category(['name' => 'All categories']);
$root->saveAsRoot();
$root = $root->refresh();

[$electronics, $computers, $laptops, $desktops, $phones, $books] = Category::bulkInsertTree([
    ['name' => 'Electronics', 'children' => [
        ['name' => 'Computers', 'children' => [
            ['name' => 'Laptops'],
            ['name' => 'Desktops'],
        ]],
        ['name' => 'Phones'],
    ]],
    ['name' => 'Books'],
], appendTo: $root);

$electronics->name;                            // 'Electronics' — mass-assigned via $fillable
$electronics->wasRecentlyCreated;              // true
$laptops->parent_id === $computers->getKey();  // true
```

The returned array is in depth-first pre-order — the same walk order as `defaultOrder()` once the rows are persisted. Top-level entries come first; each entry is immediately followed by its descendants.

## Seeding new roots (unscoped models)

Passing `appendTo: null` on an **unscoped** model seeds the input as new root(s) — the package starts one past the current `MAX(rgt)` so the new trees never overlap existing ones. Useful for first-run fixtures or admin-imported batches that should each become their own top-level tree:

```php
[$site1, $site2] = Category::bulkInsertTree([
    ['name' => 'Site 1', 'children' => [['name' => 'Home']]],
    ['name' => 'Site 2'],
]);

$site1->isRoot();   // true
$site2->isRoot();   // true
```

Scoped models reject `appendTo: null` with `ScopeViolationException` — the anchor is also where the scope-column values come from.

Each row goes through a normal `save()`, so per-row `creating` / `saving` / `created` / `saved` events still fire, every cast applies, observers run, mass-assignment guards are respected. The only operations the package does on top of `save()` are the one-shot `makeGap` (replaces N gap-shifts) and the deferred `fixAggregates` at the end of the call (replaces N aggregate-ancestor UPDATEs).

## Performance

| Backend | N=100 (vs naive) | N=1000 (vs naive) |
|---|---|---|
| SQLite | 18ms (3.2× faster) | 224ms (3.2× faster) |
| MySQL 8 | 44ms (3.0× faster) | 302ms (6.0× faster) |
| MariaDB | 48ms (6.6× faster) | 451ms (11.9× faster) |

The win widens as N grows because the naive path's gap-shift cost is O(N²). At N=10,000 on MariaDB the naive loop runs for many minutes; `bulkInsertTree` scales roughly linearly.

## Event-free seeding

If you specifically need event-free seeding (e.g. backfilling 100K rows from a CSV with no observer side effects), the standard Laravel escape hatch composes:

```php
Model::withoutEvents(static fn () => Category::bulkInsertTree($rows, appendTo: $root));
```

## Constraints

- Rows must not contain `lft`, `rgt`, `depth`, `parent_id`, or the primary key — those are computed by the package.
- **Scope columns are silently overwritten with the anchor's values.** On scoped models, every inserted row's scope-column attributes are replaced with the values read off `$appendTo` regardless of what the input row contains. This is by design (a bulk insert into one anchor's subtree always belongs to that anchor's scope) but worth knowing — passing `['tenant_id' => 99]` in a row when `$appendTo->tenant_id === 7` produces a row with `tenant_id = 7`, not an error.
- Scoped models (those with `#[NestedSetScope]` or `getScopeAttributes()`) require an `$appendTo` anchor — the scope-column values are copied from it onto every inserted row.
- Wrapped in a transaction; if any per-row `save()` throws, the gap-open and any prior inserts roll back together.
