# Declaring Aggregates

The class-attribute form shown in the [Overview](overview.html) is the
canonical way to declare an aggregate. Two alternatives cover special
cases.

## Method-override form

For runtime-conditional aggregates (or large declaration sets that
would clutter the class header), override `nestedSetAggregates()`:

```php
class Category extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @return list<\Vusys\NestedSet\Aggregates\AggregateDefinition> */
    protected function nestedSetAggregates(): array
    {
        return [
            Aggregate::sum('articles')->into('articles_total'),
            Aggregate::count()->into('articles_count'),
            Aggregate::avg('articles')->into('articles_avg'),
        ];
    }
}
```

Attribute and method-override forms can coexist; attribute declarations
come first, method override appends. Same precedence rule as scope
resolution.

## Introspection

For tooling that needs to enumerate what a model declares at runtime —
Filament resources, admin generators, export scripts — use
`$model->getAggregateDefinitions()`, which returns the user-facing
`AggregateDefinition` list (internal AVG companions are filtered out).
