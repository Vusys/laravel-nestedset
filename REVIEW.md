# Package review — recommended next steps

Date: 2026-06-11. Scope: full review of `src/`, `tests/`, `docs/`, and CI across six parallel deep-dives (mutation engine & concurrency, aggregates, query layer & relations, repair/soft-delete/diff/import, test-suite & CI gaps, docs & public API). Findings marked **[reproduced]** were confirmed with actual failing probe code run against the suite during this review (probes were deleted afterwards; nothing is left in the working tree). Everything else is grounded in specific code with file:line references.

The overall verdict first: this is an unusually strong pre-1.0 package. The single-process mutation engine's CASE-WHEN gap math was hand-verified correct (forward, backward, into-parent, adjacent no-op — no off-by-one anywhere). The insert-path locking design is sound. The test suite (2,180 tests, 11 seeded fuzzers, fork-based concurrency tests, per-test integrity checks in tearDown, 36-cell CI matrix, sharded multi-backend mutation testing) is well beyond what most published packages ship. The problems below cluster in four areas: **stale in-memory state** (a recurring pattern — the delete path was hardened against it but its siblings weren't), **concurrency around moves** (inserts are protected, moves are not), **scoped-model edge cases in the query layer**, and **detection blind spots in `isBroken()`**.

---

## 1. Confirmed bugs — fix these first, each with a regression test

These were reproduced with failing probes during the review. They are ordered by severity × likelihood of being hit by a real adopter.

### 1.1 Eager-loading `children` on a scoped model returns nothing [reproduced]

`src/Concerns/HasTreeRelations.php:39-48` bakes the scope predicate into the `HasMany` at construction time from `$this`'s attributes. Eloquent's eager-load / `withCount` / `whereHas` paths build relations on an attribute-less prototype instance, so the predicate compiles to `menu_id IS NULL` and matches nothing. `MenuItem::with('children')`, `->load('children')`, `withCount('children')`, and `whereHas('children')` all return empty.

The scope predicate defends against nothing real — `parent_id` references a globally-unique primary key, so a child can't point at a parent in another scope unless the data is already corrupt. Simplest fix: drop the predicate (matching the plain `parent()` BelongsTo). If you keep it, it must move into a custom relation's `addConstraints()`/`addEagerConstraints()` like `DescendantsRelation` does. Existing tests only exercise lazy access.

### 1.2 Per-model column-name overrides break the entire read layer [reproduced]

`docs/reference/config.md:44-55` documents overriding `getLftName()` etc. per model, and the mutation/aggregate paths honour it (`CustomColumnsBranch` fixture proves it). But `TreeQueryBuilder::lftColumn()/rgtColumn()/parentIdColumn()/depthColumn()` (`src/Query/TreeQueryBuilder.php:25-51`) read only global config — the comment at line 22 ("overridable in Phase 8 via NodeTrait") shows the wiring was planned and never landed. Every scope (`defaultOrder`, `whereIsRoot`, `whereDescendantOf`, `withDepth`), both custom relations, and `FreshAggregateProjector::applyFreshSelects` fail with hard SQL errors on a column-renaming model — or, worse, run silently wrong if a column literally named `lft` also exists.

Fix: have the builder delegate to `$this->getModel()->getLftName()` (the model accessors already fall back to config). Add a full query-layer test pass over `CustomColumnsBranch`.

### 1.3 Moves and deletes trust stale in-memory aggregate attributes — silent permanent drift [reproduced]

Delta maintenance updates aggregate columns via raw SQL and never syncs the in-memory model. Any subsequent move or delete of that same instance transfers/subtracts stale values. Three ordinary scenarios all produced silent drift on the `Area` fixture:

- update a source column then `delete()` the same instance;
- append a child, then move the parent without `refresh()`;
- create a leaf and immediately move it with the same instance (`appendToNode($a)->save(); appendToNode($b)->save();`) — the move transfers an in-memory total of 0.

The `deleting` hook in `src/NodeTrait.php:122-157` deliberately re-reads structural and scope columns from the DB ("the in-memory attributes may have gone stale") but **not the aggregate columns** the deleted hook is about to subtract. Same gap in `src/Aggregates/Lifecycle/LifecycleSupport.php:221-241` and `DeleteHookApplier.php:95-151`. Every existing aggregate test calls `refresh()` before mutating, and the aggregate fuzzer re-fetches every model fresh each step — so a stale instance can never reach a mutation in the current suite.

