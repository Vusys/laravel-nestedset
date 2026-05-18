# Your First Tree

This page walks through creating a small category tree and performing the
operations you'll reach for first: insert, move, and query.

## Create a root

A root is any node with no parent.

```php
$root = Category::create(['name' => 'All categories']);
```

## Add children

Build the child, then position it relative to its parent. The mutation is
deferred until `save()` so the lft/rgt shift happens in a single
transaction.

```php
$electronics = new Category(['name' => 'Electronics']);
$electronics->appendToNode($root)->save();

$books = new Category(['name' => 'Books']);
$books->appendToNode($root)->save();
```

`prependToNode`, `insertBeforeNode`, and `insertAfterNode` give you the
other positions.

## Read the tree back

```php
$tree = Category::get()->toTree();
```

`toTree()` walks the flat collection and assembles the hierarchy in memory
using the `lft` / `rgt` ranges — one query, full tree.

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
