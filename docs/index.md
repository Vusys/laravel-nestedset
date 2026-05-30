<div class="hero">
<h1>laravel-nestedset</h1>
<p class="hero-tagline">A modern nested-set implementation for Laravel. Atomic subtree moves, automatically maintained aggregate roll-ups, multi-tree scoping, soft-delete cascade, and an opinionated repair toolkit — with strict types and PHPStan level 9 throughout.</p>
</div>

```php
use App\Models\BudgetItem;

$root = new BudgetItem(['name' => 'Engineering']);
$root->saveAsRoot();

$child = new BudgetItem(['name' => 'Salaries', 'cost' => 28000]);
$child->appendToNode($root)->save();

$child->depth;                       // 1
$child->isLeaf();                    // true
$child->isDescendantOf($root);       // true
$child->parent;                      // $root  (Eloquent relation, eager-loadable)
$child->ancestors()->get();          // collection containing $root

$root->refresh();                    // re-read parent bounds after the append
$root->descendants()->get();         // collection containing $child
$root->getDescendantCount();         // 1  (descendants, excluding self; +1 for total nodes in subtree)
```

Select a node below to see how its `lft`/`rgt` interval wraps its whole subtree — that containment is the entire trick. Selecting a node reveals the range query behind it:

```ns-tree
Engineering
  People
    Salaries {cost=26000}
    Bonuses {cost=2000}
  Tools
    SaaS {cost=2500}
    Hardware {cost=1500}
Operations
  Office {cost=1500}
```

Declare aggregates on the model and the SUM / COUNT / AVG / MIN / MAX roll-ups are maintained automatically as the tree changes:

```php
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Export\AsciiOptions;
use Vusys\NestedSet\NodeTrait;

#[NestedSetAggregate(column: 'cost_total',      sum:   'cost')]
#[NestedSetAggregate(column: 'item_count',      count: true)]
#[NestedSetAggregate(column: 'avg_cost',        avg:   'cost')]
#[NestedSetAggregate(column: 'biggest_item',    max:   'cost')]
#[NestedSetAggregate(column: 'recurring_total', sum:   'cost', filter: ['recurring' => true])]
class BudgetItem extends Model implements HasNestedSet { use NodeTrait; }

// Render the forest with each node's own cost + rolled-up subtree total:
$render = fn () => BudgetItem::toAsciiTreeForest(new AsciiOptions(
    label: fn ($n) => "{$n->name}  (cost = {$n->cost}, total = {$n->cost_total})",
));

echo $render();
// Engineering              (cost = 0,     total = 32000)
// ├── People               (cost = 0,     total = 28000)
// │   ├── Salaries         (cost = 26000, total = 26000)
// │   └── Bonuses          (cost = 2000,  total = 2000)
// └── Tools                (cost = 0,     total = 4000)
//     ├── SaaS             (cost = 2500,  total = 2500)
//     └── Hardware         (cost = 1500,  total = 1500)
// Operations               (cost = 0,     total = 1500)
// └── Office               (cost = 1500,  total = 1500)

BudgetItem::query()->where('name', '=', 'Bonuses')->first()->update(['cost' => 4000]);
BudgetItem::query()->where('name', '=', 'Engineering')->first()->refresh()->cost_total;   // 34000  — every ancestor updates (Bonuses → People → Engineering)

// move the whole Tools subtree (3 nodes) under Operations — one statement
BudgetItem::query()->where('name', '=', 'Tools')->first()
    ->moveTo(BudgetItem::query()->where('name', '=', 'Operations')->first())
    ->save();
echo $render();
// Engineering              (cost = 0,     total = 30000)   ← old parent shrank (Tools left, taking 4000)
// └── People               (cost = 0,     total = 30000)
//     ├── Salaries         (cost = 26000, total = 26000)
//     └── Bonuses          (cost = 4000,  total = 4000)
// Operations               (cost = 0,     total = 5500)    ← new parent grew by the whole moved subtree
// ├── Office               (cost = 1500,  total = 1500)
// └── Tools                (cost = 0,     total = 4000)
//     ├── SaaS             (cost = 2500,  total = 2500)
//     └── Hardware         (cost = 1500,  total = 1500)

BudgetItem::query()->withFreshAggregates()->get();   // ad-hoc correlated recomputation (read-only — see Aggregates → Drift)
```

## Why nested set?

The nested-set encoding stores `lft` and `rgt` integers on every node so any subtree, ancestor chain, or descendant set is a single `BETWEEN` query — no recursive CTEs, no N+1 loops. The price is that mutations (insert / move / delete) have to shift many rows to keep the lft/rgt sequence dense, so it's best suited to **read-heavy hierarchies**: category trees, menu structures, org charts, comment threads.

This package executes every shift as a single `CASE WHEN UPDATE`, so even a subtree move that touches thousands of rows is one round trip.

> [!TIP]
> `parent_id` is the source of truth. The `lft`/`rgt`/`depth` columns are a derived index — if they ever drift, [`fixTree()`](maintenance/fix-tree.html) rebuilds them from a `parent_id` walk.

## What's in this documentation

Start with [Installation](getting-started/installation.html), then [Migration](getting-started/migration.html) and [Model Setup](getting-started/model-setup.html) to get a working tree. From there the sidebar groups material by what you're trying to do — insert/move, query, compute aggregates, repair corruption.
