# laravel-nestedset

A nested-set implementation for Laravel Eloquent models, with built-in tree
maintenance, aggregate columns, and tooling for detecting and repairing
corruption in production trees.

This is the documentation home. Pick a section from the sidebar, or start
with [Installation](installation.html) if you're new.

## What is a nested set?

A **nested set** is a way to store hierarchical data in a single SQL table
that makes ancestor/descendant queries fast and indexable. Each row gets a
`lft` and `rgt` value defining its range; a row whose range is contained
inside another row's range is a descendant of it.

Compared with the alternatives:

| Model            | Reads (ancestors / descendants) | Writes  | Storage |
|------------------|----------------------------------|---------|---------|
| Adjacency list   | Slow (recursive)                 | Fast    | Small   |
| Closure table    | Fast                             | Medium  | Large   |
| **Nested set**   | **Fast (single range scan)**     | Medium  | Small   |
| Materialised path| Fast prefix                      | Medium  | Medium  |

Nested sets shine for trees that are **read more than they're written** —
category trees, menu structures, org charts, threaded comments where the
shape is mostly stable.

## What this package adds

On top of the core nested-set algorithm, this package provides:

- **`NodeTrait`** — drop into any Eloquent model to make it a tree node.
- **Tree-aware query scopes** — ancestors, descendants, siblings, depth.
- **Aggregate columns** — denormalised counts and sums maintained
  automatically as the tree changes.
- **Corruption detection and repair** — `fixTree`, `fixAggregates`, and
  detailed error reports.
- **Scoped trees** — multi-tenant trees keyed on one or more columns.

## Conventions in these docs

Examples are also unit tests. Every code block you see in the
aggregates, querying, and tree-operation pages is pulled byte-for-byte from
a test file in `tests/Documentation/`. If the public API changes and the
example breaks, CI fails before the page goes out of date.