Fix: re-read registered aggregate columns alongside the structural re-read in the deleting hook and alongside `freshBoundsOf` in the move path; or have `DeltaCapture::apply`/`CreateHookApplier` sync self's in-memory aggregate attributes after their UPDATE. Then add a **stale-instance fuzzer mode** (see §4.3).

### 1.4 `withDeferredAggregateMaintenance` with a non-root anchor leaves ancestors above the anchor drifted [reproduced]

The deferral counter suspends maintenance class-wide, but the closing `fixAggregates($anchor)` only repairs the anchor's subtree (`src/Aggregates/Repair/DeferredMaintenanceRunner.php:95`, `AggregateDiffer.php:741-746`). Mutations inside the closure that change rollups of the anchor's own ancestors — or of other trees on unscoped models — are never repaired. Reproduced with a mid-tree anchor: the root stayed drifted in three columns.

Fix: walk `parent_id` to the root before repairing, or track touched roots during deferral, or document "anchor must be the root of every tree mutated inside the closure" as a hard requirement.

### 1.5 `TreeDiff::apply()` removing a subtree double-runs the delete cascade and corrupts live rows [reproduced]

When `after` drops a whole subtree, the diff emits `Removed` for every member, parents first. `doRemoves` (`src/Diff/TreeDiffApplier.php:336-371`) fetches rows up front and deletes one by one — deleting the parent cascades to the child, then the loop calls `delete()` on the already-gone child, whose `deleted` hook fires anyway with stale bounds → a second spurious `closeGap` (and double aggregate decrement on aggregate models). Reproduced: `parent_bounds_mismatch = 1, bounds_out_of_range = 1` after a perfectly ordinary diff. On soft-delete models the double delete re-stamps the child, splitting it out of the parent's restore marker.

Fix: skip rows whose DB row vanished mid-loop (or reduce the removed set to subtree roots before deleting); belt-and-braces, the `deleted` hook should no-op when the DELETE affected zero rows. Then add the **diff round-trip fuzzer** (§4.4), which would have caught this immediately.

### 1.6 `isBroken()` blind spot: partially overlapping sibling intervals read fully clean [reproduced]

Siblings `A(2,6)` and `B(4,9)` — intervals overlap without nesting — pass all seven checks in `src/Query/TreeRepairBuilder.php:50-99`: `isBroken()` returns false while `whereDescendantOf(A)` returns `B`. The docs' own invariant table in `docs/maintenance/corruption.md` ("Containment ⇔ ancestry") promises exactly the property no check implements. Even-width bounds (`rgt - lft` even) are similarly invisible — parity is never checked. The documented recovery workflow ("`isBroken()` → if true `fixTree()`") never fires, even though `fixTree()` would repair this shape trivially.

Fix: add an `overlapping_bounds` category (self-join: `a.lft < b.lft AND b.lft <= a.rgt AND a.rgt < b.rgt`, same scope) and a parity check. Both are index-friendly.

### 1.7 Anchored `fixTree` rebuilds materialised paths against the anchor's stale pre-repair bounds [reproduced]

`src/Concerns/HasTreeRepair.php:253-259` filters the path rebuild by `$anchor->getLft()/getRgt()` read from memory, *after* the structural rebuild has just renumbered the DB. Whenever the repair changes the anchor's own bounds (the normal case for the documented "raw `UPDATE … SET parent_id` then `fixTree()`" recovery), the path rebuild covers the wrong band — reproduced with a path left as garbage and `materialisedPathsRepaired = ['url_path' => 0]`, directly contradicting `docs/maintenance/corruption.md:182`. The aggregate pass does this correctly by passing `$rootId` into SQL that reads fresh bounds; the path pass is the odd one out. Fix: re-read the anchor's bounds by `$rootId` before building the band filter.

### 1.8 Medium-severity reproduced bugs

