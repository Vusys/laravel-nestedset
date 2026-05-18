# Bulk Tree Insertion

Seeding a large tree by calling `appendToNode()->save()` per node is
O(N²) — each save shifts every post-insertion-point row through a
CASE-WHEN UPDATE, so the gap-shift cost piles up over the whole
insert. `bulkInsertTree()` collapses that to one `makeGap` plus one
`fixAggregates`, while keeping every Eloquent guarantee (events,
mutators, casts, mass-assignment) and returning fully hydrated models:

```php
$root = new Area(['name' => 'Engineering', 'tickets' => 0]);
$root->saveAsRoot();
$root = $root->refresh();

[$backend, $api, $db, $frontend] = Area::bulkInsertTree([
    ['name' => 'Backend', 'tickets' => 5, 'children' => [
        ['name' => 'API',      'tickets' => 3],
        ['name' => 'Database', 'tickets' => 2],
    ]],
    ['name' => 'Frontend', 'tickets' => 1],
], appendTo: $root);

$backend->name;            // 'Backend' — mass-assigned via $fillable
$backend->wasRecentlyCreated; // true
$api->parent_id === $backend->getKey(); // true
```

Each row goes through a normal `save()`, so per-row `creating` /
`saving` / `created` / `saved` events still fire, every cast applies,
observers run, mass-assignment guards are respected. The only
operations the package does on top of `save()` are the one-shot
`makeGap` (replaces N gap-shifts) and the deferred `fixAggregates`
at the end of the call (replaces N aggregate-ancestor UPDATEs).

## Performance

| Backend | N=100 (vs naive) | N=1000 (vs naive) |
|---|---|---|
| SQLite | 18ms (3.2× faster) | 224ms (3.2× faster) |
| MySQL 8 | 44ms (3.0× faster) | 302ms (6.0× faster) |
| MariaDB | 48ms (6.6× faster) | 451ms (11.9× faster) |

The win widens as N grows because the naive path's gap-shift cost is
O(N²). At N=10,000 on MariaDB the naive loop runs for many minutes;
`bulkInsertTree` scales roughly linearly.

## Event-free seeding

If you specifically need event-free seeding (e.g. backfilling 100K rows
from a CSV with no observer side-effects), the standard Laravel escape
hatch composes:

```php
Model::withoutEvents(static fn () => Area::bulkInsertTree($rows, appendTo: $root));
```

## Constraints

- Rows must not contain `lft`, `rgt`, `depth`, `parent_id`, or the
  primary key — those are computed by the package.
- Scoped models (those with `#[NestedSetScope]` or `getScopeAttributes()`)
  require an `$appendTo` anchor — the scope-column values are copied
  from it onto every inserted row.
- Wrapped in a transaction; if any per-row `save()` throws, the
  gap-open and any prior inserts roll back together.
