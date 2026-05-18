# Installation

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13

## Install via Composer

```bash
composer require vusys/laravel-nestedset
```

## Add the columns to your migration

A nested-set table needs `lft`, `rgt`, `parent_id`, and `depth`. The
package registers a `nestedSet()` macro on Laravel's `Blueprint` that
adds all four with the right indexes:

```php
Schema::create('categories', function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->nestedSet();   // lft, rgt, parent_id (nullable), depth + index
    $table->timestamps();
});
```

For a **scoped (multi-tree)** table, declare the scope column first in
the composite index so each tree gets its own index slice:

```php
$table->index(['post_id', 'lft', 'rgt', 'parent_id']);
```

## Wire up your model

Implement the `HasNestedSet` contract and use the `NodeTrait`. The
trait provides defaults for every contract method:

```php
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

class Category extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $fillable = ['name'];
}
```

That's it — your model is now a tree node. Head to
[Your First Tree](getting-started.html) for a quick tour.