- **`NodeCollection::toTree()`/`toFlatTree()` silently drop disconnected nodes** (`src/NodeCollection.php:79-98`): a filtered fetch whose nodes don't connect to the inferred root vanish from output; mixed-scope collections drop whole trees. Also `linkNodes()` marks absent parents as loaded-null, suppressing future lazy loads. Either document the contract or treat parent-absent nodes as top-level (more useful for filtered fetches). Note `docs/querying/tree-shaping.md:28` also incorrectly claims `toTree()` uses lft/rgt ranges — it uses `parent_id`.
- **Clone silently violates `uniquePerParent` path constraints** (`src/Concerns/HasSubtreeClone.php:266-269`): cloning a slugged node under its own parent produced two siblings with identical `url_path` — the same operation via a normal save throws `DuplicatePathSegment`. The path rebuild also runs *after* the clone transaction commits, so a crash between leaves NULL paths.
- **Scoped `fromJsonTree` root import is a dead end** (`src/Import/JsonTreeImporter.php:64-67` vs `HasBulkInsert.php:105-118`): the importer validates scope columns on root rows, implying support, then `bulkInsertTree($tree, null)` unconditionally throws `ScopeViolationException`. Relatedly, **`includeKeys: true` can never work** — the bulk-insert reserved-attribute guard throws before any SQL, making `JsonImportKeyCollisionException` dead code (the test suite admits this in a comment). Either implement both or remove the options.
- **Inspection predicates throw bare `LogicException` on never-placed in-memory models** (`src/Concerns/HasNodeInspection.php:54-64`): `(new Category)->isDescendantOf($root)` throws "Attribute lft is not numeric" while a persisted unplaced row answers `false` cleanly — and `docs/querying/inspection.md` constructs exactly this in an example. Pick a contract (return false, or throw `UnplacedNodeException`) and test it.

---

## 2. Concurrency — the biggest unverified risk area

The concurrency story is honest and tested for **inserts and makeRoot-with-existing-rows only**. Moves, reorders, and first-roots have real corruption paths that the current fork-test matrix cannot see. These were not reproduced (they need multi-connection interleaving) but the code paths are unambiguous.

### 2.1 Critical: existing-node moves read the mover's own bounds without a lock

`src/Concerns/HasTreeMutation.php:869` — `freshBoundsOf($this)` defaults to `lockForUpdate: false` (`:1325`). Only the *target's* bounds are locked. The unlocked read is stashed and later drives the `moveNode()` CASE band constants. Between that read and the target lock sits the `SubtreeMoving` event dispatch (arbitrary user listeners) and the before-move aggregate hook — a wide window. A concurrent committed insert/move that shifts the mover's bounds in that window makes the CASE `BETWEEN` match the wrong rows → silent overlapping bounds. On MySQL REPEATABLE READ the anomaly is deterministic whenever another transaction commits in the window (snapshot `from` mixed with current-read UPDATE).

Fix is one argument: `freshBoundsOf($this, lockForUpdate: true)`. Mover-then-target lock ordering can deadlock against opposite-direction moves — acceptable, since the package's own test harness already treats 40001/40P01 retries as the contract (but see §5.3: the docs must then say so).

### 2.2 Major concurrency gaps

