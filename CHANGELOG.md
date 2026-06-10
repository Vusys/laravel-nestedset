# Changelog

All notable changes to `vusys/laravel-nestedset` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Pre-1.0, backwards-compatibility breaks are allowed when called out under
**Changed** / **Removed**.

## [Unreleased]

### Fixed

- **Soft-delete cascade marker format diverged from the anchor row**
  (anchor `…12:00:00` vs descendants `…12:00:00.000000`), so restoring an
  outer ancestor could restore part of an interleaved cascade and strand a
  trashed node under a live parent (diverging across backends). The cascade
  now stamps descendants with the anchor's exact seconds-precision value.
- **Anchored `fixTree()` hung / OOM'd on `parent_id` cycles** — the
  documented cycle-recovery tool now terminates (visited-set guards in the
  subtree walk).
- **MIN/MAX aggregates drifted** on `NULL ↔ value` source updates and
  listener-contribution transitions (the SUM-style `NULL → 0` coercion
  leaked into the MIN/MAX update paths).
- **Stale in-memory values on the move path**: `insertAfterNode`/
  `insertBeforeNode` could stamp a stale `parent_id`, and the before-move
  aggregate hook used stale bounds. Both now read fresh.
- **A `saving`/`creating` listener returning `false` committed structural
  SQL** (gap/move) without the row write; the cancel now rolls back.
- **`delete()`/`forceDelete()` were not wrapped in the auto-transaction**, so
  a throw mid-pipeline left a permanent hole — now wrapped like `save()`.
- **Hard-deleting an unplaced row shifted every placed row in scope**;
  `saveQuietly()` silently dropped queued placements — both now guarded.
- **`bulkInsertTree()` trusted the anchor's stale in-memory bounds** — now
  reads + locks the anchor row inside its transaction.
- **`withDeferredAggregateMaintenance()` swallowed repair failures on the
  success path** — the failure now propagates.
- **`TreeDiff::apply()`** discarded recorded sibling positions and deleted
  retained children before moving them; **`fromJsonTree()`** returned the
  wrong nodes (DFS pre-order slice bug).
- **`isDescendantOf()`/`isAncestorOf()`** ignored scope (cross-tree false
  positives); **relations** had no `ORDER BY`; **`#[NestedSetScope]`** was
  not inherited by subclasses; `withDepth()`/`whereIsLeaf()` didn't wrap
  identifiers; JSON-import collision keys dropped UUID/string PKs.

### Added

- `countErrors()` detects three more corruption categories:
  `parent_bounds_mismatch`, `depth_mismatch`, `bounds_out_of_range`.
- `fixTree($anchor)` rejects an unplaced anchor and reports the rows it
  actually walked in `TreeFixResult::nodesUpdated`.
- Documentation for the Tree-diff subsystem.

### Removed

- Dead bitwise-delta maintenance paths (all bitwise aggregates already
  maintain via chain recompute); the docs now reflect this.

## [0.21.0] - 2026-06-04

### Added

- Top-K aggregate (`topK`).
- Lazy aggregates with TTL.
- Aggregate change-feed event (`NestedSetAggregateChanged`).
- Listener-aggregate hardening: O(N) repair, `filter:`, variance / stddev /
  geometric-mean / harmonic-mean.

### Fixed

- Type-preserving MIN/MAX reads; guard unplaced mutation targets;
  cross-backend mutation + scope-resolver gaps.

## [0.20.0] - 2026-05-31

### Added

- Materialised-path columns.

## [0.19.0] - 2026-05-31

### Added

- Tree diff + JSON tree import.
- Subtree cloning (`cloneSubtreeTo`, `cloneSubtreeAsRoot`).
- Sibling reorder primitive (one `CASE WHEN` UPDATE per reorder).

[Unreleased]: https://github.com/vusys/laravel-nestedset/compare/v0.21.0...HEAD
[0.21.0]: https://github.com/vusys/laravel-nestedset/compare/v0.20.0...v0.21.0
[0.20.0]: https://github.com/vusys/laravel-nestedset/compare/v0.19.0...v0.20.0
[0.19.0]: https://github.com/vusys/laravel-nestedset/compare/v0.18.2...v0.19.0
