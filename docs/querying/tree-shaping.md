# In-memory Tree Shaping

When you've already fetched a flat result, build the tree without extra queries. Every `get()` on a `NodeTrait` model returns a `NodeCollection`, which knows how to assemble itself.

> [!TIP]
> If you only need to *visit* each node — not assemble nested `children` arrays — see [Walking Subtrees](walking.md). Walking is purely in-memory and skips the children-array allocation that `toTree()` builds.

## The flat → tree transform

```php
$flat = Category::query()->defaultOrder()->get();
// → Collection of 9 categories ordered by lft:
//   Electronics, Computers, Laptops, Desktops, Phones, Android,
//   Books, Fiction, Non-fiction

$tree = $flat->toTree();
```

`$tree` is a collection of the **top-level** nodes only, each with its `children` relation populated recursively:

```php
$tree->count();                                // 2 — Electronics, Books
$tree[0]->name;                                // 'Electronics'
$tree[0]->children->count();                   // 2 — Computers, Phones
$tree[0]->children[0]->children->count();      // 2 — Laptops, Desktops
```

No extra queries fire — `toTree()` walks the already-loaded collection and rewires the relations using the `lft` / `rgt` ranges.

## Flat-with-hierarchy

`toFlatTree()` returns a single flat collection in depth-first order with `parent` and `children` relations populated — handy when you want to render a tree with `<ul>`-style indenting but don't want to recurse:

```php
foreach ($flat->toFlatTree() as $node) {
    echo str_repeat('  ', $node->depth) . $node->name . "\n";
}
// Electronics
//   Computers
//     Laptops
//     Desktops
//   Phones
//     Android
// Books
//   Fiction
//   Non-fiction
```

## Subtree shaping

Both `toTree()` and `toFlatTree()` accept an optional `$root` argument when your collection is *a subtree, not the whole table*. Without it, the implicit root is inferred from the smallest-lft node's `parent_id`.

```php
// Just Electronics + descendants, shaped as a subtree
$subtree = Category::query()
    ->whereDescendantOrSelf($electronics->getBounds())
    ->defaultOrder()
    ->get()
    ->toTree($electronics);
```

## Linking nodes without restructuring

If you want the relations populated on the original flat collection — e.g. so each node can call `->parent` or `->children` without lazy loading — but don't want to re-shape into a tree, call `linkNodes()`:

```php
$flat->linkNodes();
$flat[3]->parent->name;                  // resolved without a query
$flat[0]->children->pluck('name');       // ['Computers', 'Phones']
```

`toTree()` calls `linkNodes()` internally, so the relations are already populated on the tree result too.