- **`makeRoot()` in an empty scope locks nothing** (`HasTreeMutation.php:1253-1263`): the lock targets "the row owning max rgt"; with zero rows, nothing is locked and two concurrent first-root creators both insert at `(1,2)`. Realistic for scoped models (a new tenant's first two writes). Fix: per-scope advisory lock when the max read returns null, or document a unique `(scope, lft)` index as the backstop. The existing `MakeRootConcurrencyTest` deliberately seeds one root first, so this is invisible to it.
- **`reorderChildren()` reads bounds unlocked and outside its transaction** (`HasTreeMutation.php:256-280` vs the transaction at `:327-332`): a concurrent append to the same parent between the reads and the UPDATE corrupts the shift windows. Fix: wrap the whole method (reads included) in the transaction with `lockForUpdate` on the parent.
- **A dirty scope column bypasses every cross-scope protection on save/move** (`NestedSetScopeResolver::valuesFor` reads in-memory attributes; `src/Scope/NestedSetScopeResolver.php:66-99`): `$item->menu_id = 2; $item->appendToNode($menu2Root)->save()` passes `assertSameScope` but the mover's disk row is still in menu 1, so the scoped UPDATE shifts menu-2 bystanders instead — both trees corrupted, no exception. A plain `$item->menu_id = 2; $item->save()` with no pending op has no check at all. The **delete** path was explicitly hardened against exactly this (`NodeTrait.php:122-157`); the save/move path wasn't. Fix: in the saving listener, throw `ScopeViolationException` when a scope column is dirty on an existing node, and document an explicit "move between trees" recipe.
- **`fixAggregates` can introduce drift when racing live writers**, contradicting drift.md's "safe to fire defensively on a cron": the differ (`src/Query/Aggregates/Maintenance/AggregateDiffer.php:164-301`) is an unlocked SELECT followed by chunked UPDATEs with no wrapping transaction — a classic read-modify-write lost update against concurrent delta maintenance. The per-mutation recompute path *does* lock; the repair path recommended for hourly cron doesn't. Also note `FOR UPDATE` in the recompute path is meaningless with `auto_transaction = false` and no caller transaction (lock releases at autocommit) — the config docblock doesn't mention this.
- **Chunked `FixAggregatesJob` retry duplicates the chain** (`src/Jobs/FixAggregatesJob.php:105-142`): a worker killed after `dispatch($next)` but before ack retries the job and dispatches a second `$next` — two self-redispatching chains, doubling per retry. Data stays correct (recompute is idempotent) but workload amplifies; consider `ShouldBeUnique` or a dedup key.
- **Minor but cheap:** cycle/self validation runs *after* the before-move aggregate hook and `SubtreeMoving` dispatch — with `auto_transaction = false`, an attempted move-into-own-descendant permanently corrupts aggregates even though the structure is untouched. Hoist the containment check above the hooks.

### 2.3 Concurrency tests to add (using the existing `ConcurrencyHarness`)

1. **`MoveNodeConcurrencyTest`** — workers repeatedly move two fixed leaves between two parents while others insert siblings; assert `isBroken() === false`. The single biggest test gap; would catch §2.1.
2. **Deterministic interleave variant** — a `SubtreeMoving` listener that, via a second DB connection, commits a sibling insert mid-window, then asserts the tree intact. Catches §2.1 without fork flakiness; runs on CI's MySQL/PG cells.
3. Empty-scope concurrent `makeRoot` (§2.2), `reorderChildren` vs `appendToNode` race, `fixTree` vs live writers, delete-vs-insert on the same parent.
4. A **mixed-workload fork fuzzer** (moves + inserts + deletes + aggregates, N workers, deadlock retry) asserting `assertTreeIsIntact` + `assertAggregatesAreIntact` — the concurrency analogue of `TreeStructureFuzzerTest`; subsumes 1–3 over time.
5. Consider a second harness flavour using **two PDO connections in one process** with step barriers — runs on macOS/sqlite-less environments and allows deterministic interleaving assertions.

---

## 3. Other high-confidence static findings

- **Filter-predicate cast asymmetry** (`src/Aggregates/Lifecycle/DeltaCapture.php:143-148`): `$newPred` evaluates against raw uncast attributes, `$oldPred` against cast-transformed `getOriginal()` values, with strict `!==` comparison. A `filter: ['active' => true]` with a boolean cast over TINYINT misfires — delta captured as `−old` instead of `+(new−old)` while the SQL side keeps the row in-filter. Permanent drift. The only equality-filter fixture (`TypedArea`) filters on a plain string column where casts are identity, so tests can't see it. The listener path has the same asymmetry plus a bug: `setRawAttributes($node->getOriginal(), true)` feeds cast values where raw is expected — breaks on `array`/`json`/`encrypted` casts. Fix: `getRawOriginal()` for the old side; normalise filter values against casts or document strict-type requirements. Add a filter/cast matrix test across backends (MySQL with PDO prepare emulation returns numerics as strings — same misfire with no casts at all).
- **Restore cascade uses stale in-memory bounds** (`src/Concerns/HasSoftDeleteTree.php:168`) — asymmetric with the hardened delete path. Trash a subtree, hard-delete a sibling (shifting trashed rows), `restore()` the original instance → the cascade band misses shifted descendants, which stay trashed under a restored parent. Same family as §1.3; fix it in the same pass.
- **Subtree move/rename never re-validates descendant path lengths** (`src/Concerns/HasMaterialisedPath.php:189-197` checks only the saved node; `emitSubtreeRewrite` at `:419-456` rewrites descendants with no check). Moving a subtree deeper silently overflows `maxLength` (or dies with a driver error on strict VARCHAR mid-transaction instead of `PathTooLong`).
- **Deep-recursion crash before the iterative planners**: `walkAssignPositions` and `bulkInsertPlan` were deliberately made iterative, but everything feeding them is recursive — `JsonTreeNormaliser::nestedRow`, `TreeDiff::walkNested`, `topologicalSortAdded`, clone's `normaliseEntry`. A 10K-deep array `fromJsonTree` crashes in the normaliser. Also `assertNoFlatCycle` is O(n²) on a chain.
- **MariaDB fresh-aggregate id-snapshot staleness** (`src/Query/Aggregates/Read/FreshAggregateProjector.php:234-238`): the derived-table shape snapshots the outer WHERE at `withFreshAggregates()` call time; widening the query afterwards (`orWhere`, `withTrashed`) yields silently wrong COALESCE zeros on MariaDB only, and a prior `limit()` is cloned into the IN-subquery which MariaDB rejects. At minimum document "call `withFreshAggregates()` last".
- **Decimal precision**: move/delete deltas round-trip stored decimals through PHP floats (`Numeric::asNumericOrZero`, `%.14F` formatting), and `AggregateValueComparator`'s tolerance (1e-4 absolute) then hides sub-tolerance drift from both `aggregateErrors()` and the fuzzer — small errors can accumulate forever because `fixAggregates` only rewrites rows the comparator flags. Worth a bcmath-exact differential test to size the real-world exposure.
- **Queued operations are silently last-write-wins** (`appendToNode($a)->insertAfterNode($b)->save()` drops the first op). Either throw on overwrite of an undispatched op or document it.
- **`makeRoot()` on an already-root, non-last root silently relocates it to the end of the forest** — untested and undocumented semantics; pin whichever you want.
- **Silent no-op when a model forgets `implements MaintainsTreeAggregates`**: every lifecycle hook early-returns, so `use NodeTrait` without the interface means pending operations never dispatch and rows land unplaced — silently, unless you run PHPStan. Throw a `LogicException` from `bootNodeTrait()` at boot time.

---

## 4. Testing investments, ranked by confidence-per-effort

The suite's composition gap is bigger than any missing category: fuzzers only ever run on sqlite, and they re-fetch all models fresh each step.

1. **Run the existing fuzzers against MySQL/MariaDB/PG in CI.** `.github/workflows/fuzz.yml` is sqlite-only today, yet the per-driver SQL shapes (LATERAL / derived-table / correlated) are exactly where backend divergence lives. One-file matrix change, zero new test code. Add a small pinned-seed fuzz job to PR CI too.
2. **Materialised-path fuzzer.** The only major subsystem with no fuzzer (19 hand-written tests). Random placements/moves/renames/reorders/soft-deletes on `SluggedCategory`, asserting after every step that stored paths equal a from-scratch rebuild.
3. **Stale-instance fuzzer mode.** The aggregate fuzzer re-fetches every participant per step, which is precisely why §1.3 survived. Add a mode that keeps and reuses held model instances without `refresh()`, plus non-leaf moves, interior-node cascade deletes, and combined "set source + move in one save" steps (the current fuzzer only moves and deletes leaves).
4. **In-memory mutation oracle + diff round-trip fuzzer.** `TreeStructureFuzzerTest` checks self-consistency invariants, which can't catch *valid-but-wrong* trees (e.g. `insertBeforeNode` landing after the sibling). A ~100-line oracle (parent map + ordered child ids) upgrades it to correctness checking, and gives diff testing free: random before/after, `TreeDiff::between(...)->apply()`, assert DB == after exactly. This would have caught §1.5 immediately.
5. **Concurrency tests** per §2.3.
6. **Ratchet Infection's `minMsi`/`minCoveredMsi` off 0.** The PR diff-mode gate currently cannot fail on surviving mutants. Also: fuzzers are excluded from Infection's test run, so boundary mutants in gap math (`>=`→`>`, `+2`→`+1`) whose only killer is a fuzzer escape — add small deterministic permutation-invariant tests to the default suite. And add a CI guard asserting the shard `--filter` union covers all of `src/` (a new top-level dir is silently un-mutated today).
7. **Property tests worth adding**: `fixTree()` on an intact tree is a zero-write no-op and idempotent; export→import→export identity over *random* trees (the existing round-trip test is fixed-shape); `diff(A,B)->apply(A) == B` and the invert round-trip; `up()` then `down()` is identity; clone produces a bounds-disjoint isomorphic copy leaving the source byte-identical; soft-delete→restore round-trips the exact snapshot; aggregate four-way equivalence (stored == fresh == fixAggregates == a PHP-side recompute — the fourth oracle would catch a bug shared by all SQL paths).
8. **Backend edges**: multibyte/emoji slugs (the pgsql test connection is `charset => 'utf8'`, worth revisiting); path-column truncation on deep trees; `toJsonTree` precision of large bounds. Low priority.
9. **Perf suite**: Bencher PR runs lack `--err`, so threshold breaches are advisory only. Query-count assertions (e.g. "fixTree is O(1) queries at any scale") would be noise-free and CI-stable where wall-clock isn't.

---

## 5. Documentation fixes

### Stale or wrong

- `docs/aggregates/drift.md:105-107` and `docs/maintenance/corruption.md:130` — the "unplaced rows are a legitimate transient state, maintenance is skipped" narrative predates the `UnplacedNodeException` hardening; that state is now only reachable via raw SQL. **`CLAUDE.md` carries the same stale claim** ("Calling `create()` without `appendToNode()`… leaves the row unplaced").
- `docs/reference/factories.md:409` — references a `NestedSetException` base class that doesn't exist (see §6.2).
- `docs/internals/architecture.md` — says nine concerns (there are eleven), misattributes `TreeExpression` as the backend-dialect generator, wrong file path for `MaintainsTreeAggregates`, snapshot labelled v0.13.0.
- `docs/internals/concurrency.md:10-17` — shows a stale, simplified `save()` override that omits the load-bearing `SaveCancelledException` dance.
- `docs/internals/aggregate-maintenance.md:138` — quotes `buildBitwiseSetClauses`, removed in 0.22.0.
- `docs/maintenance/fix-tree.md:8` — `countErrors()` example shows 4 keys; there are 7.
- `docs/aggregates/maintenance.md` — the flagship example uses `Bonuses::query()->update(...)`, a bulk update that fires no model events: it demonstrates the one write style the package cannot maintain.
- `FilterPredicate`'s class docblock claims values are inlined/escaped; the implementation uses PDO bindings (safe direction, but it will mislead a security review).
- `docs/querying/relations.md:55` — "the composite index already covers `depth`" is false (depth isn't in the index).
- `config/nestedset.php:92` — "See README → Telemetry" points at a section that no longer exists; this ships in the publishable config.

### Honesty gaps to close

- **Deadlocks**: the package's own harness retries SQLSTATE 40001/40P01 up to 16 times, but no user-facing page says concurrent writers must expect and retry deadlocks. After fixing §2.1, the concurrency page should state the contract plainly: corruption-free under the documented locks, deadlock-prone under contention, retry is the caller's job.
- **Write footprint**: `makeGap`/`closeGap`/`moveNode` have no WHERE clause beyond scope — every mutation rewrites and X-locks every row in the scope. On PG that's a full tuple version per row per mutation. Adding `WHERE rgt >= {at}` (and a band-bounded WHERE on moveNode) would shrink both the lock surface and WAL volume — likely the cheapest real-world perf and deadlock improvement available. At minimum, document the current footprint; `mutation-engine.md`'s "everything outside the band is untouched" is misleading.
- **Timestamps**: shifted bystanders never get `updated_at` stamped; the moved node only when `parent_id` changes. Undocumented.
- Soft-deleted mid-chain ancestors silently vanish from `ancestors` (breadcrumb gap) — consistent with Eloquent semantics, but worth a note plus an `ancestors()->withTrashed()` recipe.

### Missing pages

- **Migrating from kalnoy/laravel-nestedset** — the most obvious audience has no adoption guide (`docs/getting-started/migration.md` is a schema page whose title collides with the concept). Column mapping, API mapping table (`appendNode` → `appendToNode`+`save`, `rebuildTree` → `TreeDiff`/`fromJsonTree`, …), behavioural deltas (queued mutations, stored depth, unplaced-save throws, anchored scoped repairs), and the import-then-`fixTree()` recipe.
- **Troubleshooting page** — symptom → cause → fix (UnplacedNodeException on create, empty `descendants` after append without refresh, ScopeViolationException from fixTree, drift after raw SQL). The material exists scattered.
- **Operational sizing** — expected `fixTree()` behaviour at 1M+ rows; "hot parents serialise writers" implication of the parent FOR UPDATE; memory-safe patterns for huge subtrees (`->descendants()->defaultOrder()->cursor()`).
- The tree-diff page has no failure-modes section despite `apply()`'s rich validation; four diff exceptions appear nowhere in docs.

---

## 6. API hardening and pre-1.0 checklist

1. **Boot-time contract assertion** (§3 last bullet) — eliminates a whole bug class for non-PHPStan adopters.
2. **`NestedSetException` marker interface** on all 21 exceptions — already (incorrectly) documented as existing; genuinely useful for `catch (NestedSetException)`. Do the naming pass at the same time: the five path exceptions lack the `Exception` suffix, the mutation path's cycle guard throws bare `LogicException` while `CyclicMoveException` exists but is diff-only, and the `*Scope` exporters throw `LogicException` where everything else scope-related uses `ScopeViolationException`.
3. **Artisan commands**: `nestedset:check {model} {--anchor=}` (prints `countErrors()` + `aggregateErrors()`, exit 1 on errors — cron/CI friendly), `nestedset:fix-tree`, `nestedset:fix-aggregates {--chunk=} {--queue}`. The repair APIs, chunking, `onChunk` progress callback, and events make these thin wrappers; every adopter currently writes them by hand. A documented scheduler recipe (or shipped job) pairing `isBroken()`/`aggregatesAreBroken()` with the existing heartbeat events is the natural companion.
4. **Model-aware query scopes**: `whereDescendantOf(HasNestedSet|NodeBounds)` — when given a model, derive bounds *and* auto-apply scope columns. Closes both the cross-tree bounds-overlap footgun on scoped models and the kalnoy-adopter friction of `->getBounds()` everywhere.
5. **Positional-index consistency**: `moveTo($parent, 0)` is 0-based, `moveToSiblingPosition(1)` is 1-based. Standardise (0-based matches `moveTo`, `TreeDiff::siblingPosition`, and the factory) before 1.0.
6. **`withFreshAggregates()` overlay poisoning dirty tracking** — a read method that can silently persist drift on a later `save()` is the sharpest remaining read-path edge. Consider requiring an explicit alias for declared columns or an `overlay: true` opt-in.
7. Smaller regularisations: `toJsonTreeForest()` should always return a list (the doc itself calls the current dict-or-list contract unstable); `NodeBounds::height()` returns slot width, not height — rename; land the promised `TreeFixResult::nodesUpdated` rename in the same BC batch; mark the internal-but-public surface (`captureAggregateDeltas`, `applyAggregateOnCreate`, `markMoved`, `callPendingAction`) `@internal` before freezing; `up()`/`down()` returning `false` conflates "no sibling" with "save failed"; consider suppressing `NodeMoved` on no-op moves; chunked repair silently skipping rows on non-sortable string PKs deserves at least a diagnostics event.
8. Consider whether `MaintainsTreeAggregates` should be the mandatory interface for models with zero aggregates — either rename to something neutral or make `NodeTrait` work against `HasNestedSet` with aggregates as true opt-in.

---

## 7. Suggested sequencing

1. **Now (correctness, small diffs):** §1.1, §1.2, §1.6, §1.7 — each is a localized fix plus a regression test. Then §1.3/§2.2-restore (the stale-state family, one design decision applied consistently), §1.4, §1.5.
2. **Next (concurrency):** the one-argument fix in §2.1, the empty-scope `makeRoot` lock, `reorderChildren` transaction, dirty-scope-on-save guard — each landed together with its harness test from §2.3.
3. **Then (test infrastructure):** multi-backend fuzz CI, materialised-path fuzzer, stale-instance fuzzer mode, mutation oracle + diff round-trip, Infection ratchet. These protect everything above against regression and will likely surface a second round of smaller bugs.
4. **Then (docs):** the stale-claims sweep (§5), the kalnoy migration guide, the concurrency honesty pass.
5. **Pre-1.0:** the API regularisation batch (§6) in one or two coordinated BC-breaking releases, plus artisan commands.
6. **Opportunistic perf:** bounded WHERE clauses on `makeGap`/`closeGap`/`moveNode` — shrinks lock footprint, WAL volume, and deadlock frequency in one change; benchmark it with the existing Bencher setup.
