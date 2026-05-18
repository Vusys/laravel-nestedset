# Your First Tree

This page walks through creating a small category tree and performing the
operations you'll reach for first: insert, move, and query.

## Create a root

A root is any node with no parent.

```php
$root = Category::create(['name' => 'All categories']);
```

## Add children

```php
$electronics = Category::create(['name' => 'Electronics']);
$root->appendNode($electronics);

$root->children()->create(['name' => 'Books']);
```

Both forms work. `appendNode` is useful when you already have the child
loaded; the relation-style `children()->create()` is closer to how you'd
build an adjacency tree in plain Eloquent.

## Read the tree back

```php
$tree = Category::get()->toTree();
```

`toTree()` walks the flat collection and assembles the hierarchy in memory
using the `_lft` / `_rgt` ranges — one query, full tree.

## Move a subtree

```php
$books = Category::where('name', 'Books')->first();
$books->appendToNode($electronics)->save();
```

`Books` and all its descendants are now under `Electronics`. The package
handles the range shift for every affected row in a single transaction.

## What's next

- [Inserting Nodes](inserting.html) — every way to position a new node
- [Ancestors & Descendants](ancestors-descendants.html) — read patterns
- [Aggregates Overview](aggregates/overview.html) — denormalised counts kept
  in sync automatically
