# Package audit — 2026-06-11

Eight parallel deep-review passes over the full package: mutation core, query layer, aggregate write path, aggregate read path, repair/soft-deletes, bulk/clone/diff/import/export/walkers, materialised paths/plumbing, and test-suite/docs/CI. Findings marked **[reproduced]** were confirmed with throwaway failing tests against the current code (sqlite) during the audit; the rest were verified by code trace. Baseline at audit time: `composer check` green, full default suite green.

The headline: the core lft/rgt arithmetic, locking discipline, and hook ordering are genuinely solid — the moveNode band maths, MySQL SET-order handling, and the combined move+source-update delta composition were all hand-traced and found correct. The latent bugs cluster at the *edges*: failed-save recovery, stale-instance paths that missed the hardening other paths got, soft-delete visibility splits, diff/clone interactions with hard-delete models, and multibyte/multi-scope materialised paths.

---

## Progress ledger — updated 2026-06-12

Work landed on branch `review-audit-fixes-2026-06-11` (20 commits on top of v0.22.0). Every ✅ item ships with a reproduced-then-fixed regression test; the full suite (2207 tests) and `composer check` (Pint + Rector + PHPStan level 9) are green.

Legend: ✅ fixed · 🔶 open · 🧭 needs a decision before implementing.

**§1 Critical** — ✅ 1.1 · ✅ 1.2

**§2 High** — ✅ 2.1 · ✅ 2.2 · ✅ 2.3 · ✅ 2.4 · ✅ 2.5 · ✅ 2.6 · ✅ 2.7 · ✅ 2.8 *(scope now carried on `NodeBounds`)*

**§3 Medium**
- ✅ create-hook cast-aware filters · ✅ float zero-delta no-op · ✅ scope snowflake collapse · ✅ missing-anchor `fixTree` guard · ✅ `immutable_datetime` cascade · ✅ `withFreshAggregates` alias injection · ✅ `withDepth`/`withFreshAggregates` narrowed-`select()` · ✅ static-trait-call deprecation
- 🔶 forceDelete-after-restore drift · 🔶 lazy-aggregate read-writes / `aggregateErrors` lazy drift · 🔶 `withDeferredAggregateMaintenance` cross-scope · 🔶 BIGINT/decimal float precision in delta path · 🔶 MariaDB fresh-read `limit`/`orWhere` · 🔶 identifier-quoting consistency across write/repair/read · 🔶 unbounded structural UPDATEs · 🔶 deleting-hook unlocked re-read · 🔶 PG READ COMMITTED recompute window · 🔶 lock-ordering canonicalisation · 🔶 flat-snapshot spurious `Moved` · 🔶 diff hardcodes id/parent_id · 🔶 diff unusable on scoped+aggregates
- 🧭 soft-delete visibility (reorderChildrenBy / up()/down() / placement onto trashed targets) — pick raw vs live visibility · 🧭 restoring a child under a still-trashed ancestor — allow+document / block / auto-restore · 🧭 `moveTo(int)` resolves target at queue time

**§4 Pre-1.0 decisions** — ✅ #1 (= 2.8). 🧭 #2–#11 (see "Decisions still open" below).

**§5 Test/CI** — ✅ 5.1 (`top_k_areas` truncation) · ✅ 5.9 (deprecation surfacing). 🔶 backend fuzzing, new fuzzers (paths / diff-apply / clone / stale-instance pool), narrow class-level `allowBrokenTreeAtTearDown`, extend tearDown integrity to all fixtures, remaining targeted regressions, duplicate migration prefixes.

**§6 Docs** — ✅ CLAUDE.md "spec lives in docs/". 🔶 corruption.md staleness, tree-diff.md / exporters.md prose, materialised-paths soft-delete note.

**§7 Packaging** — ✅ 7.1 `.gitattributes`. 🔶 LICENSE copyright; REVIEW.MD is untracked so never shipped.

### Decisions still open (your call)

Each is "implement X *or* drop/document it" — I've put my default first.

