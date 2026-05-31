# Glossary

Reference for nested-set jargon, acronyms, and package-specific names used throughout the docs. Entries are grouped by topic; the sidebar TOC is alphabetical within each section.

## Acronyms

### BFS — breadth-first search

A tree traversal that visits every node at depth `d` before any node at depth `d + 1` — the queue-based "across, then down" walk. See [Tree traversal → BFS](#bfs) for the visit order and the walker's BFS strategy.

### CDC — change data capture

A pattern where every row-level change in a database (insert / update / delete) is emitted as a stream of events so downstream systems can mirror state without polling. Tools like Debezium and Postgres logical replication are the canonical examples. The package's [`NestedSetAggregateChanged`](events.html#aggregate-maintenance) event applies the same shape to maintained aggregate columns: one event per `(row, column)` whose stored value moved, carrying `oldValue` / `newValue` so a consumer (Redis, Kafka, Reverb, search index) can mirror the rollup without re-querying.

### CTE — common table expression

A `WITH` clause that defines a named query reusable within the surrounding statement. The recursive form (`WITH RECURSIVE`) walks adjacency lists, which is how non-nested-set hierarchies are typically queried. This package avoids recursive CTEs entirely — every ancestor/descendant query is a single `BETWEEN` thanks to the nested-set encoding.

### DFS — depth-first search

A tree traversal that follows one branch all the way to a leaf before backtracking and trying the next branch. See [Tree traversal → DFS](#dfs) for the pre-order vs post-order distinction.

### FK — foreign key

A column that references another table's primary key. `parent_id` is the package's only structural FK — to the same table, making the tree a self-referential one.

### N+1 queries

The anti-pattern of issuing one query for a list and then one query *per row* in that list — `N + 1` queries total instead of one or two. The nested-set encoding makes ancestors, descendants, and siblings cheap enough that even the unbounded `descendants` eager-load runs in two queries regardless of the result-set size.

### ORM — object-relational mapping

A library that maps database rows to in-memory objects. Eloquent is Laravel's ORM, and the entire package is built as a thin extension on top of it.

### PDO — PHP data objects

PHP's database-abstraction layer; Laravel uses one PDO connection per configured connection. The `useReadPdo()` switch routes a query through the read-replica PDO when one is configured — relevant for the [`withFreshAggregates`](../aggregates/reading.html) read path.

### PK — primary key

The unique identifier column on each row. The package accepts integer (`bigIncrements`) and UUID/ULID primary keys; the choice flows through to `parent_id`'s column type via `nestedSet(parentIdType: ...)`.

### UDF — user-defined function

A SQL function registered at the connection level. The package installs UDF `BIT_OR` / `BIT_AND` / `BIT_XOR` aggregates on SQLite so the same native-aggregate SQL works on every backend.

### UUID — universally unique identifier

A 128-bit identifier rendered as `xxxxxxxx-xxxx-...`. Supported as a primary key shape; the [exporters](../querying/exporters.html) hash UUIDs to a short prefix so Mermaid/DOT identifiers stay valid.

## Nested-set model

### Bounds

The `(lft, rgt, depth)` triple that describes a row's position in the tree. Wrapped as the immutable [`NodeBounds`](#nodebounds) value object; obtained via `$node->getBounds()`. The mutation engine, exporters, and aggregate hooks all pass `NodeBounds` around rather than loose integers.

### Containment

The relation `A.lft < B.lft AND B.rgt < A.rgt`, equivalent to "B is a strict descendant of A". This single predicate, expressible as one `BETWEEN`, is what makes every tree-shape query in the package O(1) statements instead of recursive.

### Depth

Distance from the root, stored as a column. The root is `depth = 0`. Maintained on every mutation, so depth-bounded queries (`where('depth', '<=', 2)`) are cheap; the [walker's `WalkFilter::depth()`](../querying/walking.html#pruning-with-walkfilter) uses *relative* depth (from the walk root) rather than the absolute column.

### Forest

A collection of independent trees in one table. An unscoped table holds one forest indexed by `parent_id IS NULL` roots; a scoped table holds one forest per scope value. The `*Forest` exporter variants render every tree in every scope.

### Gap

The `2 × N`-wide slot range a subtree of `N` nodes occupies in the `lft`/`rgt` sequence. `makeGap($at, $size)` opens a fresh `$size`-wide hole at position `$at` (shifting later values up); `closeGap` is the inverse. See [the Mutation Engine](../internals/mutation-engine.html#opening-and-closing-gaps).

### Invariant

A property the engine maintains on every mutation — e.g. `lft < rgt` for every row, `lft`/`rgt` unique within a scope, the contiguous-permutation rule. The [corruption taxonomy](../maintenance/corruption.html) is the list of "an invariant got broken" categories; [`countErrors()`](../maintenance/fix-tree.html) detects them; [`fixTree()`](../maintenance/fix-tree.html) restores them by rebuilding from `parent_id`.

### `lft` and `rgt`

The two integer columns that encode the nested set. Assigned by a pre-order walk: as you enter a node, stamp `lft`; as you leave, stamp `rgt`. Together they form the interval that contains every descendant's interval. The column names are configurable via `nestedset.columns.*`.

### Orphan

A row whose `parent_id` is non-null but points at a missing (or different-scope) row. Detected by `countErrors()`; not auto-fixable by `fixTree()` because the right answer is domain-specific — re-parent, promote to root, or delete. See [Corruption Reference → orphans](../maintenance/corruption.html#orphans).

### Pre-order numbering

The traversal that assigns `lft`/`rgt` integers: visit a node, recurse into its children left-to-right, return. The numbers come out so that each node's interval wraps every descendant's. This is also the natural read order — `ORDER BY lft` is depth-first display order.

### Scope

A column (or set of columns) that partitions one table into multiple independent trees. Declared via `#[NestedSetScope]` or `getScopeAttributes()`. Each scope's `lft` sequence restarts at 1; the mutation engine refuses cross-scope writes (`ScopeViolationException`). See [Scoped Trees](../querying/scoped-trees.html).

### Subtree

A node together with all its descendants. In SQL: `WHERE lft BETWEEN node.lft AND node.rgt`. In PHP: `$node->descendants` (strict) or `$node->descendantsAndSelf` (inclusive). The [walker](../querying/walking.html) operates on subtrees in memory without any further queries.

## Tree traversal

### BFS

Breadth-first search — every node at depth `d` before any node at depth `d + 1`. Implemented in `SubtreeWalker` as a queue-based generator. Honours `WalkSignal::SkipSubtree` (the children of the skipped node are not enqueued). Use BFS when you need level-by-level visits (rendering a level meter, computing tree width).

### DFS

Depth-first search — drill into one subtree fully before moving to the next sibling. The walker exposes pre-order and post-order DFS strategies; both use an explicit task stack so deep trees do not blow PHP's call stack.

### Level

The set of all nodes sharing a `depth` value. Level 0 is the roots, level 1 the children of roots, and so on. BFS is the "visit level by level" traversal.

### Post-order

DFS that visits children *before* their parent. Useful for "fold up" computations: summing weights bottom-up, rendering trees where a parent's display depends on its children. `WalkSignal::SkipSubtree` is a no-op in post-order — the children have already fired by the time the parent visit runs.

### Pre-order

DFS that visits a node *before* descending into its children. The default for every walker and exporter in this package, and the order `ORDER BY lft` produces from SQL. This is the order humans expect when reading a tree.

## Mutations

### Ancestor chain

The set of ancestors of a node: `WHERE lft < node.lft AND rgt > node.rgt`. Every aggregate maintenance `UPDATE` touches exactly this set — that's why the per-mutation cost is "delta UPDATE on the ancestor chain", not "rewrite the whole subtree".

### Band (move algorithm)

The interval `[boundFrom, boundTo]` that contains both the moving subtree's old position and the target position during a `moveNode`. Only rows whose `lft`/`rgt` falls inside this band are touched; everything outside is untouched. See [the band concept](../internals/mutation-engine.html#the-band).

### Bystander (move algorithm)

A row inside the move's band but *not* part of the moving subtree — the rows the subtree slides past. Bystanders shift by `∓height` (negative when the subtree moves forward, positive when backward) to fill or open the slot count the subtree vacates or needs.

### `CASE WHEN` UPDATE

The package's signature mutation shape: one SQL `UPDATE` whose `SET` clauses are `CASE WHEN ... THEN ... ELSE ...` expressions, so many rows shift in a single statement with no invariant-violating intermediate state. Used by `makeGap`, `closeGap`, `moveNode`, and the chunked rebuild in `fixTree`.

### Gap close

The mirror of "open a gap" — used after a hard delete. `closeGap($at, $size)` slides every `lft`/`rgt > $at` down by `$size`, reclaiming the deleted subtree's slot range so the `1..2N` permutation stays contiguous.

### Gap open

`makeGap($at, $size)` shifts every `lft`/`rgt >= $at` up by `$size`, reserving an empty `$size`-wide slot range at `$at`. The first step of every insert and every "move into a new parent".

### Mutation

Any structural change to the tree — `appendToNode`, `prependToNode`, `insertBefore`, `insertAfter`, `moveTo`, `makeRoot`, `delete`, `restore`, `forceDelete`. Each goes through the package's [mutation engine](../internals/mutation-engine.html), so the structural shift, the row INSERT/UPDATE, and the aggregate hooks share one transaction.

### Pending operation

A `PendingOperation` value object set by `appendToNode($parent)` (and siblings) on the model. The actual SQL doesn't run until `save()` — the trait's `saving` listener dispatches the pending action.

## Aggregates

### Ad-hoc fresh aggregate

A correlated-subquery aggregate computed on read, with no stored column and no maintenance. Declared inline via `withFreshAggregates(['alias' => Aggregate::sum(...)])`. Useful for one-off reports and drift audits. See [Reading Values](../aggregates/reading.html).

### Aggregate column

A column on the model whose value is a rolled-up function (`SUM`/`COUNT`/`AVG`/`MIN`/`MAX`/...) of every descendant row's source. Declared with `#[NestedSetAggregate]` and maintained automatically on every mutation. See [Aggregates Overview](../aggregates/overview.html).

### Chain recompute

A maintenance strategy that re-derives an aggregate by re-scanning the subtree under each affected ancestor — used when no signed delta exists (MIN/MAX after a deleted extremum, BitOr/BitAnd after a delete, collection aggregates). O(depth × subtree-size) per mutation. The opposite of [delta maintenance](#delta-maintenance).

### Companion column

A hidden delta-maintainable column that backs a derived aggregate. `AVG` is stored as a `__sum` + `__count` pair plus the display column rewritten as `sum / count`. Allocated automatically by the `nestedSetAggregate(type: ...)` migration macro. See [Aggregate Maintenance → Companion columns](../internals/aggregate-maintenance.html#companion-columns).

### Delta maintenance

The cheap path: each ancestor's stored value is updated by a signed delta in one statement (`col = col ± Δ`). Available for SUM, COUNT, and BitXor (the only delta-maintainable bitwise function — XOR is self-inverse). Constant work per ancestor, regardless of subtree size.

### Drift

The state where a stored aggregate column disagrees with the value freshly computed from the source. Caused by raw `UPDATE`s that bypass the trait, `Model::unguard()` writes, or time-dependent filters (a date window that slides every day). Detected by `aggregateErrors()`; repaired by [`fixAggregates()`](../maintenance/fix-aggregates.html). See [Drift & Limitations](../aggregates/drift.html).

### Exclusive aggregate

An aggregate that excludes self from its own roll-up — declared with `exclusive: true`. A leaf reports `0`; an interior node reports "every descendant, not me". Routes through chain recompute (no delta path exists), so it costs more than the default inclusive form.

### Fresh aggregate

A value computed by re-scanning the source at read time rather than reading the stored column. The single-row form is `$node->freshAggregate('col')`; the collection-level overlay is `withFreshAggregates()`. Useful for drift detection. Treat the result as read-only — saving a model hydrated via `withFreshAggregates()` will silently persist the fresh value over the stored one.

### Listener aggregate

An aggregate whose per-row contribution is a PHP closure rather than a source column. Declared via `#[NestedSetAggregateListener]` with a class implementing `TreeAggregateListener::contribution(Model)`. SUM/COUNT/MIN/MAX/AVG are supported. See [Listener Aggregates](../aggregates/listeners.html).

### Recompute maintenance

The "SELECT-then-UPDATE" path used by MIN/MAX, raw filters, and collection aggregates — recompute each affected ancestor's value via an inner subtree subquery, then write it back. See [Aggregate Maintenance → Recompute maintenance](../internals/aggregate-maintenance.html#recompute-maintenance).

### Source column

The plain column an SQL aggregate is rolled up over: `sum: 'articles'` rolls up the `articles` source column. The package watches the source column for change-deltas; updating it triggers ancestor maintenance.

### SQL aggregate

The default kind — an aggregate computed by SQL over a real source column, declared via `#[NestedSetAggregate]`. Distinct from [listener](#listener-aggregate) and [ad-hoc fresh](#ad-hoc-fresh-aggregate) aggregates.

## Repair

### Aggregate errors

The per-column drift counts returned by `aggregateErrors()`. Non-zero means the stored value disagrees with the freshly computed one. Repaired by `fixAggregates()`.

### `countErrors()`

The structural diagnostic — runs four scoped queries (`invalid_bounds`, `duplicate_lft`, `duplicate_rgt`, `orphans`) and returns the counts. Cycles are not detected by `countErrors()`; see the [Diagnostic SQL](../maintenance/corruption.html#diagnostic-sql) in the corruption reference.

### `fixAggregates`

Recompute every stored aggregate from the source and write back the corrections. Idempotent. See [Repairing Aggregates](../maintenance/fix-aggregates.html).

### `fixTree`

Rebuild `lft`/`rgt`/`depth` from a `parent_id` walk, then run `fixAggregates()` against the repaired structure. The recovery anchor for every structural corruption category except cycles. See [Tree Repair](../maintenance/fix-tree.html).

### Tree corruption

A state where one or more invariants are broken — typically by a write that bypassed the package. The [corruption reference](../maintenance/corruption.html) enumerates the categories and which are auto-recoverable.

## Concurrency

### Aggregate locking

The `nestedset.aggregate_locking` config flag (`'auto'` / `'always'` / `'never'`) controlling whether the recompute path takes a `FOR UPDATE` lock on the ancestor chain before SELECTing. Default `'auto'` is right for nearly every app; raise to `'always'` if you've observed drift under non-default isolation. See [Concurrency → Aggregate locking](../internals/concurrency.html#aggregate-locking).

### Auto-transaction

The wrap that every mutation runs inside by default (`config('nestedset.auto_transaction')`). Makes a single `save()` — gap shift, INSERT/UPDATE, aggregate hooks — all-or-nothing. Laravel handles nesting via savepoints, so wrapping in your own outer transaction is safe.

### `FOR UPDATE`

A row-level lock taken by `SELECT ... FOR UPDATE`. Used by the mutation engine on parent/sibling reads to serialise concurrent appenders. PostgreSQL rejects `FOR UPDATE` on aggregate queries, which is why `makeRoot()` locks the highest-`rgt` row instead of `max(rgt)`.

### Snapshot semantics

The soft-delete model: a trashed subtree freezes its rolled-up aggregates at trash-time, and every subsequent maintenance `UPDATE` adds `WHERE deleted_at IS NULL` so trashed ancestors stay frozen. Restore recomputes from live descendants rather than blindly adding back the trash-time total.

## Package types

### `HasNestedSet`

The contract that every tree model must implement. `NodeTrait` provides default implementations of every method, so satisfying the interface costs nothing — its job is to give static analysis (Larastan / IDE) a typed surface to resolve `getLft()`, `getBounds()`, and the column accessors against.

### `NestedSetAggregate`

The PHP attribute that declares an aggregate column on a model: `#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]`. Repeatable. The fluent `Aggregate::sum(...)->into(...)` factory is the runtime/conditional alternative used from a `nestedSetAggregates()` method override.

### `NestedSetScope`

The PHP attribute that declares a model's scope (partition) column(s): `#[NestedSetScope('menu_id')]` or `#[NestedSetScope(['tenant_id', 'menu_id'])]`. See [Scoped Trees](../querying/scoped-trees.html).

### `NodeBounds`

The readonly value object wrapping `(lft, rgt, depth)`. Exposes `height()`, `contains()` (the descendant predicate), and `depthDelta()`. Passed by the mutation engine, aggregate hooks, and exporters — never re-read mid-operation.

### `NodeTrait`

The user-facing trait every tree model uses. A composition of nine concerns (`HasTreeMutation`, `HasTreeRelations`, `HasNodeInspection`, `HasTreeRepair`, `HasNestedSetAggregates`, `HasSoftDeleteTree`, `HasTreeWalk`, `HasBulkInsert`, `HasTreeExport`) plus the Eloquent overrides that wire `TreeQueryBuilder` and `TreeBaseQueryBuilder` in. See [Architecture](../internals/architecture.html).

### `PendingOperation`

The three-field value object recording a queued mutation: action name (`'appendTo'`, `'prependTo'`, `'sibling'`, `'root'`), the target neighbour, and a `Position` enum (`Before` / `After`) for sibling inserts. Set by `appendToNode()` and friends; dispatched on `save()`.

### `TreeAggregateListener`

The interface a listener-aggregate class implements. Two methods: `contribution(Model $node): int|float|null` (the per-row value, `null` to exclude) and `watchColumns(): array` (which columns dirty the contribution).

### `TreeExpression`

A thin `Expression` wrapper that lets the package's composed-but-trusted SQL strings bypass Laravel's `@template TValue of literal-string` constraint. Used everywhere a `CASE WHEN` or column-to-column predicate is emitted (mutation engine, leaf scope, aggregate SQL).

### `TreeQueryBuilder`

The Eloquent builder subclass returned from every `NodeTrait` model's `query()`. Adds `whereDescendantOf`, `whereAncestorOf`, `whereIsRoot`, `withDepth`, `defaultOrder`, `withFreshAggregates`, and the positional/ordering helpers. See [Query Engine](../internals/query-engine.html).

### `WalkContext`

The readonly value object passed as the visitor's second argument. Carries depth (relative to the walk root), parent, sibling index/count, the derived `isFirstSibling`/`isLastSibling` flags, and a lazy `pathToRoot()`. See [Walking Subtrees → The visitor signature](../querying/walking.html#the-visitor-signature).

### `WalkFilter`

A static pruning rule combining `maxDepth` (`?int`), `visitable` (`?Closure`), and `includeRoot` (`bool`). Plugs into every walker method and every exporter option object, so the same predicate prunes ASCII output, Mermaid diagrams, and JSON exports identically. See [Pruning with `WalkFilter`](../querying/walking.html#pruning-with-walkfilter).

### `WalkSignal`

The two-case enum (`SkipSubtree`, `Stop`) a visitor returns to steer the walk. Skip is honoured by pre-order DFS and BFS; ignored by post-order. Returning `null` (or nothing) continues normally.
