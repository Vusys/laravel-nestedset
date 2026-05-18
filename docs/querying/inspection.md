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

`getNodeHeight()` counts the slots in the interval — every node
including itself.

```php
$electronics->getNodeHeight();      // 6   ((12 - 1 + 1) / 2 = 6)
$electronics->getDescendantCount(); // 5   (height - 1)
$laptops->getNodeHeight();          // 1
$laptops->getDescendantCount();     // 0
```

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
