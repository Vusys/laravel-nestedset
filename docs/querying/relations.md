# Eloquent Relations

```php
$node->parent;        // BelongsTo
$node->children;      // HasMany — scope-applied
$node->ancestors;     // custom relation (eager-loadable)
$node->descendants;   // custom relation (eager-loadable)

// Eager loading is 2 queries total, no N+1:
Category::with('ancestors')->get();
Category::with('descendants')->get();

// whereHas works too:
Category::whereHas('descendants', fn ($q) => $q->where('active', true))->get();
```

## Bounding the descendants relation

The descendants relation is unbounded by default — it pulls every
descendant of every selected row. For trees with deep, wide subtrees
this can be a lot more data than the UI needs. Bound the load to the
first N levels by composing a `where` on the relation's `depth` column
(which the trait already maintains):

```php
// Just children + grandchildren of $root (depth 1 + 2 relative to root)
$root->load(['descendants' => fn ($q) => $q->where('depth', '<=', $root->depth + 2)]);

// Or on a top-level query — load every root with its first two levels
Category::with([
    'descendants' => fn ($q) => $q->where('depth', '<=', 2),
])->whereIsRoot()->get();
```

The composite index already covers `depth`, so the bounded `WHERE`
costs no more than the unbounded eager load on the same rows.
