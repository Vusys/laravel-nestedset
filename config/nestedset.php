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

];
