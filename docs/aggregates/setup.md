# Migration & Setup

## Migration helper

```php
Schema::create('areas', function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->unsignedInteger('tickets')->default(0);
    $table->nestedSet();

    // SUM / COUNT — non-null, default 0
    $table->nestedSetAggregate('tickets_total');
    $table->nestedSetAggregate('tickets_count_all');

    // AVG — nullable decimal; null on empty subtree
    $table->nestedSetAggregate('tickets_avg', type: 'avg');

    // MIN / MAX — nullable; empty subtree yields NULL
    $table->nestedSetAggregate('tickets_min', type: 'min_max');
    $table->nestedSetAggregate('tickets_max', type: 'min_max');
});
```

## Required model conventions

Aggregate columns are derived state. Two rules:

```php
class Area extends Model implements HasNestedSet
{
    use NodeTrait;

    // Aggregate columns NEVER belong in $fillable. Mass-assigning them
    // is silently overwritten on the next mutation and produces drift
    // in the interim.
    protected $fillable = ['name', 'tickets'];

    // Declare casts manually — NodeTrait does not register them for you.
    protected $casts = [
        'tickets'           => 'integer',
        'tickets_total'     => 'integer',
        'tickets_count_all' => 'integer',
        'tickets_avg'       => 'decimal:4',
        'tickets_min'       => 'integer',
        'tickets_max'       => 'integer',
    ];
}
```
