# Testing Helpers

Drop the `InteractsWithTrees` trait into your PHPUnit test classes to shorten the boilerplate around assertions on tree state:

```php
use Vusys\NestedSet\Testing\InteractsWithTrees;

final class CategoryTreeTest extends TestCase
{
    use InteractsWithTrees;

    public function test_appending_child_keeps_tree_intact(): void
    {
        $root = Category::factory()->create();
        $child = new Category(['name' => 'child']);
        $child->appendToNode($root)->save();

        $this->assertIsRoot($root->refresh());
        $this->assertIsChildOf($child, $root);
        $this->assertIsLeaf($child);
        $this->assertHasChildren($root, 1);
        $this->assertHasDescendants($root, 1);
        $this->assertTreeIsIntact(Category::class);
    }
}
```

## Available assertions

| Assertion | What it checks |
|---|---|
| `assertIsRoot($node)` | `parent_id` is `NULL` |
| `assertIsLeaf($node)` / `assertIsNotLeaf($node)` | `rgt = lft + 1` (no descendants) |
| `assertIsChildOf($node, $parent)` | direct parent: `parent_id` matches, `depth = parent.depth + 1` |
| `assertIsDescendantOf($node, $ancestor)` | strict containment via `NodeBounds::contains()` |
| `assertIsAncestorOf($a, $b)` | symmetric counterpart of the above |
| `assertHasDescendants($node, $count)` | exact descendant count, derived from `(rgt - lft - 1) / 2` (no extra query) |
| `assertHasChildren($node, $count)` | exact direct-child count (one query) |
| `assertAggregateMatchesFresh($node, $column)` | stored aggregate equals freshly-computed value, with numeric tolerance |
| `assertTreeIsIntact($modelClass, ?$anchor)` | wraps `isBroken()`; failure message includes `countErrors()` breakdown |
| `assertAggregatesAreIntact($modelClass, ?$anchor)` | wraps `aggregatesAreBroken()`; failure message includes per-column drift; fails fast with a clear message when the model declares no aggregates |

The trait depends only on the `HasNestedSet` contract for parameters that don't need DB access, and on `Model & HasNestedSet` for the few that do (`assertHasChildren`, `assertAggregateMatchesFresh`).
