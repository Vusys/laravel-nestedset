# vusys/laravel-nestedset

A modern Laravel implementation of the nested-set model for hierarchical
data — strict types throughout, PHPStan level 9, atomic CASE-WHEN mutations,
multi-tree scoping, soft-delete cascade, and an opinionated repair toolkit.

Target: **PHP 8.3+** / **Laravel 11, 12, 13**.

```php
$root = Category::create(['name' => 'Root']);
$root->saveAsRoot();

$child = Category::create(['name' => 'Child']);
$child->appendToNode($root)->save();

Category::query()->whereDescendantOf($root->getBounds())->get();
$root->descendants()->orderBy('lft')->get();
$root->refresh()->getNodeHeight();   // rgt - lft + 1
```

## Why nested set?

The nested-set encoding stores `lft` and `rgt` integers on every node so any
subtree, ancestor chain, or descendant set is a single `BETWEEN` query — no
recursive CTEs, no N+1 loops. The price is that mutations (insert / move /
delete) have to shift many rows to keep the lft/rgt sequence dense, so it's
best suited to **read-heavy hierarchies**: category trees, menu structures,
org charts, comment threads.

This package executes every shift as a single `CASE WHEN UPDATE`, so even a
subtree move that touches thousands of rows is one round trip.

---

## Installation

```bash
composer require vusys/laravel-nestedset
```

The service provider auto-registers Blueprint macros and publishes config.

```bash
php artisan vendor:publish --provider="Vusys\NestedSet\NestedSetServiceProvider" --tag=nestedset-config
```

---

## Migration

The `$table->nestedSet()` Blueprint macro adds the four maintained columns
and a composite index that covers the common ancestor/descendant range
lookups.

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->nestedSet();   // lft, rgt, parent_id (nullable), depth + index
            $table->softDeletes(); // optional — see "Soft deletes" below
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
```

For a scoped (multi-tree) table, declare the scope column **first** in the
composite index so each tree gets its own index slice:

```php
$table->index(['post_id', 'lft', 'rgt', 'parent_id']);
```

To remove the columns later: `$table->dropNestedSet()`.

---

## Model setup

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

The trait satisfies the `HasNestedSet` interface out of the box — you only
need to implement methods yourself if you're storing nested-set data on
columns the trait can't derive from your `protected $casts`.

---

## Inserting and moving nodes

Every mutation is a method on the model that queues a pending operation;
the actual work happens on the next `save()`, wrapped in a transaction
(configurable, on by default).

```php
$root  = new Category(['name' => 'Root']);
$root->saveAsRoot();

$a = new Category(['name' => 'A']);
$a->appendToNode($root)->save();         // last child of $root

$first = new Category(['name' => 'First']);
$first->prependToNode($root->refresh())->save();   // first child of $root

$before = new Category(['name' => 'Before']);
$before->insertBeforeNode($a->refresh())->save();  // sibling before $a

$after = new Category(['name' => 'After']);
$after->insertAfterNode($a->refresh())->save();    // sibling after $a

$a->makeRoot()->save();   // detach to its own tree
$a->saveAsRoot();         // shorthand for makeRoot()->save()

$a->up();                 // swap with previous sibling (returns bool)
$a->down();               // swap with next sibling
```

**Important**: pass a fresh copy of the parent / sibling (`->refresh()`)
when you've inserted other rows since loading it. The trait re-reads the
target's bounds from the database before mutating to stay safe against
stale parent_ids, but the trait can't refresh nodes you handed it.

### Cross-tree moves

`appendToNode` and friends accept any `HasNestedSet` of the same model
class. Moving between scopes is rejected with `ScopeViolationException` —
see [Scoping](#scoping).

---

## Querying

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

### Eloquent relations

```php
$node->parent;        // BelongsTo
$node->children;      // HasMany — scope-applied
$node->ancestors;     // custom relation (eager-loadable)
$node->descendants;   // custom relation (eager-loadable)

// Eager loading is 2 queries total, no N+1:
Category::with('ancestors')->get();
Category::with('descendants')->get();

// whereHas works too:
Category::whereHas('descendants', fn ($q) => $q->where('active', true))->get();
```

### In-memory tree shaping

When you've already fetched a flat result, build the tree without extra
queries:

```php
$flat = Category::query()->defaultOrder()->get();   // returns NodeCollection

$flat->linkNodes();           // populate parent/children relations in place
$tree = $flat->toTree();      // top-level nodes with children attached
$dfs  = $flat->toFlatTree();  // depth-first flatten preserving sibling order
```

`toTree()` and `toFlatTree()` accept an optional `$root` (a HasNestedSet
node) when the collection is a subtree; otherwise the implicit root is
inferred from the smallest-lft node's parent_id.

---

## Inspection

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

---

## Scoping (forests of independent trees in one table)

Declare the partition column with the `#[NestedSetScope]` attribute and the
package automatically constrains every internal write to that scope:

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

