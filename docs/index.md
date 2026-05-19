# Introduction

A modern Laravel implementation of the nested-set model for hierarchical
data — strict types throughout, PHPStan level 9, atomic CASE-WHEN
mutations, multi-tree scoping, soft-delete cascade, and an opinionated
repair toolkit.

Target: **PHP 8.3+** / **Laravel 11, 12, 13**.

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

The nested-set encoding stores `lft` and `rgt` integers on every node so
any subtree, ancestor chain, or descendant set is a single `BETWEEN`
query — no recursive CTEs, no N+1 loops. The price is that mutations
(insert / move / delete) have to shift many rows to keep the lft/rgt
sequence dense, so it's best suited to **read-heavy hierarchies**:
category trees, menu structures, org charts, comment threads.

This package executes every shift as a single `CASE WHEN UPDATE`, so
even a subtree move that touches thousands of rows is one round trip.

## What's in this documentation

Start with [Installation](getting-started/installation.html), then [Migration](getting-started/migration.html)
and [Model Setup](getting-started/model-setup.html) to get a working tree. From there the
sidebar groups material by what you're trying to do — insert/move,
query, compute aggregates, repair corruption.