1. **`aggregate_locking: 'always'`** is a no-op alias of `'auto'`. → *Drop the third state* (simplest), or implement a genuine force-lock mode.
2. **`defaultOrder()` on scoped models** is non-deterministic (every tree restarts lft at 1). → *Order by scope columns first when the model is scoped.* Low risk, strictly better — but it changes default row order, so flagging rather than just doing it.
3. **Fresh-select column-reuse footgun** — `withFreshAggregates()` reusing a real aggregate column name corrupts the next `save()`'s delta baseline. → *Throw when an ad-hoc alias collides with a stored aggregate column.*
4. **Aggregate events / change-feed dispatch pre-commit** — a rollback leaves listeners having observed phantom changes. → *Wrap dispatch in `DB::afterCommit()`* (changes event timing — a real semantic choice), or document loudly.
5. **Deferred `fixAggregates` after commit** in `bulkInsertTree` / `TreeDiff::apply()` — a crash between commit and repair leaves persistent drift. → *Pull the repair inside the caller transaction*, or accept + document.
6. **Secondary index on `[scope…, parent_id]`** — the shipped composite can't serve `children()` / `whereIsRoot()` / fixTree's parent walk on PG/sqlite. → *Add the index* (write/storage cost) vs leave to the user.
7. **Exception marker sweep** — ~20 sites throw bare `InvalidArgumentException`/`LogicException`/`RuntimeException`, so `catch (NestedSetException)` misses them. → *Make them all implement the marker* (mechanical; mild hierarchy change).
8. **`anchoredCountErrors` semantics** — `countErrors($anchor)` is whole-table on unscoped models while `fixTree($anchor)` bands to the subtree, so `hasErrors()` can stay true after a converged anchored repair. → *Band the post-fix count to the anchor*, or document the asymmetry.
9. **Helpers assume the full framework** — `config()`/`event()`/`resolve()` come from `laravel/framework`, which `composer.json` doesn't require (only `illuminate/*`). → *Require `laravel/framework` honestly*, or guard the helpers.
10. **MariaDB `SET STATEMENT` prefix has no version floor** — errors hard on MariaDB < 10.3 and is silently lost by `cursor()`/`lazy()`. → *Gate on the parsed server version + override `cursor()`.*
11. **Soft-delete visibility model** (the §3 🧭 items) — decide once whether structural-slot operations see trashed rows (raw) or live rows, and the restore-under-trashed-ancestor semantic, then pin with tests.

---

## 1. Critical

### 1.1 `TreeDiff::apply()` corrupts the tree when removing a subtree on a hard-delete model [reproduced]  — ✅ **FIXED**

`src/Diff/TreeDiffApplier.php:361-370`. A diff removing a subtree carries a `Removed` entry per node. `doRemoves` fetches all rows upfront and deletes each. The first (ancestor) delete cascades a raw hard-delete and closes the gap; the subsequent `delete()` on an already-cascade-deleted child silently proceeds with stale bounds — the `deleting` re-read at `src/NodeTrait.php:146` finds no row and returns without flagging, then the `deleted` hook runs `applyForceDeleteCascade` + `closeGap` against the stale band, deleting innocent rows and corrupting bounds. Reproduced: removing a 3-node subtree on `Branch` left `lft=1 rgt=0` rows, `isBroken() === true`. Masked in the suite because every diff-apply test uses `Category`, which soft-deletes.

**Fix:** in `doRemoves`, delete only top-most removed nodes (skip rows whose ancestor key is also in the removed set), and make the `deleted` hook a no-op when the `deleting` re-read found the row already gone (set a "vanished" flag rather than silently returning). Fixing the latter also fixes the general double-delete footgun outside diff. The same top-most-only fix stops soft-delete diff removes from re-stamping cascaded children (breaking stamp-matched restore).

### 1.2 Retrying a save after a cancelled/failed save silently corrupts the tree [reproduced]  — ✅ **FIXED**

`src/Concerns/HasTreeMutation.php:666-692, 862-863, 1391-1432`. When the auto-transaction rolls back (listener returning `false` → `SaveCancelledException`, or any DB exception during the row write), the DB is restored but the in-memory model is poisoned: `$pending` is already cleared and `lft`/`rgt`/`depth` hold the rolled-back placement. A retry `save()` then passes every guard:

- New node: `isPlacedInTree()` is true, so it INSERTs with stale bounds and **no makeGap** → duplicate lft/rgt. Reproduced: two rows both at `(2,3)`, `isBroken() === true`, no exception.
- Existing node: only `parent_id` is dirty → plain UPDATE writes the new `parent_id` with no structural SQL → `parent_id` contradicts bounds.

**Fix:** snapshot the pending op + structural attributes (and originals) before `parent::save()`, and restore them on any non-commit path (both the `SaveCancelledException` catch and the rethrow path). `CancelledSaveTest` only asserts the DB is untouched — add retry-after-cancel and retry-after-DB-exception tests for both insert and move.

---

## 2. High

### 2.1 Aggregate drift: source-column update applies deltas against stale in-memory bounds [reproduced]  — ✅ **FIXED**

