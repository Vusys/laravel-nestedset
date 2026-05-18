# Inspection

```php
$node->isRoot();                  // parent_id is null
$node->isLeaf();                  // rgt - lft === 1
$node->isChild();                 // !isRoot()
$node->isDescendantOf($other);
$node->isAncestorOf($other);
$node->isSiblingOf($other);
$node->getNodeHeight();           // rgt - lft + 1
$node->getDescendantCount();      // (rgt - lft - 1) / 2
$node->hasMoved();                // true after a mutation this request
```

The `NodeBounds` value object that powers the query scopes carries the
same primitives, useful when you have bounds but not a model instance:

```php
$bounds = $node->getBounds();

$bounds->height();                // rgt - lft + 1
$bounds->contains($other);        // strict containment (descendant test)
$bounds->depthDelta($other);      // signed depth difference
```
