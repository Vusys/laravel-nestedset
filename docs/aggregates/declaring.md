# Declaring Aggregates

The class-attribute form shown in the [Overview](overview.html) is the canonical way to declare an aggregate. Two alternatives cover special cases.

## Method-override form

For runtime-conditional aggregates (or large declaration sets that would clutter the class header), override `nestedSetAggregates()`:

```php
class Category extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @return list<\Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition> */
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

## Bitwise rollups

For integer source columns packing independent yes/no bits — feature flags, capability masks — declare via `bitOr` / `bitAnd` / `bitXor` named args or the `Aggregate::bitOr/bitAnd/bitXor()` factories. See the dedicated [Bitwise Aggregates](bitwise.html) page for the full rundown of which mutation paths use delta vs chain recompute.

## Beyond the SQL-standard five

`Aggregate::distinctCount`, `Aggregate::stringAgg`, `Aggregate::jsonAgg` and `Aggregate::jsonObjectAgg` build collection-shaped rollups (distinct counts, concatenated text, JSON arrays/objects). They use the same `#[NestedSetAggregate]` attribute and method-override form as the SQL-standard functions, but always go through full subtree recompute (no delta fast path). See [Collection Aggregates](text-and-json.html) for the full surface, backend caveats, and recipe examples.

`Aggregate::weightedAvg(value, weight)`, `Aggregate::boolOr(source)`, and `Aggregate::boolAnd(source)` are delta-maintainable but derived from companion sums — `weightedAvg` rides on `Σ(weight · value)` and `Σ(weight)`; the boolean rollups share a single `Sum(source AS INT)` + `Count` pair. See [Weighted Average & Boolean Rollups](weighted-avg-and-booleans.html) for the API, migration shape, and the per-backend storage caveats.

`Aggregate::geometricMean(source)` and `Aggregate::harmonicMean(source)` are companion-derived too — `geometricMean` rides on `Σ LN(source)` + `Count`; `harmonicMean` on `Σ 1/source` + `Count`. Both have a domain restriction (geometric needs strictly positive values; harmonic needs non-zero) and throw `AggregateSourceConstraintViolationException` by default. See [Geometric & Harmonic Mean](means.html) for the constraint behaviour, the `allowNonPositive()` opt-out, and the wider-precision companion storage shape.

`Aggregate::median(source)` and `Aggregate::percentile(source, $p)` (plus the `percentiles([...])` / `quartiles()` bundlers) are **fresh-read-only** — they cannot be stored as columns and only work through `withFreshAggregates([...])`. See [Quantiles](quantiles.html).

The existing `min` / `max` factories also work on text columns and produce lexicographic min/max — useful for "first alphabetical descendant tag" and similar queries.

## Introspection

For tooling that needs to enumerate what a model declares at runtime — Filament resources, admin generators, export scripts — use `$model->getAggregateDefinitions()`, which returns the user-facing `AggregateDefinition` list (internal AVG companions are filtered out).
