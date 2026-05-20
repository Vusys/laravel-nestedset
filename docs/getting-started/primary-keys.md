# Primary keys

`int` and `string` primary keys are both supported. Auto-incrementing bigint (the Laravel default), UUIDv7 / ULID / time-ordered string keys, and any other monotonically-ordered identifier work end to end. The package's tree mutation, repair, aggregate maintenance, and queued fix-aggregates job all flow the model's PK type through without narrowing to int.

## Choosing the column type

Choose the `parent_id` column type at migration time via the `parentIdType` argument to the `nestedSet()` macro:

```php
Schema::create('categories', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('name');
    $table->nestedSet(parentIdType: 'uuid');     // matches the PK type
});
```

Accepted values: `'bigint'` (default), `'uuid'`, `'ulid'`, `'string'`, or a closure `function (Blueprint $table, string $column): void { … }` for custom shapes (nanoid, fixed-width char, FK constraints, etc.). Composite primary keys are not supported.

## Monotonicity matters for chunked repair

Chunked aggregate repair (`fixAggregates(chunkSize: …)` and the queued `FixAggregatesJob`) walks rows with `WHERE id > ? ORDER BY id LIMIT N`, so it relies on the PK being **lexicographically monotonic**. UUIDv7, ULID, bigint auto-increment, and ascending strings all qualify. UUIDv4, nanoid (random by default), or clock-rollback UUIDv1 will silently skip rows under the chunked path — use the unchunked `fixAggregates($anchor)` call on those models instead.