`src/Aggregates/Lifecycle/DeltaCapture.php:436` (and `LifecycleSupport.php:131`) use `$node->getBounds()` from the in-memory instance. Delete, move, and restore were all hardened to re-read bounds from the DB; the plain source-update save was missed. Hold an instance, let another mutation shift its bounds, update the source column, `save()` — the ancestor-band UPDATE hits the wrong rows. Reproduced: 4 rows of permanent drift on the `Area` fixture. **Fix:** re-read bounds in `DeltaCapture::apply()` when any state was captured (one extra SELECT, only on saves that captured aggregate work). The fuzzers can't catch this because every op refreshes first — add a "no-refresh stale instance pool" to `AggregateFuzzerTest`.

### 2.2 Multibyte content corrupts every descendant materialised path on rename/move [reproduced]  — ✅ **FIXED**

`src/Concerns/HasMaterialisedPath.php:427` computes the rewrite offset with `strlen()` (bytes) but the SQL uses `SUBSTRING`/`SUBSTR` (characters on utf8mb4 MySQL, PG, sqlite). Any non-ASCII character in the old path — including the `' › '` separator showcased in the docs — chops characters off every descendant path. Reproduced: renaming `Café` → `Bücher` ate the separator space in the child path. **Fix:** `mb_strlen($oldPrefix, 'UTF-8')` or compute the offset in SQL via `CHAR_LENGTH(?)+1`. No multibyte fixture exists anywhere in the path tests — add one.

### 2.3 Closure-source materialised paths cannot be repaired or cloned [reproduced]  — ✅ **FIXED**

`src/Concerns/HasTreeRepair.php:237-250` selects only structural columns + `sourceColumn()` per path; closure sources (`MaterialisedPath::from(...)`) return `null` there, so the closure reads missing attributes, produces an empty segment, and the row is silently skipped at `:298-300`. Reproduced: `fixMaterialisedPaths()` returned 0 and left `/garbage/` in place. Knock-on: `cloneSubtreeTo()` strips path columns and relies on `fixMaterialisedPaths` — reproduced that cloning a closure-path subtree leaves every cloned row with NULL paths, and `assertClonedPathsUnique` skips empties so nothing throws. **Fix:** select all columns in `rebuildMaterialisedPaths` (it already loads every row), or let `MaterialisedPath::from()` declare its dependent columns.

### 2.4 Multi-column scope: path-rewrite bindings bound to the wrong columns [reproduced via SQL]  — ✅ **FIXED**

`HasMaterialisedPath.php:448-450` vs `:491-493` — the WHERE prepends scope predicates (reversing column order) while values are appended in forward order. With `#[NestedSetScope(['tenant_id', 'menu_id'])]`, tenant binds to menu_id and vice versa: rewrites miss all descendants or hit another tenant's rows. Only single-column-scope fixtures are tested, which is why CI is green. **Fix:** append predicates in iteration order; add a two-column-scope fixture.

### 2.5 `cloneSubtreeTo()` silently drops non-fillable columns [reproduced]  — ✅ **FIXED**

`src/Concerns/HasSubtreeClone.php:484-560` builds the payload from raw DB rows but delegates to `bulkInsertTree`, which mass-assigns (`new static($attrs)`). Guarded columns are silently zeroed — reproduced with a guarded `tickets` column (42 → 0). Respecting `$fillable` is right for user payloads, wrong for a deep-copy primitive. **Fix:** internal force-fill mode (`static::unguarded()`) for the clone path; add a guarded-column fixture (every current fixture declares everything fillable, so this is untestable today).

### 2.6 `TreeDiff::apply()` throws on legal diffs [reproduced ×2]  — ✅ **FIXED**

