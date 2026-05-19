# Migration & Setup

## Migration helper

```php
Schema::create('categories', function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->unsignedInteger('articles')->default(0);
    $table->nestedSet();

    // SUM / COUNT — non-null, default 0
    $table->nestedSetAggregate('articles_total');
    $table->nestedSetAggregate('articles_count_all');

    // AVG — nullable decimal; null on empty subtree
    $table->nestedSetAggregate('articles_avg', type: 'avg');

    // MIN / MAX — nullable; empty subtree yields NULL
    $table->nestedSetAggregate('articles_min', type: 'min_max');
    $table->nestedSetAggregate('articles_max', type: 'min_max');
});
```

## Required model conventions

Aggregate columns are derived state. Two rules:

```php
class Category extends Model implements HasNestedSet
{
    use NodeTrait;

    // Aggregate columns must NOT appear in $fillable — the package
    // enforces this and throws AggregateConfigurationException at boot
    // if any aggregate column is found there. They stay readable,
    // castable, hideable; only mass-assignment is rejected.
    protected $fillable = ['name', 'articles'];
    // (Equivalently: list every aggregate column in $guarded if you
    // prefer the deny-list style. The package checks $fillable first
    // and falls back to $guarded.)

    // Declare casts manually — NodeTrait does not register them for you.
    protected $casts = [
        'articles'           => 'integer',
        'articles_total'     => 'integer',
        'articles_count_all' => 'integer',
        'articles_avg'       => 'decimal:4',
        'articles_min'       => 'integer',
        'articles_max'       => 'integer',
    ];
}
```

### Scope of the mass-assignment guard

The check fires once at registry build (when the model's aggregate
definitions are first resolved) via reflection on the `$fillable` /
`$guarded` properties. That covers the common configurations.

It does **not** intercept dynamic bypasses applied at runtime:

- Overriding `isFillable()` or `isGuarded()` on the model to return
  values not derivable from the properties.
- Calling `Model::unguard()` (e.g. in a service provider, seeder, or
  test setup) — this is a global Eloquent toggle that disables
  guarding for every model, so an `Article::create([...])` after
  `unguard()` can write directly to aggregate columns regardless of
  what the build-time check saw.

In both cases the next mutation through the package overwrites the
clobbered value with the correctly recomputed aggregate, so the only
observable effect is that the value you mass-assigned is
silently lost. If you rely on `Model::unguard()` in test code,
prefer scoping it with `Model::unguarded(fn () => ...)` and avoid
writing to aggregate columns inside the closure.
