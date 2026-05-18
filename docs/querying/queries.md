# Tree Queries

The model query builder (`TreeQueryBuilder`) adds tree-aware scopes
that compose with regular Eloquent constraints. Every scope takes a
`NodeBounds` value (returned by `$node->getBounds()`) so you can query
by structure even when you don't have a hydrated model handy.

## Example tree

The examples below assume this `Category` tree, where each row is
labelled with its `(lft, rgt)` interval:

```text
Electronics      (1, 12)
├── Computers    (2, 7)
│   ├── Laptops  (3, 4)
│   └── Desktops (5, 6)
└── Phones       (8, 11)
    └── Android  (9, 10)

Books            (13, 18)
├── Fiction      (14, 15)
└── Non-fiction  (16, 17)
```

## Descendant / ancestor scopes

`whereDescendantOf` returns every node strictly *below* the given
bounds; the `*OrSelf` variant includes the bounds row itself.

```php
$electronics = Category::firstWhere('name', 'Electronics');

Category::query()
    ->whereDescendantOf($electronics->getBounds())
    ->pluck('name');
// → ['Computers', 'Laptops', 'Desktops', 'Phones', 'Android']

Category::query()
    ->whereDescendantOrSelf($electronics->getBounds())
    ->pluck('name');
// → ['Electronics', 'Computers', 'Laptops', 'Desktops', 'Phones', 'Android']
```

`whereAncestorOf` walks upward — every node strictly *above* the
bounds. Useful for breadcrumbs.

```php
$laptops = Category::firstWhere('name', 'Laptops');

Category::query()
    ->whereAncestorOf($laptops->getBounds())
    ->defaultOrder()
    ->pluck('name');
// → ['Electronics', 'Computers']

Category::query()
    ->whereAncestorOrSelf($laptops->getBounds())
    ->defaultOrder()
    ->pluck('name');
// → ['Electronics', 'Computers', 'Laptops']
```

## Roots, leaves, ordering

```php
Category::query()->whereIsRoot()->pluck('name');
// → ['Electronics', 'Books']

Category::query()->whereIsLeaf()->pluck('name');
// → ['Laptops', 'Desktops', 'Android', 'Fiction', 'Non-fiction']

// One-shot when you know the tree has a single root:
Category::query()->root();   // ?Category — null on empty / multi-root tables

// Ordering by lft yields depth-first traversal order
Category::query()->defaultOrder()->pluck('name');
// → ['Electronics', 'Computers', 'Laptops', 'Desktops', 'Phones',
//    'Android', 'Books', 'Fiction', 'Non-fiction']

// reversed() orders by lft DESC — useful when you want bottom-up walks
Category::query()->reversed()->pluck('name');

// withDepth() selects the depth column under the alias 'depth'
Category::query()->withDepth()->get();
```

## Positional scopes

`whereIsBefore` / `whereIsAfter` slice the tree at a node's bounds.
Useful when you need "all rows preceding this one in depth-first
order" — e.g. for next-prev navigation in a sequenced tree.

```php
$phones = Category::firstWhere('name', 'Phones');

Category::query()
    ->whereIsBefore($phones->getBounds())
    ->defaultOrder()
    ->pluck('name');
// → ['Electronics', 'Computers', 'Laptops', 'Desktops']

Category::query()
    ->whereIsAfter($phones->getBounds())
    ->defaultOrder()
    ->pluck('name');
// → ['Books', 'Fiction', 'Non-fiction']
```

## Composing with Eloquent

These scopes are regular query-builder constraints — combine them with
`where`, `whereBelongsTo`, `with(...)`, eager-load constraints, joins,
ordering, anything Eloquent supports:

```php
// "Active descendants of Electronics, eager-load children, paginate"
Category::query()
    ->whereDescendantOf($electronics->getBounds())
    ->where('active', true)
    ->with('children')
    ->defaultOrder()
    ->paginate(20);
```
