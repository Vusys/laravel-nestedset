# Inspection

Reading-only methods that answer "where am I in the tree?" without
firing any extra queries — they all derive from the row's stored
`lft` / `rgt` / `depth` / `parent_id` values.

For the examples below, assume the [Category tree from Tree Queries](queries.html):

```text
Electronics      (1, 12, depth 0)
├── Computers    (2, 7,  depth 1)
│   ├── Laptops  (3, 4,  depth 2)
│   └── Desktops (5, 6,  depth 2)
└── Phones       (8, 11, depth 1)
    └── Android  (9, 10, depth 2)
```

## Boolean predicates

```php
$electronics->isRoot();         // true   — parent_id is null
$laptops->isRoot();             // false

$laptops->isLeaf();             // true   — rgt - lft === 1
$electronics->isLeaf();         // false

$laptops->isChild();            // true   — !isRoot()

$laptops->isDescendantOf($electronics);   // true
$laptops->isDescendantOf($phones);        // false

$electronics->isAncestorOf($laptops);     // true
$electronics->isAncestorOf($books);       // false

$laptops->isSiblingOf($desktops);         // true   — same parent
$laptops->isSiblingOf($android);          // false

$node->hasMoved();              // true after a mutation this request
```

## Subtree size

`getSubtreeSize()` returns the raw `rgt - lft + 1` interval width — two
slots per node, so a leaf is `2` and a subtree of `N` nodes is `2 * N`.
`getDescendantCount()` is the more useful "count of strict descendants"
view, derived as `(rgt - lft - 1) / 2`.

```php
$electronics->getSubtreeSize();     // 12   (rgt - lft + 1 = 12 - 1 + 1)
$electronics->getDescendantCount(); // 5    ((12 - 1 - 1) / 2)
$laptops->getSubtreeSize();         // 2
$laptops->getDescendantCount();     // 0
```

> `getNodeHeight()` is the legacy name; it still works (it delegates
> to `getSubtreeSize()`) but is deprecated — the old name suggested
> tree-theory height (max depth of a descendant) but the method
> always returned the lft/rgt slot count.

Both are pure arithmetic on the row's columns — they cost nothing.

## NodeBounds — inspection without a model

When you have just the bounds (e.g. cached from elsewhere) and not a
hydrated row, the same primitives live on `NodeBounds`:

```php
$bounds = $electronics->getBounds();    // readonly NodeBounds

$bounds->height();                       // 6
$bounds->contains($laptops);             // true — strict containment
$bounds->depthDelta($laptops);           // 2     — laptops is 2 levels below
$bounds->depthDelta($computers);         // 1
$bounds->depthDelta($electronics);       // 0     — same node
```

`contains()` is the engine behind `whereDescendantOf` — the same
interval-test, just in PHP rather than SQL.
