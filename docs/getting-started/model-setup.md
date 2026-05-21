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
  `isAncestorOf`, `isPlacedInTree`, `getSubtreeSize`,
  `getDescendantCount`. See [Inspection](../querying/inspection.html).
- Repair: `isBroken`, `countErrors`, `fixTree`. See
  [Tree Repair](../maintenance/fix-tree.html).
- Optional aggregate maintenance when you declare
  `#[NestedSetAggregate]` attributes. See
  [Aggregates Overview](../aggregates/overview.html).
- Typed events fired on Laravel's event bus around every meaningful
  operation — moves, cascades, repairs, aggregate maintenance, plus
  model-carrying events for in-process indexing and cache priming. See
  [Events](../reference/events.html).

## Soft deletes

If you add Laravel's `SoftDeletes` trait, the package cascades
delete and restore through the subtree. See
[Soft Deletes](../tree-operations/soft-deletes.html).

## Scoped (multi-tenant) models

To partition one table into many independent trees — per-tenant menus,
per-user folder structures — add `#[NestedSetScope]`. See
[Scoped Trees](../querying/scoped-trees.html).

## Exceptions

The trait throws three typed exceptions that all extend
`\LogicException` — they signal programmer error rather than runtime
conditions you'd expect to recover from.

- **`Vusys\NestedSet\Exceptions\UnplacedNodeException`** — `save()`
  was called on a new node that hasn't been placed in any tree (no
  `appendToNode` / `prependToNode` / `insertBeforeNode` /
  `insertAfterNode` / `makeRoot`). Check
  `$node->isPlacedInTree()` if you need to gate the call.
- **`Vusys\NestedSet\Exceptions\ScopeViolationException`** — a write
  crossed scope boundaries on a `#[NestedSetScope]` model
  (`appendToNode($parentInDifferentScope)`), or a scoped model called
  `fixTree` / `fixAggregates` / `bulkInsertTree` / `aggregateErrors`
  without the required `?HasNestedSet $anchor` argument. The anchor
  rule prevents accidental full-table walks across millions of rows in
  many trees. See [Scoped Trees](../querying/scoped-trees.html).
- **`Vusys\NestedSet\Exceptions\AggregateConfigurationException`** —
  thrown at registry build time when a `#[NestedSetAggregate]`
  declaration is malformed (zero or multiple aggregate functions, more
  than one filter form, empty `filterRawWatches` without the
  no-column-dependencies opt-in). Boot-time failure by design —
  surfaces config errors before they become silent drift in
  production. See [Aggregates → Declaring](../aggregates/declaring.html)
  and [Filtered Aggregates](../aggregates/filtered.html).
