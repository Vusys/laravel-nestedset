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

    // 'auto'   — lock the ancestor chain only on the MIN/MAX recompute path
    // 'always' — lock on every aggregate maintenance UPDATE
    // 'never'  — issue no explicit locks
    'aggregate_locking' => 'auto',
];
```

Column names are read globally — change them once in config and every
model using `NodeTrait` picks up the new names via the `getLftName()` /
`getRgtName()` / `getParentIdName()` / `getDepthName()` accessors.

To use different column names per model, override those accessors on
the model:

```php
class Category extends Model implements HasNestedSet
{
    use NodeTrait;

    public function getLftName(): string  { return 'tree_lft'; }
    public function getRgtName(): string  { return 'tree_rgt'; }
}
```
