# Configuration

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

    'aggregate_locking' => 'auto',   // 'auto' | 'always' | 'never'

    'queue' => [
        'connection' => env('NESTEDSET_QUEUE_CONNECTION'),
        'queue'      => env('NESTEDSET_QUEUE'),
    ],

    'events_enabled' => true,
];
```

## `columns`

Column names are read globally — change them once in config and every model using `NodeTrait` picks up the new names via the `getLftName()` / `getRgtName()` / `getParentIdName()` / `getDepthName()` accessors.

To use different column names per model, override those accessors on the model:

```php
class Category extends Model implements HasNestedSet
{
    use NodeTrait;

    public function getLftName(): string  { return 'tree_lft'; }
    public function getRgtName(): string  { return 'tree_rgt'; }
}
```

## `auto_transaction`

When `true` (default), every tree mutation (`save()` after a `appendToNode` / `prependToNode` / `insertBeforeNode` / `insertAfterNode` / `makeRoot` / `up` / `down`) is wrapped in a `DB::transaction()` so the gap-shift UPDATE, the row INSERT/UPDATE, and any aggregate maintenance hooks all commit together. Set to `false` if you wrap calls in your own transaction at the call site.

## `aggregate_locking`

Controls whether the aggregate maintenance path issues `SELECT ... FOR UPDATE` on the ancestor chain before recomputing MIN/MAX (or raw-filter) columns. The right setting for almost every application is `'auto'`.

- **`'auto'`** (default) — lock the ancestor chain only on the recompute path (MIN, MAX, raw-filter, `fixAggregates`). Delta-only updates (SUM, COUNT, AVG) rely on the engine's single-statement row locks, which are sufficient under default isolation on all supported backends.
- **`'always'`** — lock the ancestor chain before every aggregate maintenance UPDATE, including deltas. Choose this if you run with non-default isolation levels (e.g. PostgreSQL `REPEATABLE READ`) or have seen drift under concurrent load.
- **`'never'`** — issue no explicit locks. Marginally faster on the recompute path; can produce drift on PostgreSQL `READ COMMITTED` with concurrent recomputes against overlapping subtrees.

## `queue`

Routing used by `Model::queueFixAggregates()` when the caller doesn't pass an explicit `onConnection:` / `onQueue:` override. Either key may be `null` — that falls back to Laravel's default queue connection / queue name. The defaults pull from environment so you can override per-deployment without code changes:

```env
NESTEDSET_QUEUE_CONNECTION=redis
NESTEDSET_QUEUE=aggregates-low
```

## `events_enabled`

When `true` (default), the package fires typed events on Laravel's event bus around its meaningful operations — `fixTree`, `fixAggregates` (including per-chunk progress), `bulkInsertTree`, structural moves of existing nodes, the boundary marker for `withDeferredAggregateMaintenance`, and aggregate-maintenance failures. Listen via `Event::listen()` to wire metrics, Sentry, or audit logs — see [Production Notes → Telemetry](production.html#telemetry).

Set to `false` to short-circuit every firing site. Useful only on genuinely hot paths where you've measured the cost of constructing event objects you'll never observe.
