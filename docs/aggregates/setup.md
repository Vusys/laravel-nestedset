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
