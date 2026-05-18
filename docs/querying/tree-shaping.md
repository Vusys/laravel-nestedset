# In-memory Tree Shaping

When you've already fetched a flat result, build the tree without extra
queries:

```php
$flat = Category::query()->defaultOrder()->get();   // returns NodeCollection

$flat->linkNodes();           // populate parent/children relations in place
$tree = $flat->toTree();      // top-level nodes with children attached
$dfs  = $flat->toFlatTree();  // depth-first flatten preserving sibling order
```

`toTree()` and `toFlatTree()` accept an optional `$root` (a `HasNestedSet`
node) when the collection is a subtree; otherwise the implicit root is
inferred from the smallest-lft node's parent_id.
