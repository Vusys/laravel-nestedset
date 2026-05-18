# Soft Deletes

When the model uses `SoftDeletes`, the trait cascades on delete and restore:

```php
class Category extends Model implements HasNestedSet
{
    use NodeTrait, SoftDeletes;
}

$category->delete();    // soft-deletes the whole subtree (same deleted_at stamp)
$category->restore();   // restores only descendants stamped with that same
                        // deleted_at — independent soft-deletes coexist
```

A descendant that was independently trashed before the parent gets a
different `deleted_at` and is left alone when the parent restores; restore
it separately to bring it back.
