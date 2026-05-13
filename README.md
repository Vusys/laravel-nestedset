# vusys/laravel-nestedset

[![Tests](https://github.com/Vusys/laravel-vusys-nestedset/actions/workflows/tests.yml/badge.svg)](https://github.com/Vusys/laravel-vusys-nestedset/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/Vusys/laravel-vusys-nestedset/graph/badge.svg)](https://codecov.io/gh/Vusys/laravel-vusys-nestedset)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777BB4?logo=php&logoColor=white)](composer.json)
[![Laravel](https://img.shields.io/badge/laravel-11%20%7C%2012%20%7C%2013-FF2D20?logo=laravel)](composer.json)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg)](phpstan.neon)
[![Rector](https://img.shields.io/badge/Rector-passing-brightgreen.svg)](rector.php)
[![Code Style: Pint](https://img.shields.io/badge/code%20style-Laravel%20Pint-FF2D20.svg?logo=laravel)](https://github.com/laravel/pint)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

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

$a->prevSibling();        // ?static — sibling immediately before this node
$a->nextSibling();        // ?static — sibling immediately after this node
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

The `NodeBounds` value object that powers the query scopes carries the
same primitives, useful when you have bounds but not a model instance:

```php
$bounds = $node->getBounds();

$bounds->height();                // rgt - lft + 1
$bounds->contains($other);        // strict containment (descendant test)
$bounds->depthDelta($other);      // signed depth difference
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

### What gets corrupted, what's auto-fixable, and how to avoid it

The package treats **`parent_id` as the source of truth**. `fixTree()` rebuilds `lft`/`rgt`/`depth` from a `parent_id` walk, so as long as `parent_id` describes the tree you actually want, every other column is recoverable.

| Corruption | Detected by `countErrors()`? | Repaired by `fixTree()`? | Typical cause |
| --- | --- | --- | --- |
| `invalid_bounds` (`lft >= rgt`) | ✅ | ✅ | Raw `UPDATE` on `lft`/`rgt`; crashed transaction. |
| `duplicate_lft` / `duplicate_rgt` | ✅ | ✅ | Concurrent gap-shifts without locking; partial migration. |
| `orphans` (`parent_id` → missing row) | ✅ | ❌ — detected but not auto-repaired | Hard `DELETE` of a parent without cascading. |
| `parent_id` cycles | ❌ — not surfaced by `countErrors()` | ❌ — cycle members are silently skipped | Raw `UPDATE` on `parent_id` that bypassed Eloquent guards. |
| Aggregate drift (stored `tickets_total` ≠ computed) | ✅ via `aggregateErrors()` | ✅ via `fixAggregates()` | Raw `UPDATE` on the source column. |

**Best practice in one rule:** mutate trees only through Eloquent on a `NodeTrait` model. Every `appendToNode`/`prependToNode`/`insertBeforeNode`/`insertAfterNode`/`makeRoot`/`delete`/`forceDelete`/`restore` call is wrapped in a transaction and maintains every invariant. Most of the corruption categories above are reachable only by bypassing that surface.

See [`docs/CORRUPTION.md`](docs/CORRUPTION.md) for the full taxonomy with worked recovery recipes, diagnostic SQL for finding cycles, and `tests/Feature/Corruption/` for executable examples of every category.

### Queueable aggregate repair

`fixAggregates()` is fast on most trees but a heavily-drifted 1M-row table still measures in tens of seconds — not the kind of work you want on the synchronous response path. `queueFixAggregates()` hands it to a worker instead:

```php
// Fire and forget — uses Laravel's default queue connection / queue name.
Area::queueFixAggregates();

// Scoped models: anchor required (same rule as the sync method).
MenuItem::queueFixAggregates($anchor);

// Per-call routing overrides (also configurable globally — see below).
Area::queueFixAggregates(onConnection: 'redis', onQueue: 'aggregates-low');
```

Defaults come from `config/nestedset.php`:

```php
'queue' => [
    'connection' => env('NESTEDSET_QUEUE_CONNECTION'),  // null → default connection
    'queue' => env('NESTEDSET_QUEUE'),                   // null → default queue
],
```

The dispatched `Vusys\NestedSet\Jobs\FixAggregatesJob` carries the model class and an optional anchor id; its `handle()` just calls the same `Model::fixAggregates($anchor)` you'd call synchronously, so it inherits every Phase K+ optimisation automatically. The job is **idempotent** — a second run on a clean tree finds zero drift and writes nothing — so dispatching defensively after a batch operation is safe.

#### Chunked self-redispatch

For very large trees where even a single repair job would exceed your queue's per-job time budget, pass a `chunkSize` and the job will process one bounded slice and re-dispatch itself with an advanced cursor until the table is covered:

```php
// Process 1,000 outer rows per dispatch. The job re-queues itself
// (on the same connection/queue) after each chunk until done.
Area::queueFixAggregates(chunkSize: 1_000);
```

Each chunk runs one chunked `fixAggregates` constrained to its outer-id slice, so total work scales linearly in `chunkSize` regardless of total table size. The chain terminates automatically when a chunk returns fewer rows than `chunkSize` — no completion handler to register, no manual cursor to track. Combine with a smaller chunk size to keep individual jobs well under your worker's `--timeout`.

#### Deferred maintenance for batch mutations

If you're doing many small mutations through Eloquent — a CSV import, a re-parenting script, a re-numbering migration — every save normally triggers a per-row aggregate update on the ancestor chain. For N saves that's N × ancestor-chain UPDATEs. `withDeferredAggregateMaintenance()` suspends those side-effects for the duration of a closure and fires one `fixAggregates()` at the end:

```php
Area::withDeferredAggregateMaintenance(function () use ($csv, $parent) {
    foreach ($csv as $row) {
        $area = new Area($row);
        $area->appendToNode($parent)->save();  // saving/created/saved fire,
    }                                           // aggregate side-effects deferred
}, $rootAnchor);                                // one fixAggregates($root) at the end
```

What still fires inside the closure:
- Every Eloquent event (`saving` / `created` / `saved` / `deleted` / `restoring` / `restored`)
- Mutators, casts, mass-assignment guards, observers — exactly as they would outside the block

What's deferred:
- The trait's per-row aggregate-column updates on the ancestor chain (`tickets_total`, `tickets_count_all`, etc.)
- All the MIN/MAX recompute and AVG companion writes that normally piggy-back on each save

The wrapper is re-entrant (nested calls share one counter, only the outermost call triggers the final fix) and **failure-safe** — if the closure throws, the counter still decrements and `fixAggregates()` still fires before the exception propagates. Leaving the table half-repaired would be worse than spending the fix cost. The closure's return value is what the wrapper returns.

Trade-off: this trades N small ancestor UPDATEs for one all-at-once repair pass. The repair touches every row whose stored aggregates may have drifted, so it's worth it when N is large (CSV imports, scripts) and a poor fit for one-or-two saves.

#### Sync chunked repair with progress

When you'd rather drive the loop yourself — e.g. a CLI command streaming progress to stdout — pass the same `chunkSize` to the synchronous `fixAggregates()` plus an `onChunk` callback:

```php
$result = Area::fixAggregates(
    chunkSize: 1_000,
    onChunk: function ($chunkResult, int $chunkIndex, ?int $cursor) {
        $this->output->writeln(sprintf(
            'Chunk %d: %d rows updated (cursor=%s)',
            $chunkIndex,
            $chunkResult->totalRowsUpdated,
            $cursor ?? 'end',
        ));
    },
);

// $result is the merged total across every chunk.
```

The callback receives the per-chunk `AggregateFixResult`, the zero-based chunk index, and the cursor (last id processed, or `null` on the final chunk). Each chunk is independently atomic at the database level — if the process is killed mid-loop you can re-run and the remaining drift will be detected and repaired on the next pass.

---

## Bulk tree insertion

Seeding a large tree by calling `appendToNode()->save()` per node is O(N²) — each save shifts every post-insertion-point row through a CASE-WHEN UPDATE, so the gap-shift cost piles up over the whole insert. `bulkInsertTree()` collapses that to one `makeGap` plus one `fixAggregates`, while keeping every Eloquent guarantee (events, mutators, casts, mass-assignment) and returning fully hydrated models:

```php
$root = new Area(['name' => 'Engineering', 'tickets' => 0]);
$root->saveAsRoot();
$root = $root->refresh();

[$backend, $api, $db, $frontend] = Area::bulkInsertTree([
    ['name' => 'Backend', 'tickets' => 5, 'children' => [
        ['name' => 'API',      'tickets' => 3],
        ['name' => 'Database', 'tickets' => 2],
    ]],
    ['name' => 'Frontend', 'tickets' => 1],
], appendTo: $root);

$backend->name;            // 'Backend' — mass-assigned via $fillable
$backend->wasRecentlyCreated; // true
$api->parent_id === $backend->getKey(); // true
```

Each row goes through a normal `save()`, so per-row `creating` / `saving` / `created` / `saved` events still fire, every cast applies, observers run, mass-assignment guards are respected. The only operations the package does on top of `save()` are the one-shot `makeGap` (replaces N gap-shifts) and the deferred `fixAggregates` at the end of the call (replaces N aggregate-ancestor UPDATEs).

| Backend | N=100 (vs naive) | N=1000 (vs naive) |
|---|---|---|
| SQLite | 18ms (3.2× faster) | 224ms (3.2× faster) |
| MySQL 8 | 44ms (3.0× faster) | 302ms (6.0× faster) |
| MariaDB | 48ms (6.6× faster) | 451ms (11.9× faster) |

The win widens as N grows because the naive path's gap-shift cost is O(N²). At N=10,000 on MariaDB the naive loop runs for many minutes; `bulkInsertTree` scales roughly linearly.

If you specifically need event-free seeding (e.g. backfilling 100K rows from a CSV with no observer side-effects), the standard Laravel escape hatch composes:

```php
Model::withoutEvents(static fn () => Area::bulkInsertTree($rows, appendTo: $root));
```

Constraints:
- Rows must not contain `lft`, `rgt`, `depth`, `parent_id`, or the primary key — those are computed by the package.
- Scoped models (those with `#[NestedSetScope]` or `getScopeAttributes()`) require an `$appendTo` anchor — the scope-column values are copied from it onto every inserted row.
- Wrapped in a transaction; if any per-row `save()` throws, the gap-open and any prior inserts roll back together.

---

## Precalculated aggregate columns

Sometimes you want a node to carry rolled-up data about its subtree —
a total, a count, an average, a min/max — without re-running an
aggregate query every time the tree is rendered. Declare the columns
and the package keeps them in sync as the tree mutates.

```php
use Vusys\NestedSet\Attributes\NestedSetAggregate;

#[NestedSetAggregate(column: 'tickets_total',     sum:   'tickets')]
#[NestedSetAggregate(column: 'tickets_count_all', count: true)]
#[NestedSetAggregate(column: 'tickets_avg',       avg:   'tickets')]
#[NestedSetAggregate(column: 'tickets_min',       min:   'tickets')]
#[NestedSetAggregate(column: 'tickets_max',       max:   'tickets')]
class Area extends Model implements HasNestedSet
{
    use NodeTrait;
}
```

For a tree where `Root(tickets=100) > A(50) > A1(50)` and `Root > B(25)`:

```php
$root->refresh()->tickets_total;    // 225  (100 + 50 + 50 + 25)
$root->tickets_count_all;           // 4
$root->tickets_avg;                 // 56.25
$root->tickets_min;                 // 25
$root->tickets_max;                 // 100
```

Every node carries its own subtree's rollup. Inserts, source-column
updates, deletes, moves and soft-delete restores all keep the stored
values current.

### Migration helper

```php
Schema::create('areas', function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->unsignedInteger('tickets')->default(0);
    $table->nestedSet();

    // SUM / COUNT — non-null, default 0
    $table->nestedSetAggregate('tickets_total');
    $table->nestedSetAggregate('tickets_count_all');

    // AVG — nullable decimal; null on empty subtree
    $table->nestedSetAggregate('tickets_avg', type: 'avg');

    // MIN / MAX — nullable; empty subtree yields NULL
    $table->nestedSetAggregate('tickets_min', type: 'min_max');
    $table->nestedSetAggregate('tickets_max', type: 'min_max');
});
```

### Required model conventions

Aggregate columns are derived state. Two rules:

```php
class Area extends Model implements HasNestedSet
{
    use NodeTrait;

    // Aggregate columns NEVER belong in $fillable. Mass-assigning them
    // is silently overwritten on the next mutation and produces drift
    // in the interim.
    protected $fillable = ['name', 'tickets'];

    // Declare casts manually — NodeTrait does not register them for you.
    protected $casts = [
        'tickets'           => 'integer',
        'tickets_total'     => 'integer',
        'tickets_count_all' => 'integer',
        'tickets_avg'       => 'decimal:4',
        'tickets_min'       => 'integer',
        'tickets_max'       => 'integer',
    ];
}
```

### Reading: stored vs fresh

The stored column is a single-row read — effectively free. The fresh
counterpart recomputes from the source column via a correlated
subquery — useful for audit reports or drift detection.

```php
// Single-row fresh recomputation
$area->tickets_total;                        // stored
$area->freshAggregate('tickets_total');      // recomputed from source

// Collection-level fresh selects (overlay stored values)
Area::query()->withFreshAggregates()->get();
Area::query()->withFreshAggregates(['tickets_total', 'tickets_max'])->get();

// Ad-hoc fresh aggregate without declaring a column
use Vusys\NestedSet\Aggregates\Aggregate;
Area::query()->withFreshAggregates([
    'descendants_total' => Aggregate::sum('tickets')->exclusive(),
])->get();
```

### Method-override declaration form

For runtime-conditional aggregates (or large declaration sets that
would clutter the class header), override `nestedSetAggregates()`:

```php
class Area extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @return list<\Vusys\NestedSet\Aggregates\AggregateDefinition> */
    protected function nestedSetAggregates(): array
    {
        return [
            Aggregate::sum('tickets')->into('tickets_total'),
            Aggregate::count()->into('tickets_count'),
            Aggregate::avg('tickets')->into('tickets_avg'),
        ];
    }
}
```

Attribute and method-override forms can coexist; attribute declarations
come first, method override appends. Same precedence rule as scope
resolution.

For tooling that needs to enumerate what a model declares at runtime
— Filament resources, admin generators, export scripts — use
`$model->getAggregateDefinitions()`, which returns the user-facing
`AggregateDefinition` list (internal AVG companions are filtered out).

### Maintenance

Aggregates ride the package's existing lifecycle events:

| Mutation                  | Path                                      | Extra UPDATEs |
|---------------------------|-------------------------------------------|---------------|
| Insert leaf               | cheap-delta (SUM/COUNT/MIN/MAX) + AVG ratio | 1            |
| Source-column update      | cheap-delta + recompute for invalidated extremum | 1 or 2 |
| Delete                    | delta subtract + recompute for invalidated extremum | 1 or 2 |
| Move (`appendToNode` etc.)| delta on old chain + delta on new chain   | 2            |
| Soft-delete restore       | delta re-add to current chain             | 1            |

MIN/MAX use a SELECT-then-UPDATE recompute path when the change may
have invalidated the stored extremum — controlled by the
`nestedset.aggregate_locking` config flag (`'auto'` / `'always'` /
`'never'`; see `config/nestedset.php`).

### Integrity tooling

Mirrors the tree-repair API:

```php
Area::aggregateErrors();
// ['tickets_total' => 0, 'tickets_count_all' => 0, 'tickets_avg' => 0, ...]

Area::aggregatesAreBroken();    // bool

Area::fixAggregates();
// → AggregateFixResult { totalRowsUpdated: 0, perColumn: [...] }
```

`fixTree()` runs `fixAggregates()` as a final step — corrupted lft/rgt
plus drifted aggregates are repairable in one call. The result carries
the aggregate stats alongside the tree stats:

```php
$result = Area::fixTree();
$result->nodesUpdated;       // tree side
$result->errors;             // post-repair tree errors
$result->aggregatesFixed;    // AggregateFixResult — null on no-aggregate models
```

Scoped models require an anchor on `aggregateErrors`, `aggregatesAreBroken`,
and `fixAggregates` (same as `fixTree`).

### Adding aggregates to an existing model

1. Add `#[NestedSetAggregate(...)]` declarations to the model class.
2. Add `$table->nestedSetAggregate('col_name', type: ...)` to a new
   migration; run it.
3. Add the matching cast to `$casts`.
4. Run `YourModel::fixAggregates()` once to backfill stored values from
   the source data. On scoped models, run per anchor.
5. Deploy.

After the backfill, every subsequent mutation through Eloquent keeps
the stored values current.

### Limitations and footguns

- **Raw DB::table updates bypass aggregate maintenance.**
  `DB::table('areas')->where(...)->update(['tickets' => 99])` won't fire
  Eloquent events. Use `fixAggregates()` to recover.
- **Soft-delete cascade preserves stored aggregates on the soft-deleted
  subtree;** ancestor chain is decremented. `restored` re-adds.
- **`replicate()` clones reset every aggregate column** to the function's
  empty element (0 for SUM/COUNT, NULL for AVG/MIN/MAX). The clone
  backfills correctly on placement.
- **Plain `Area::create(...)` without `appendToNode()` / `makeRoot()`**
  leaves the row unplaced (`lft = rgt = 0`); aggregate maintenance is
  skipped until the node is placed in the tree. Check the state with
  `$node->isPlacedInTree(): bool` — returns false when both `lft` and
  `rgt` are still the migration default.
- **AVG over a nullable source.** `avg: 'col'` uses `AVG(col)` which
  skips NULL rows. If the source is nullable, the auto-promoted COUNT
  companion uses `COUNT(col)` (also non-null-skipping) so the ratio
  stays consistent.
- **MIN/MAX recompute cost.** Deletes and source-decreasing updates that
  invalidate the stored extremum trigger a SELECT-then-UPDATE recompute.
  Cheap-skipped when the change couldn't have affected the extremum —
  but if you have a deep, wide tree with hot MIN/MAX columns, expect
  occasional spikes.

See `tests/Feature/Aggregates/` for executable examples of every
maintenance path.

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

    // 'auto'   — lock the ancestor chain only on the MIN/MAX recompute path
    // 'always' — lock on every aggregate maintenance UPDATE
    // 'never'  — issue no explicit locks
    'aggregate_locking' => 'auto',
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
| Test coverage | 86 tests | 340+ tests, performance bench harness, cross-backend matrix |
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