- `TreeDiffApplier.php:419, 505-508`: an added node positioned after a *moved-in* sibling throws `LogicException` from `moveToSiblingPosition` — adds run before moves, so the earlier-ranked sibling isn't there yet.
- `TreeDiffApplier.php:438-492`: flat-shape input (including plain Eloquent collections — the docs' primary example) applies moves in row order, so a parent/child swap throws `CyclicMoveException`.

**Fix:** topologically sort moves by after-parent chains (the `topologicalSortAdded` machinery already exists), and clamp/defer sibling positions — or run one final reorder pass after all adds/moves/removes.

### 2.7 `fixTree()` destroys deliberate sibling order [reproduced]  — ✅ **FIXED**

`src/Query/TreeRepairBuilder.php:294-301, 340-345` order children by id only. Running `fixTree()` on a *healthy* tree reverts every `insertBeforeNode`/`up()`/`reorderChildren` ordering to PK order. `corruption.md` frames fixTree as the universal recovery, so following the docs loses user data. **Fix:** order by `(lft, id)` — preserves valid order, keeps id-tiebreak determinism, and garbage lft values degrade to id order naturally. Cheap and strictly better.

### 2.8 Cross-scope leak in every positional read method [reproduced]  — ✅ **FIXED**

`src/Query/TreeQueryBuilder.php:93-228` — `whereDescendantOf`, `whereAncestorOf`, `whereIsRoot`, `root()`, `whereIsAfter/Before`, etc. take `NodeBounds` (no scope) and apply no scope predicates. Scoped trees all restart lft at 1, so overlap is the norm: `MenuItem::whereDescendantOf($menu1Root->getBounds())` returned rows from menu 2; `root()` returns an arbitrary tree's root. The relations layer adds scope manually, so the internals know it's required — the public query API leaves it to the caller, documented only for `whereDescendantOf`. **Fix (pre-1.0):** carry scope values on `NodeBounds` (the model has them at `getBounds()` time) and apply them in the predicates, or auto-apply scope when handed a model. This is the kind of silent-wrong-data default that's much harder to change after 1.0.

---

## 3. Medium

### Mutation / soft-delete visibility

- **`reorderChildrenBy()` always throws when any child is trashed** [reproduced] — `HasTreeMutation.php:402-405` reads live children (`newQuery()`) but validation (`loadDirectChildBounds`, `:520-552`) reads raw including trashed, so `InvalidSiblingOrderException::missingChildren` fires. Pick one visibility (structural slots argue for raw) and align; same trap hits anyone building `reorderChildren()` input from `$parent->children`.
- **`up()`/`down()` silently no-op when the adjacent sibling is trashed** — `HasTreeMutation.php:1517-1563` resolves the neighbour by exact `rgt = lft - 1` through the soft-delete scope; a trashed neighbour yields null → `false` with no error. Skip over trashed neighbours or swap with the raw row; either way pin with a test.
- **Placement onto trashed targets is silently accepted** — `appendToNode($trashedParent)` succeeds via the raw bounds read (`:856-887`), producing a live node inside a trashed subtree whose restore-stamp bookkeeping never saw it. Throw (like the unplaced-target guard) or document deliberately.
- **`moveTo(int)` resolves its target at queue time** (`:196`) — queries inside what looks like a pure queue call and can be stale by `save()`; also `orderedSiblingsUnder` (excludes trashed) and `moveToSiblingPosition` (counts trashed) disagree about positional indexing on soft-delete models.
- **No way to abandon a queued `PendingOperation`** — nothing clears `$pending` (not `refresh()`, not `delete()`); a queued-then-deleted model still dispatches the op on a later save. Add `clearPendingOperation()` and clear on delete.

### Repair / soft deletes

- **Anchored `fixTree()` whose anchor row is missing writes the orphan subtree at lft 1, colliding with the live root** [reproduced] — `TreeRepairBuilder.php:367-403`. fixTree *created* corruption. `AggregateRepair::fixAggregatesChunk` already throws for exactly this; port that guard.
- **forceDelete of a trashed parent deletes live (individually restored) descendants without decrementing aggregates** [reproduced] — `NodeTrait.php:225-235`'s `alreadyTrashed` guard skips `applyAggregateOnDelete` for the parent (correct) but the cascade hard-deletes the live child whose contribution is never subtracted. Silent drift via the public API.
- **Soft-delete cascade silently no-ops with `immutable_datetime` casts** — `HasSoftDeleteTree.php:315-322` accepts only `Carbon|string`; `CarbonImmutable` fails both → cascade returns early, descendants stay live, no error. Type against `\DateTimeInterface` + `->format($node->getDateFormat())`. Same hole in `captureRestoreMarker`.
- **Restoring a child whose ancestor is still trashed silently succeeds** — live row under a trashed parent, partially invisible ancestor chain. Decide the semantic (allow + document, block, or auto-restore chain) and pin it.
- **`fixMaterialisedPaths` applies weaker validation than the save path** — separator-in-segment embedded as-is when rejection is on, empty segments silently skipped (descendants then chain off stale values), `maxLength` and `uniquePerParent` never enforced. Repair output should satisfy save-path invariants or report violations.

### Aggregates

- **`CreateHookApplier` evaluates filter predicates against raw (uncast) attributes** [reproduced] — `CreateHookApplier.php:90-125`. Creating with `active = 1` (int) against a boolean cast + `filter: ['active' => true]` drifts permanently. DeltaCapture fixed exactly this for updates (`DeltaCapture.php:142-163`); apply the same cast-aware read to all three create branches.
- **`aggregateErrors()` reports invalidated lazy columns as permanent drift; the listener-side scan silently *writes* heals** [reproduced] — `AggregateDiffer.php:62-67` has no lazy-awareness (healthy `LazyArea` reports drift forever, poisoning monitoring), while `ListenerMaintenance::buildListenerNodeMeta` reads through `getAttribute()` and triggers per-node refresh UPDATEs from a read-only diagnostics call. Skip lazy defs in the differ; read stored values raw in the scan.
- **Reading a lazy aggregate writes to the DB per row, and `withFreshAggregates()` doesn't prevent it** [reproduced] — the projection overwrites the value but not the `_computed_at` stamp, so `isStale()` still refreshes (N UPDATEs during a read loop; fatal on read replicas). Project + hydrate the stamp companion for lazy columns, and document that lazy reads write.
- **`withDeferredAggregateMaintenance` suppresses class-wide but repairs only the anchor's tree** — `DeferredMaintenanceRunner.php:51-143`. Mutations touching another scope/root inside the block are left silently drifted. Track touched roots and repair each, or detect and throw.
- **Float precision in the delta path** — `CompanionSourceTransform::applyPhp` floats every numeric and `formatNumeric` emits 14 significant digits; BIGINT sums beyond 2^53 and high-precision DECIMALs drift via the very path the change-feed comparator (which is digit-exact) will then faithfully report. Keep ints as ints; consider string/bcmath passthrough for decimals. Related: `DeltaCapture.php:182`'s `$delta !== 0` never matches `0.0`, so no-op saves still trigger full ancestor UPDATEs.

### Query layer / SQL

- **MariaDB fresh-read derived path snapshots query state too early** — `FreshAggregateProjector.php:234-238`: `limit()` before `withFreshAggregates()` compiles `IN (SELECT ... LIMIT)` which MariaDB rejects (error 1235); constraints added *after* that widen the rowset produce silently wrong (zero/NULL) fresh values on MariaDB only. Strip orders/limit/offset from the cloned id query and/or defer the snapshot to compile time via `beforeQuery`.
- **`withFreshAggregates()` alias keys are interpolated raw into the SELECT** [reproduced injection] — `FreshAggregateProjector.php:133` etc. `['x, (SELECT name FROM areas LIMIT 1) AS smuggled' => ...]` executes verbatim. Validate against `^[A-Za-z_][A-Za-z0-9_]*$` or grammar-wrap, in `Aggregate::into()`/`resolveRequest()`. Source column names passed to `Aggregate::sum()` likewise.
- **Identifier quoting is inconsistent across the write/repair/aggregate-read paths** — `TreeQueryBuilder::withDepth`/`whereIsLeaf` wrap; `TreeMutationBuilder`, `TreeRepairBuilder`, `AggregateSqlFragments`, `FreshAggregateProjector` interpolate `{$this->lft}` etc. raw. A custom lft/rgt rename to `left`/`right`/`order` breaks every mutation with backend-dependent syntax errors. Wrap once at builder construction; add a reserved-word fixture exercised through mutation + repair + fresh reads (the existing `ReservedIdentifierTest` covers only two read methods).
- **Scope-equality float collapse** — `NestedSetScopeResolver.php:165-167` compares numeric scopes via `(float) $a === (float) $b`; distinct 64-bit values above 2^53 (snowflakes) compare equal → `assertSameScope` passes → cross-tree append corrupts both trees. Compare integer-like values as canonicalised strings.
- **`withDepth()`/`withFreshAggregates()` silently discard a narrowed `select()`** [reproduced] — `addSelect(['*', ...])` re-widens. Only add `'*'` when columns are empty.
- **`bulkInsertTree` root seeding races concurrent root creation** — `HasBulkInsert.php:246-254` reads `max(rgt)` unlocked; `actMakeRoot` defends this exact race with an advisory lock + FOR UPDATE and a comment saying the unlocked read corrupts on PG. Reuse that discipline.

### Concurrency

- **`deleting`-hook structural re-read is not locked** — `NodeTrait.php:180-183` uses plain `first()`; every other stale-bounds path got `lockForUpdate`. A concurrent gap-shift between read and DELETE deletes the wrong descendants / closes the wrong gap. Add the lock (skip on sqlite, matching `getPlainNodeData`); add a concurrent move-vs-delete test (the concurrency suite covers everything except deletes).
- **PG READ COMMITTED recompute window even under `'auto'` locking** — `RecomputeMaintenance.php:110-136`: SELECT…FOR UPDATE locks outer rows but the correlated descendant subqueries read the statement snapshot; EvalPlanQual re-fetches the row, not the subquery. Lock first (bare `SELECT id ... FOR UPDATE`), then compute — or document the residual window honestly (the config comment currently attributes it only to `'never'`).
- **Unbounded structural UPDATEs** — `TreeMutationBuilder.php:49-75, 147-159`: `makeGap`/`closeGap`/`moveNode` update every row in scope with no WHERE. On PG that's a new tuple + WAL per row per mutation. Bounded predicates exist (`WHERE rgt >= at` for makeGap etc. — `reorderSiblings` already does this). Caveat: the giant lock footprint is doing accidental concurrency work; pair the change with re-running the concurrency suite and fuzzers. Pre-1.0 is the time to decide.
- **Lock ordering is not canonicalised** — crossing moves acquire mover/target locks in opposite orders; the DB resolves by killing one transaction (integrity preserved, but callers get raw deadlock exceptions). Document or sort acquisitions by key. Same for `candidatesForRecompute` (no ORDER BY before FOR UPDATE).

### Diff / import / export

- **Flat-snapshot ordering produces spurious `Moved` entries** [reproduced] — `TreeDiff.php:459-516` assigns sibling positions by encounter order; diffing the same DB state ordered by lft vs id yields `moved = 2`. Sort flat rows by lft when present.
- **Diff hardcodes `id`/`parent_id`/column names** — `TreeDiff.php:44, 468, 588`; custom-column and custom-PK models silently mis-diff (structural columns diffed as content; everything looks like a root). Resolve from the model or document the limitation. Zero diff/import tests use custom-column/custom-PK fixtures.
- **`TreeDiff::apply()` is unusable on scoped models with aggregates** — passes `anchor: null` to the deferral wrapper, which throws `ScopeViolationException`. Undocumented, untested.
- **`fromJsonTree(toJsonTree())` fails outright by default** [reproduced] — exporter emits `id`/`label`/`extras`; importer drops `label` → NOT NULL violation. The docs claim both shapes round-trip; the suite's own round-trip test quietly passes `extras: ['name']`. Add a `labelColumn` import option and fix the docs.
- **Static trait method calls fire E_DEPRECATED on every soft delete** [reproduced] — `NodeTrait.php:204, 255` call `HasSoftDeleteTree::applySoftDeleteCascade($node)` by trait name (4 notices per delete, removal slated for PHP 9). Use `static::`. Hidden because `phpunit.xml` doesn't surface deprecations — turn on `failOnDeprecation`/`displayDetailsOnTestsThatTriggerDeprecations`.

---

## 4. Pre-1.0 design decisions worth making now

1. **Scope on `NodeBounds`** (finding 2.8) — the single most valuable API change while BC is free.
2. **`aggregate_locking: 'always'` is a documented no-op alias of `'auto'`** — implement it or drop the value; a three-state enum with two identical states invites cargo-cult config.
3. **`defaultOrder()` on scoped models is non-deterministic** — every tree has lft 1, 2, 3…; ties interleave arbitrarily across backends. Order by scope columns first when the model declares a scope.
4. **Fresh-select column-reuse footgun** — `withFreshAggregates()` reusing a real column name overwrites `$original`, and a later `save()` feeds a wrong delta baseline into maintenance (documented in a docblock, enforced nowhere). Force a distinct alias or block `save()` on overlaid models.
5. **Aggregate events / change feed dispatch pre-commit** — a rollback leaves listeners having observed phantom changes. `DB::afterCommit()` or a prominent doc.
6. **Deferred `fixAggregates` runs after commit** in `bulkInsertTree` and `TreeDiff::apply()` (no caller transaction) — a crash between commit and repair leaves persistent drift; the clone path is only safe because it wraps its own outer transaction.
7. **Secondary index on `[scope…, parent_id]`** — the shipped composite can't serve `children()` / `whereIsRoot()` / fixTree's parent walk; PG/sqlite users don't get the FK freebie MySQL adds.
8. **Exception marker sweep** — ~20 sites throw bare `InvalidArgumentException`/`LogicException`/`RuntimeException`, so `catch (NestedSetException)` misses them despite the marker's "every exception" promise (enforced only for `src/Exceptions/` classes).
9. **`anchoredCountErrors` semantics** — `countErrors($anchor)` uses the anchor only as a scope carrier (whole-table on unscoped models) while `fixTree($anchor)` bands to the subtree, so `TreeFixResult::hasErrors()` can be true after a fully converged anchored repair. Band the post-fix count or document.
10. **Helpers assume the full framework** — `config()`/`event()`/`resolve()` come from `laravel/framework`, not the three `illuminate/*` packages composer.json requires. Guard the helpers or require the framework honestly.
11. **MariaDB `SET STATEMENT` prefix has no version floor** — errors hard (breaking every fresh read) on MariaDB < 10.3 rather than degrading; also `cursor()`/`lazy()` bypass `runSelect()` so they silently lose the hint. Gate on the server version already being parsed, override `cursor()`.

---

## 5. Test suite & CI

The suite is in excellent shape overall (376 files, every concern with a dedicated directory, fork-based concurrency tests, seeded + exploratory fuzzers, a real 36-cell matrix with per-backend coverage flags, mutation testing on PRs). Gaps:

1. **`top_k_areas` is missing from the truncation list** at `tests/TestCase.php:66` — row leakage between tests on MySQL/MariaDB/PG. One-line fix.
2. **Fuzzers run on sqlite only, and pinned seeds never run on PR CI** — `fuzz.yml` hardcodes `DB_CONNECTION: sqlite`; the backend-specific mutation SQL (the riskiest surface) is never fuzzed against MySQL/MariaDB/PG. Add a backend matrix to the scheduled run and a cheap pinned-seed job on PRs; consider create-issue-on-failure so found seeds aren't lost.
3. **Missing fuzzers:** materialised paths (highest value — paths are rebuilt on insert/move/rename/fixTree/clone/diff yet no fuzzer asserts coherence), `TreeDiff::apply()` (random before/after pairs → apply → assert equality; the oracles already exist), subtree clone, and a stale-instance/no-refresh pool in the aggregate fuzzer (would have caught 2.1).
4. **Class-level `allowBrokenTreeAtTearDown = true` in ~20 files** — legitimate for Corruption/, but Events, Schema, and several others disable the integrity postcondition for every test in the file with no comment. Narrow to per-test (the pattern already used in `ScopingTest.php:182`).
5. **tearDown integrity check covers 4 of ~40 fixtures** — none of the aggregate or path fixtures get the structural postcondition. A generic loop over fixture classes would extend the net cheaply.
6. **Targeted gaps called out above**, most valuable first: retry-after-failed-save (1.2), diff apply on a hard-delete model (1.1), guarded-column fixture (2.5), multibyte + two-column-scope + closure-source + SoftDeletes path fixtures (2.2–2.4, repair), trashed-sibling navigation and reorder (3), concurrent move-vs-delete, `withFreshAggregates` + `limit`/`orWhere` on MariaDB, reserved-word structural columns through the mutation path, naive JSON round-trip, cross-shape aggregate equivalence (the four backend SQL shapes are never asserted equal on the same fixture).
7. **`InteractsWithTrees` docblocks promise scope checks the assertions don't make** — `assertIsDescendantOf` passes for coincidentally nested bounds across different scoped trees. Compare resolved scope attributes or soften the docs.
8. **Duplicate migration timestamp prefixes** (000015/16/26/27 each shared by two fixture migrations) — deterministic today, but CLAUDE.md warns ordering matters; renumber.
9. **`phpunit.xml` hides deprecations** — which is how 3's trait-name deprecation stayed invisible. Turn deprecation display/fail on.

---

## 6. Docs

Docs accuracy is exceptional — 20+ examples were checked against source and every signature, option, and behavioural claim held, including obscure ones. The exceptions:

- **`docs/maintenance/corruption.md` is stale post-0.23.0**: the `countErrors()` example shows 7 of 9 keys; `overlapping_bounds` and `even_bounds_width` have no taxonomy sections; line 196's "per-row UPDATEs" predates the chunked CASE-WHEN writer; line 130's "unplaced rows aren't treated as corruption" is contradicted by `invalid_bounds` flagging every `lft = rgt = 0` row [reproduced]; and the claim that cycles aren't surfaced by `countErrors()` is **false** — parent_bounds_mismatch + depth_mismatch provably always fire on a cycle [reproduced]. The corresponding "detection gap" comment in `CorruptionRecoveryTest.php:243-247` should be strengthened to assert detection fires.
- **fixTree's sibling-order destruction** (2.7) is mentioned only in fix-tree.md, not in corruption.md's recovery recipes.
- **tree-diff.md** overstates: "apply() reproduces the after snapshot exactly — sibling order included" (false for roots), and the Eloquent-collection example is exactly the flat shape that mis-orders (3) and can throw (2.6).
- **exporters.md** claims `fromJsonTree()` is the inverse of `toJsonTree()` (2.6 above).
- **materialised-paths.md** never mentions soft deletes (trashed siblings block path reuse; restores never re-check collisions), and its headline "move = one rewrite UPDATE" claim has no query-count test.
- **CLAUDE.md** still says "README.md — extensive usage docs; treat as the spec" — README is now a landing page; the spec lives in `docs/`.
- Docs show `implements MaintainsTreeAggregates` on aggregate-free models (deliberate, but `HasNestedSet` would be the minimal honest contract in e.g. the materialised-path example).

---

## 7. Packaging

- **No `.gitattributes`** — dist archives ship `tests/` (376 files), `docs/`, `docs-site/`, `.github/`, `REVIEW.md`, `CLAUDE.md`, phpstan/rector/infection configs. Add `export-ignore` entries.
- **`REVIEW.md` (31 KB of internal audit notes) is committed at the repo root** — move to PR descriptions or delete.
- LICENSE copyright is "Copyright (c) 2026 Bryan" — bare first name; fine legally, unusual for a published package.
- composer.json is otherwise clean (constraints line up with the CI matrix, `suggest` entries correct, provider auto-discovery).

---

## 8. Notably well done

Worth recording so future refactors don't regress them:

- **Stale-bounds discipline on the hardened paths**: dispatch-time re-reads with `lockForUpdate`, the `deleting`-hook re-read, restore-cascade bounds re-read, and `emitSubtreeRewrite`'s fresh-bounds re-read — a whole class of bugs systematically closed (which is exactly what makes the two missed paths, 2.1 and the unlocked delete re-read, stand out).
- **moveNode band arithmetic and MySQL left-to-right SET handling** — hand-traced forward/backward/no-op/cycle-guard cases; all correct, with accurate in-place comments.
- **PG advisory lock + ORDER BY…LIMIT 1 FOR UPDATE in `actMakeRoot`** — correct per-backend reasoning for a race most packages don't even know exists.
- **Hook-order correctness for combined move + source-update saves** — the saving-listener sequence composes exactly for SUM/COUNT/MIN/MAX including filter flips (verified adversarially; worth a pinning test since it depends on boot order).
- **`SaveCancelledException`** converting Laravel's `return false` idiom into a rollback.
- **Relations scope hygiene** — constraint, eager-constraint, `match()`, and existence queries each independently enforce scope, with comments explaining why.
- **`AggregateDefinitionValidator`'s companion-adoption rules** and boot-time `$fillable` assertions — excellent footgun removal.
- **ChangeFeedRecorder's digit-exact BIGINT/DECIMAL normalisation.**
- **Test infrastructure**: tearDown integrity check that skips when the test already failed (avoids masking), per-scope integrity loops, `syncSequence`, zero swallowed catches across 41 catch blocks, and seeds printed in every fuzzer failure.
- **Cycle-termination guards in both repair walkers**, iterative to 2,000 levels, with `CountErrorsBlindSpotsTest` existing specifically to pin formerly invisible corruption.
- **CI**: real 36-cell matrix, SHA-pinned actions, per-backend Codecov flags so backend-specific SQL shows covered, mutation testing on PRs, documented advisory ignores.

---

## 9. Suggested order of attack

1. **Corruption-class bugs first** (each is a small, testable fix): diff hard-delete removes (1.1), retry-after-failed-save (1.2), stale-bounds delta capture (2.1), multibyte path offset (2.2), multi-scope binding order (2.4), missing-anchor fixTree guard, scope float collapse.
2. **Silent-wrong-data fixes**: closure-path repair/clone (2.3), guarded-column clone (2.5), create-hook cast filters, lazy drift reporting, forceDelete-after-restore drift, immutable-datetime cascade, soft-delete visibility splits (reorder/up/down/trashed targets).
3. **Diff applier robustness** (2.6 + flat ordering + scoped/custom-key support) as one work package — it shares a fix shape (topological ordering + final reorder pass).
4. **Pre-1.0 API decisions** (section 4) — especially scope-on-NodeBounds and the bounded-UPDATE question, both much cheaper now than later.
5. **Test/CI hardening** (section 5): truncation list, deprecation visibility, backend fuzzing, the new fuzzers, and the targeted regression tests for everything in 1–3.
6. **Docs + packaging sweep** (sections 6–7) last — mostly mechanical.
