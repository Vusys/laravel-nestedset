# vusys/laravel-nestedset

[![Tests](https://github.com/Vusys/laravel-nestedset/actions/workflows/tests.yml/badge.svg)](https://github.com/Vusys/laravel-nestedset/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/Vusys/laravel-nestedset/graph/badge.svg)](https://codecov.io/gh/Vusys/laravel-nestedset)
[![tests](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-nestedset/badges/tests.json)](https://github.com/Vusys/laravel-nestedset/actions/workflows/tests.yml)
[![assertions](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-nestedset/badges/assertions.json)](https://github.com/Vusys/laravel-nestedset/actions/workflows/tests.yml)
[![test LOC](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-nestedset/badges/test-ratio.json)](tests/)
[![CI matrix](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-nestedset/badges/matrix.json)](.github/workflows/tests.yml)
[![CodSpeed](https://img.shields.io/endpoint?url=https://codspeed.io/badge.json)](https://codspeed.io/Vusys/laravel-nestedset)
[![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/Vusys/laravel-nestedset/badge)](https://scorecard.dev/viewer/?uri=github.com/Vusys/laravel-nestedset)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777BB4?logo=php&logoColor=white)](composer.json)
[![Laravel](https://img.shields.io/badge/laravel-11%20%7C%2012%20%7C%2013-FF2D20?logo=laravel)](composer.json)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg)](phpstan.neon)
[![Rector](https://img.shields.io/badge/Rector-passing-brightgreen.svg)](rector.php)
[![Code Style: Pint](https://img.shields.io/badge/code%20style-Laravel%20Pint-FF2D20.svg?logo=laravel)](https://github.com/laravel/pint)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

A modern Laravel implementation of the nested-set model for hierarchical
data — strict types throughout, PHPStan level 9, atomic CASE-WHEN mutations,
multi-tree scoping, soft-delete cascade, and an opinionated repair toolkit.

```php
$root = Category::create(['name' => 'Root']);
$root->saveAsRoot();

$child = Category::create(['name' => 'Child']);
$child->appendToNode($root)->save();

Category::query()->whereDescendantOf($root->getBounds())->get();
$root->descendants()->orderBy('lft')->get();
$root->refresh()->getSubtreeSize();  // rgt - lft + 1
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

## Installation

```bash
composer require vusys/laravel-nestedset
```

The service provider auto-registers Blueprint macros and registers a
publishable config file:

```bash
php artisan vendor:publish \
    --provider="Vusys\NestedSet\NestedSetServiceProvider" \
    --tag=nestedset-config
```

See the [Installation guide](https://vusys.github.io/laravel-nestedset/getting-started/installation.html)
for the rest of the setup (migration macros, model trait, scoped trees).

## Documentation

Full documentation lives at **<https://vusys.github.io/laravel-nestedset/>**.

- **Getting Started** — [Introduction](https://vusys.github.io/laravel-nestedset/) · [Installation](https://vusys.github.io/laravel-nestedset/getting-started/installation.html) · [Migration](https://vusys.github.io/laravel-nestedset/getting-started/migration.html) · [Primary Keys](https://vusys.github.io/laravel-nestedset/getting-started/primary-keys.html) · [Model Setup](https://vusys.github.io/laravel-nestedset/getting-started/model-setup.html)
- **Tree Operations** — [Inserting & Moving](https://vusys.github.io/laravel-nestedset/tree-operations/inserting.html) · [Soft Deletes](https://vusys.github.io/laravel-nestedset/tree-operations/soft-deletes.html) · [Bulk Insertion](https://vusys.github.io/laravel-nestedset/tree-operations/bulk-insertion.html)
- **Querying** — [Tree Queries](https://vusys.github.io/laravel-nestedset/querying/queries.html) · [Eloquent Relations](https://vusys.github.io/laravel-nestedset/querying/relations.html) · [In-memory Tree Shaping](https://vusys.github.io/laravel-nestedset/querying/tree-shaping.html) · [Inspection](https://vusys.github.io/laravel-nestedset/querying/inspection.html) · [Scoped Trees](https://vusys.github.io/laravel-nestedset/querying/scoped-trees.html)
- **Aggregates** — [Overview](https://vusys.github.io/laravel-nestedset/aggregates/overview.html) · [Setup](https://vusys.github.io/laravel-nestedset/aggregates/setup.html) · [Reading](https://vusys.github.io/laravel-nestedset/aggregates/reading.html) · [Declaring](https://vusys.github.io/laravel-nestedset/aggregates/declaring.html) · [Filtered](https://vusys.github.io/laravel-nestedset/aggregates/filtered.html) · [Listeners](https://vusys.github.io/laravel-nestedset/aggregates/listeners.html) · [Recipes](https://vusys.github.io/laravel-nestedset/aggregates/recipes.html) · [Maintenance](https://vusys.github.io/laravel-nestedset/aggregates/maintenance.html) · [Drift & Limitations](https://vusys.github.io/laravel-nestedset/aggregates/drift.html)
- **Maintenance** — [Tree Repair](https://vusys.github.io/laravel-nestedset/maintenance/fix-tree.html) · [Repairing Aggregates](https://vusys.github.io/laravel-nestedset/maintenance/fix-aggregates.html) · [Corruption Reference](https://vusys.github.io/laravel-nestedset/maintenance/corruption.html)
- **Reference** — [Configuration](https://vusys.github.io/laravel-nestedset/reference/config.html) · [Testing Helpers](https://vusys.github.io/laravel-nestedset/reference/testing.html) · [Transactions](https://vusys.github.io/laravel-nestedset/reference/transactions.html) · [Production Notes](https://vusys.github.io/laravel-nestedset/reference/production.html) · [vs. kalnoy/nestedset](https://vusys.github.io/laravel-nestedset/reference/comparison.html)

The site is built from the markdown in [`docs/`](docs/) — if you spot an
error, edit the source and open a PR.

## Contributing

Run the full check suite before opening a PR:

```bash
composer pint:check    # style
composer rector:check  # automated refactors
composer analyse       # static analysis
composer test          # unit + feature
```

All four must pass on CI before merge.

## License

MIT. See [LICENSE](LICENSE).
