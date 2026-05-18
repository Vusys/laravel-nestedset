# Model Setup

A `Category` model — the example used throughout these docs — looks
like this:

```php
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

class Category extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $fillable = ['name'];

    protected $casts = [
        'lft'       => 'integer',
        'rgt'       => 'integer',
        'depth'     => 'integer',
        'parent_id' => 'integer',
    ];
}
```

The trait satisfies the `HasNestedSet` interface out of the box — you
only need to implement methods yourself if you're storing nested-set
data on columns the trait can't derive from your `protected $casts`.

## What `NodeTrait` gives you

- Tree mutation: `appendToNode`, `prependToNode`, `insertBeforeNode`,
  `insertAfterNode`, `makeRoot`, `saveAsRoot`, `up`, `down`.
  See [Inserting & Moving](../tree-operations/inserting.html).
- Tree-aware query scopes on the model's builder. See
  [Tree Queries](../querying/queries.html).
- Eloquent relations: `parent`, `children`, `ancestors`, `descendants`.
  See [Eloquent Relations](../querying/relations.html).
- Inspection: `isRoot`, `isLeaf`, `isChild`, `isDescendantOf`,
  `isAncestorOf`, `getNodeHeight`, `getDescendantCount`.
  See [Inspection](../querying/inspection.html).
- Repair: `isBroken`, `countErrors`, `fixTree`. See
  [Tree Repair](../maintenance/fix-tree.html).
- Optional aggregate maintenance when you declare
  `#[NestedSetAggregate]` attributes. See
  [Aggregates Overview](../aggregates/overview.html).

## Soft deletes

If you add Laravel's `SoftDeletes` trait, the package cascades
delete and restore through the subtree. See
[Soft Deletes](../tree-operations/soft-deletes.html).

## Scoped (multi-tenant) models

To partition one table into many independent trees — per-tenant menus,
per-user folder structures — add `#[NestedSetScope]`. See
[Scoped Trees](../querying/scoped-trees.html).
