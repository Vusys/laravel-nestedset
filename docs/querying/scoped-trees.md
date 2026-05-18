# Scoped Trees

> Forests of independent trees in one table.

A **scoped** model partitions one table into many independent trees,
keyed by one or more columns. The classic case is per-tenant or
per-site menus: every customer has their own menu hierarchy, but
they all live in one `menu_items` table and share the same indexes.

Declare the partition column with `#[NestedSetScope]` and the package
constrains every internal write — gap-shifts, range queries, repair
walks — to that scope automatically:

```php
use Vusys\NestedSet\Attributes\NestedSetScope;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

#[NestedSetScope('menu_id')]
class MenuItem extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $fillable = ['name', 'menu_id'];
}
```

Multi-column scopes work too: `#[NestedSetScope(['tenant_id', 'menu_id'])]`.

For dynamic scopes that need runtime resolution, override
`getScopeAttributes()` instead — the attribute takes precedence when
both are present.

## Picturing the table

A `menu_items` table for two menus might hold:

```text
id  menu_id  name        lft  rgt  parent_id
--  -------  ----------  ---  ---  ---------
1   1        Home        1    6    null         ← menu 1's root
2   1        About       2    3    1
3   1        Contact     4    5    1
4   2        Dashboard   1    4    null         ← menu 2's root
5   2        Profile     2    3    4
```

Both menus start their `lft` at 1 — they're independent trees,
partitioned by `menu_id`.

## Reading

Reads compose with regular Eloquent — no special API:

```php
$menu = Menu::find(1);

// All root items in this menu
MenuItem::query()->whereBelongsTo($menu)->whereIsRoot()->get();

// Descendants of a specific item, within its menu
MenuItem::query()
    ->whereBelongsTo($menu)
    ->whereDescendantOf($node->getBounds())
    ->get();
```

## Writes are scope-checked

The trait refuses to move a node into a different scope:

```php
$menu1Item->appendToNode($menu2Item);
// → Vusys\NestedSet\Exceptions\ScopeViolationException
```

This is intentional: silently rewriting `menu_id` on a moved subtree
is almost never what you want, and a wrong move is hard to detect
after the fact. If you genuinely need to migrate a subtree to a
different scope, do it explicitly with a fresh insert + delete.

## Scoped repairs need an anchor

[`fixTree()`](../maintenance/fix-tree.html) and
[`fixAggregates()`](../maintenance/fix-aggregates.html) refuse to run
on a scoped model without an **anchor node** — any row that identifies
which menu you want to repair:

```php
MenuItem::fixTree();          // → ScopeViolationException
MenuItem::fixTree($anyItem);  // repairs $anyItem->menu_id only
```

That guard prevents a casual `fixTree()` call on a multi-million-row
forest from walking every tree to repair one.
