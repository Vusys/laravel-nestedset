# Declaring Aggregates

The class-attribute form shown in the [Overview](overview.html) is the canonical way to declare an aggregate. Two alternatives cover special cases.

## Method-override form

For runtime-conditional aggregates (or large declaration sets that would clutter the class header), override `nestedSetAggregates()`:

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

Attribute and method-override forms can coexist; attribute declarations come first, method override appends. Same precedence rule as scope resolution.

## How AVG is computed

`Aggregate::avg('articles')` (and `avg:` on the attribute) is maintained as a derived value, not as a real `AVG(...)` UPDATE. The registry auto-promotes companion `SUM` and `COUNT` definitions over the same source column unless you've already declared them explicitly with matching filters. The AVG display column is then written as `sum / NULLIF(count, 0)` after every delta — so the ancestor `UPDATE` stays one statement and the column returns `NULL` for empty subtrees rather than dividing by zero.

The auto-promoted companions follow the `{avg_column}__sum` / `{avg_column}__count` naming convention; both must exist in the migration alongside the AVG column. `$model->getAggregateDefinitions()` filters internal companions out of its return list. If you already declared a `SUM(source)` or `COUNT(source)` aggregate **with the same filter** as the AVG, the registry reuses your user-facing column instead of promoting a hidden one. See [Listener AVG](listeners.html#listener-avg) for the listener-side equivalent.

## Beyond the SQL-standard five

`Aggregate::distinctCount`, `Aggregate::stringAgg`, `Aggregate::jsonAgg` and `Aggregate::jsonObjectAgg` build collection-shaped rollups (distinct counts, concatenated text, JSON arrays/objects). They use the same `#[NestedSetAggregate]` attribute and method-override form as the SQL-standard functions, but always go through full subtree recompute (no delta fast path). See [Collection Aggregates](text-and-json.html) for the full surface, backend caveats, and recipe examples.

The existing `min` / `max` factories also work on text columns and produce lexicographic min/max — useful for "first alphabetical descendant tag" and similar queries.

## Introspection

For tooling that needs to enumerate what a model declares at runtime — Filament resources, admin generators, export scripts — use `$model->getAggregateDefinitions()`, which returns the user-facing `AggregateDefinition` list (internal AVG companions are filtered out).
