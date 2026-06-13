<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Column Names
    |--------------------------------------------------------------------------
    | The four columns added to your table by $table->nestedSet(). If you
    | change these defaults, the same names must be configured on every
    | model that uses NodeTrait (via overrides or per-model config).
    */

    'columns' => [
        'lft' => 'lft',
        'rgt' => 'rgt',
        'parent_id' => 'parent_id',
        'depth' => 'depth',
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic Transactions
    |--------------------------------------------------------------------------
    | When enabled, all tree mutation operations (insert, move, delete) are
    | wrapped in a database transaction automatically. Set to false only if
    | you are managing transactions yourself at the call site.
    */

    'auto_transaction' => true,

    /*
    |--------------------------------------------------------------------------
    | Aggregate locking
    |--------------------------------------------------------------------------
    | Controls whether the aggregate maintenance path issues
    | `SELECT ... FOR UPDATE` on the ancestor chain before recomputing
    | MIN/MAX columns.
    |
    |   'auto'   (default) — locks the ancestor chain only on the
    |                        recompute path (MIN, MAX, fixAggregates).
    |                        Delta-only updates (SUM, COUNT, AVG) rely on
    |                        the engine's single-statement row locks,
    |                        which are sufficient under default isolation
    |                        on all supported backends. This is the right
    |                        setting for nearly every application.
    |                        Caveat (PostgreSQL READ COMMITTED): the
    |                        recompute computes the new value and takes the
    |                        FOR UPDATE lock in one statement, so the locked
    |                        outer rows are re-fetched (EvalPlanQual) but the
    |                        correlated descendant subqueries still read the
    |                        statement snapshot. A descendant change that
    |                        commits in that window can leave a recomputed
    |                        MIN/MAX/exclusive value momentarily stale until
    |                        the next maintenance pass over that subtree;
    |                        `fixAggregates($anchor)` reconciles it. Other
    |                        backends (and PG under REPEATABLE READ /
    |                        SERIALIZABLE) don't exhibit this.
    |
    |   'always' — forward-compatible alias; today behaves identically
    |              to 'auto' (the recompute path locks, the pure-delta
    |              path does not). Separate per-delta locking is not yet
    |              implemented.
    |
    |   'never'  — issues no explicit locks. Marginally faster on the
    |              recompute path; relies entirely on the engine's
    |              UPDATE-time row locks. Can produce drift on
    |              PostgreSQL READ COMMITTED with concurrent recomputes
    |              against overlapping subtrees.
    */

    'aggregate_locking' => 'auto',

    /*
    |--------------------------------------------------------------------------
    | Queue defaults for asynchronous aggregate repair
    |--------------------------------------------------------------------------
    | Routing used by Model::queueFixAggregates() when the caller doesn't
    | pass an explicit override. Null on either key falls back to Laravel's
    | default queue connection / queue name. Override per-environment via
    | .env (NESTEDSET_QUEUE_CONNECTION / NESTEDSET_QUEUE) without touching
    | code.
    */

    'queue' => [
        'connection' => env('NESTEDSET_QUEUE_CONNECTION'),
        'queue' => env('NESTEDSET_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry events
    |--------------------------------------------------------------------------
    | When true (default), the package fires typed events on Laravel's
    | event bus around its meaningful operations — fixTree, fixAggregates
    | (incl. per-chunk progress), bulkInsertTree, structural moves of
    | existing nodes, the boundary marker for withDeferredAggregateMaintenance,
    | and aggregate-maintenance failures. Set to false to short-circuit
    | every firing site — useful only if you're in a hot path and don't
    | want the overhead of constructing event objects you'll never observe.
    |
    | Event classes live in `Vusys\NestedSet\Events\`. See docs/reference/events.md
    | for the full catalogue.
    */

    'events_enabled' => true,

    /*
    |--------------------------------------------------------------------------
    | Materialised path defaults
    |--------------------------------------------------------------------------
    | Default formatting for `#[NestedSetMaterialisedPath]` columns.
    | Resolution order, most specific wins:
    |   1. Per-path explicit value (attribute arg or fluent setter)
    |   2. `#[NestedSetMaterialisedPathDefaults]` on the model class
    |   3. `class_defaults` block below (exact FQCN match)
    |   4. `defaults` block below (global fallback)
    |   5. Package hard-coded fallback (matches the `defaults` shipped here)
    */

    'materialised_path' => [
        'defaults' => [
            'separator' => '/',
            'wrap' => true,
            'maxLength' => 1024,
            'rejectSeparatorInSegment' => true,
            'uniquePerParent' => true,
        ],

        /*
        | Exact FQCN keys; no `is_a` walk. List each subclass explicitly
        | if you want different defaults per concrete model. Strong use
        | case: overriding a vendor model whose class you can't decorate.
        */
        'class_defaults' => [],
    ],

];
