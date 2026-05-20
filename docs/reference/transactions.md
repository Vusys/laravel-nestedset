# Transactions

Mutations are wrapped in a database transaction by default — if the `makeGap` succeeds but the row write fails (or vice versa), the gap is rolled back instead of being left in the tree:

```php
// config/nestedset.php
return [
    'auto_transaction' => true,  // wrap each saving event in DB::transaction
];
```

Opt out only if you're already inside an outer transaction and want exact control over its boundary:

```php
DB::transaction(function () use ($parent): void {
    // multiple linked mutations atomically
    $a->appendToNode($parent)->save();
    $b->appendToNode($parent)->save();
});
```

Auto-wrapping is safe to combine with outer transactions — Laravel handles nested transactions via savepoints.
