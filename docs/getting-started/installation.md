# Installation

```bash
composer require vusys/laravel-nestedset
```

The service provider auto-registers Blueprint macros and registers a
publishable config file.

```bash
php artisan vendor:publish \
    --provider="Vusys\NestedSet\NestedSetServiceProvider" \
    --tag=nestedset-config
```

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13

Next: [Migration](migration.html) to add the columns, then
[Model Setup](model-setup.html) to wire up your Eloquent class.
