# Scoped Trees

> Forests of independent trees in one table.

Declare the partition column with the `#[NestedSetScope]` attribute and
the package automatically constrains every internal write to that scope:

```php
use Vusys\NestedSet\Attributes\NestedSetScope;

#[NestedSetScope('menu_id')]
class MenuItem extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $fillable = ['name', 'menu_id'];
}
```

Multi-column scopes work too: `#[NestedSetScope(['tenant_id', 'menu_id'])]`.

For dynamic scopes that need runtime resolution, override
`getScopeAttributes()` instead — the attribute takes precedence when both
are present.

## Reading

Read queries compose with regular Eloquent — no special API needed:

```php
MenuItem::query()->whereBelongsTo($menu)->whereIsRoot()->first();
MenuItem::query()->whereBelongsTo($menu)->whereDescendantOf($node->getBounds())->get();
```

## Writes are scope-checked

Cross-scope writes throw:

```php
$menu1Item->appendToNode($menu2Item);  // → ScopeViolationException
```
