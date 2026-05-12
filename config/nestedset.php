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
    |
    |   'always' — locks the ancestor chain before every aggregate
    |              maintenance UPDATE, including deltas. Choose this if
    |              you run with non-default isolation levels (e.g.
    |              REPEATABLE READ on PostgreSQL) or have seen drift
    |              under concurrent load.
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

];