For dynamic scopes that need runtime resolution, override `getScopeAttributes()`
instead — the attribute takes precedence when both are present.

Read queries compose with regular Eloquent — no special API needed:

```php
MenuItem::query()->whereBelongsTo($menu)->whereIsRoot()->first();
MenuItem::query()->whereBelongsTo($menu)->whereDescendantOf($node->getBounds())->get();
```

Cross-scope writes throw:

```php
$menu1Item->appendToNode($menu2Item);  // → ScopeViolationException
```

---

## Soft deletes

When the model uses `SoftDeletes`, the trait cascades on delete and restore:

```php
class Category extends Model implements HasNestedSet
{
    use NodeTrait, SoftDeletes;
}

$category->delete();    // soft-deletes the whole subtree (same deleted_at stamp)
$category->restore();   // restores only descendants stamped with that same
                        // deleted_at — independent soft-deletes coexist
```

A descendant that was independently trashed before the parent gets a
different `deleted_at` and is left alone when the parent restores; restore
it separately to bring it back.

---

## Tree repair

Production tables get corrupted — failed migrations, manual SQL surgery,
bugs in old code. The repair toolkit lets you validate and rebuild:

```php
Category::isBroken();                       // bool
Category::countErrors();
// ['invalid_bounds' => 0, 'duplicate_lft' => 2, 'duplicate_rgt' => 0, 'orphans' => 1]

Category::fixTree();                        // rebuilds lft/rgt/depth from parent_id
// → TreeFixResult { nodesUpdated: 15, errors: [...counts after repair...] }
```

On a scoped model, an anchor node is required so the repair stays inside
one tree (this prevents accidental full-table walks on multi-million-row
forests):

```php
MenuItem::isBroken();                       // ScopeViolationException — no anchor
MenuItem::isBroken($anyNodeFromThatMenu);   // OK — scoped to that menu

MenuItem::fixTree($anchor);                 // repair one menu's tree
```

---

## Transactions

Mutations are wrapped in a database transaction by default — if the
`makeGap` succeeds but the row write fails (or vice versa), the gap is
rolled back instead of being left in the tree:

```php
// config/nestedset.php
return [
    'auto_transaction' => true,  // wrap each saving event in DB::transaction
];
```

Opt out only if you're already inside an outer transaction and want exact
control over its boundary:

```php
DB::transaction(function () use ($parent): void {
    // multiple linked mutations atomically
    $a->appendToNode($parent)->save();
    $b->appendToNode($parent)->save();
});
```

Auto-wrapping is safe to combine with outer transactions — Laravel handles
nested transactions via savepoints.

---

## Configuration

`config/nestedset.php`:

```php
return [
    'columns' => [
        'lft'       => 'lft',
        'rgt'       => 'rgt',
        'parent_id' => 'parent_id',
        'depth'     => 'depth',
    ],

    'auto_transaction' => true,
];
```

Column names are read globally — change them once in config and every model
using `NodeTrait` picks up the new names via the `getLftName()` / `getRgtName()`
/ `getParentIdName()` / `getDepthName()` accessors.

To use different column names per model, override those accessors on the
model:

```php
class Category extends Model implements HasNestedSet
{
    use NodeTrait;

    public function getLftName(): string  { return 'tree_lft'; }
    public function getRgtName(): string  { return 'tree_rgt'; }
}
```

---

## Comparison vs. `kalnoy/nestedset`

This package is a modern reimplementation — not a fork. Key differences:

| Dimension | `kalnoy/nestedset` v7 | `vusys/laravel-nestedset` |
|---|---|---|
| PHP minimum | 8.0 | **8.3** |
| Laravel | 13 | **11, 12, 13** |
| `declare(strict_types=1)` | No | Yes |
| Required interface | None | `HasNestedSet` |
| Static analysis | None | **Larastan level 9, no baseline** |
| Pint / Rector | None | Yes |
| Test count | 86 | 157 |
| Auto transactions | No (footgun) | **On by default, opt-out via config** |
| Depth column | Computed subquery | **Stored, maintained on mutation** |
| Scoping API | Method-based | Attribute (`#[NestedSetScope]`) + method |
| Scoped `fixTree()` | Walks whole table | **Refuses without anchor** |
| Position constants | `int` | `enum Position` |
| Bounds | Untyped tuple | `readonly class NodeBounds` |
| Repair result | `int` | `readonly class TreeFixResult` |

---

## Contributing

Run the full check suite before opening a PR:

```bash
composer pint:check    # style
composer rector:check  # automated refactors
composer analyse       # static analysis
composer test          # unit + feature
```

All four must pass on CI before merge.

---

## License

MIT. See [LICENSE](LICENSE).
