# Tree Queries

The model query builder (`TreeQueryBuilder`) adds tree-aware scopes:

```php
use Vusys\NestedSet\NodeBounds;

$bounds = $someNode->getBounds();

Category::query()->whereDescendantOf($bounds)->get();
Category::query()->whereDescendantOrSelf($bounds)->get();
Category::query()->whereAncestorOf($bounds)->get();
Category::query()->whereAncestorOrSelf($bounds)->get();
Category::query()->whereIsRoot()->get();
Category::query()->whereIsLeaf()->get();
Category::query()->whereIsAfter($bounds)->get();
Category::query()->whereIsBefore($bounds)->get();
Category::query()->withDepth()->get();        // selects depth column
Category::query()->defaultOrder()->get();     // order by lft ASC
Category::query()->reversed()->get();         // order by lft DESC
Category::query()->leaves()->get();
Category::query()->root();                    // ?Category, the lone root
```

These scopes compose with regular Eloquent constraints — combine them
freely with `where()`, `whereBelongsTo()`, joins, etc.
