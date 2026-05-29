<div class="hero">
<h1>laravel-nestedset</h1>
<p class="hero-tagline">A modern nested-set implementation for Laravel. Atomic subtree moves, automatically maintained aggregate roll-ups, multi-tree scoping, soft-delete cascade, and an opinionated repair toolkit — with strict types and PHPStan level 9 throughout.</p>
</div>

```php
use App\Models\Category;

$root = new Category(['name' => 'Root']);
$root->saveAsRoot();

$child = new Category(['name' => 'Child']);
$child->appendToNode($root)->save();

$child->depth;                       // 1
$child->isLeaf();                    // true
$child->isDescendantOf($root);       // true
$child->parent;                      // $root  (Eloquent relation, eager-loadable)
$child->ancestors()->get();          // collection containing $root

$root->refresh();                    // re-read parent bounds after the append
$root->descendants()->get();         // collection containing $child
$root->getSubtreeSize();             // 4  (slot count: rgt - lft + 1 = 2 × node count)
```

Select a node below to see how its `lft`/`rgt` interval wraps its whole subtree — that containment is the entire trick. Selecting a node reveals the range query behind it:

```ns-tree
Electronics
  Computers
    Laptops
    Desktops
  Phones
    Android
    iOS
Clothing
  Shoes
  Outerwear
```

Declare aggregates on the model and the SUM / COUNT / AVG / MIN / MAX roll-ups are maintained automatically as the tree changes:

```php
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

#[NestedSetAggregate(column: 'products_total',  sum: 'products')]
#[NestedSetAggregate(column: 'products_count',  count: true)]
#[NestedSetAggregate(column: 'in_stock_total',  sum: 'products', filter: ['in_stock' => true])]
#[NestedSetAggregate(column: 'cheapest_price',  min: 'price')]
class Category extends Model implements HasNestedSet { use NodeTrait; }

// Electronics
// ├── Laptops  (products = 10)
// └── Phones   (products = 13)

$electronics->refresh()->products_total;   // 23  — rolled up from descendants

$phones->update(['products' => 20]);
$electronics->refresh()->products_total;   // 30  — ancestors updated in the same write

$phones->moveTo($gadgets)->save();         // move the Phones subtree to a different root
$electronics->refresh()->products_total;   // 10  — old ancestors shrink
$gadgets->refresh()->products_total;       // 20  — new ancestors grow

Category::query()->withFreshAggregates()->get();   // ad-hoc correlated recomputation (read-only — see Aggregates → Drift)
```

## Why nested set?

The nested-set encoding stores `lft` and `rgt` integers on every node so any subtree, ancestor chain, or descendant set is a single `BETWEEN` query — no recursive CTEs, no N+1 loops. The price is that mutations (insert / move / delete) have to shift many rows to keep the lft/rgt sequence dense, so it's best suited to **read-heavy hierarchies**: category trees, menu structures, org charts, comment threads.

This package executes every shift as a single `CASE WHEN UPDATE`, so even a subtree move that touches thousands of rows is one round trip.

> [!TIP]
> `parent_id` is the source of truth. The `lft`/`rgt`/`depth` columns are a derived index — if they ever drift, [`fixTree()`](maintenance/fix-tree.html) rebuilds them from a `parent_id` walk.

## What's in this documentation

Start with [Installation](getting-started/installation.html), then [Migration](getting-started/migration.html) and [Model Setup](getting-started/model-setup.html) to get a working tree. From there the sidebar groups material by what you're trying to do — insert/move, query, compute aggregates, repair corruption.
