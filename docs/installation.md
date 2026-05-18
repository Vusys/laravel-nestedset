# Installation

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

## Install via Composer

```bash
composer require vusys/laravel-nestedset
```

## Add the columns to your migration

A nested-set table needs four extra columns: `_lft`, `_rgt`, `parent_id`,
and (optionally) `depth`. The package ships a migration helper:

```php
use Kalnoy\Nestedset\NestedSet;

Schema::create('categories', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    NestedSet::columns($table);
    $table->timestamps();
});
```

`NestedSet::columns()` adds the four columns and the indexes you want for
fast range scans.

## Add the trait to your model

```php
use Illuminate\Database\Eloquent\Model;
use Kalnoy\Nestedset\NodeTrait;

class Category extends Model
{
    use NodeTrait;

    protected $fillable = ['name'];
}
```

That's it — your model is now a tree node. Head to
[Your First Tree](getting-started.html) for a quick tour.
