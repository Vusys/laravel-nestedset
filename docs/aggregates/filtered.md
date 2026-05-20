# Filtered Aggregates

Add a filter to any `#[NestedSetAggregate]` declaration so only nodes
that match a condition contribute to the rollup:

```php
#[NestedSetAggregate(column: 'published_articles', sum:   'articles', filter: ['visibility' => 'public'])]
#[NestedSetAggregate(column: 'public_count',       count: true,        filter: ['visibility' => 'public'])]
#[NestedSetAggregate(column: 'public_max',         max:   'articles', filter: ['visibility' => 'public'])]
#[NestedSetAggregate(column: 'has_articles',       count: true,        filterNotNull: 'articles')]
class Category extends Model implements HasNestedSet { use NodeTrait; }
```

## Filter forms

Three filter forms:

| Form | Attribute param | Meaning |
|------|----------------|---------|
| Equality | `filter: ['col' => value, ...]` | All listed columns must match |
| Not-null | `filterNotNull: 'col'` | `col IS NOT NULL` |
| Raw SQL | `filterRaw: 'active = 1'`, `filterRawWatches: ['active']` | Arbitrary SQL predicate |
| Raw SQL, no columns | `filterRaw: '1 = 1'`, `filterRawNoColumnDependencies: true` | Raw predicate that references no columns at all |

`filterRawWatches` must list **every** column the raw SQL references —
delta maintenance uses the list to decide whether a write could have
flipped a row in or out of the filter. Omit a referenced column and the
stored aggregate silently drifts; the registry validates this at boot
time, so a missing entry surfaces as a startup
`AggregateConfigurationException` rather than as runtime corruption.

If the predicate genuinely references no columns at all
(`'1 = 1'`, `'NOW() > "2000-01-01"'`, feature-flag-driven constants),
set `filterRawNoColumnDependencies: true` to opt out of the watches
requirement explicitly. The empty-watches case is the footgun the
guard exists to remove — silent defaults aren't an option here.

The fluent builder equivalents:

```php
Aggregate::sum('articles')->filter(['visibility' => 'public'])->into('published_articles')
Aggregate::count()->filterNotNull('articles')->into('has_articles')
Aggregate::max('articles')->filterRaw('active = 1', watches: ['active'])->into('active_max')
// Or with DB::raw — reads as obviously-SQL at the call site:
Aggregate::max('articles')->filterRaw(DB::raw('active = 1'), watches: ['active'])->into('active_max')
```

`filterRaw()` accepts either a string or a Laravel
`Illuminate\Contracts\Database\Query\Expression`. The Expression form
(`DB::raw(...)`) is the conventional Laravel signal for *this is raw
SQL, I take responsibility for the contents* — useful for code review.
Both forms produce identical SQL.

Write raw predicates with **bare column names** — the package emits
them inside a correlated subquery whose only `FROM` is the model's
table, so SQL's local-resolution rule binds bare references to the row
being evaluated regardless of what the calling context has in scope.

Filtered columns use the same `$table->nestedSetAggregate(...)`
migration macro as unfiltered ones — the migration doesn't know about
filter logic.

> ## ⚠️ Security note: filter values are inlined into SQL
>
> The package inlines filter values directly into generated SQL —
> equality values are single-quote-escaped (SQL standard); raw SQL
> fragments are concatenated verbatim with no escaping or parameter
> binding. This is fine for **trusted, code-level constants** (class
> attribute values, config files you control, hard-coded fragments
> in your own code).
>
> **Never pass user-supplied input** to any filter form. A
> `filterRaw('user_field = '.$request->input('foo'))` would render
> the input as a SQL fragment; a `filter(['col' => $request->...])`
> equality value escapes single quotes but does not protect against
> backslash interpretation on MySQL's default `sql_mode`.
>
> In the attribute form `#[NestedSetAggregate(..., filter: [...])]`,
> PHP requires attribute values to be compile-time constants — so
> the concern only applies to the fluent builder
> (`Aggregate::sum(...)->filter(...)`) and method-override
> (`nestedSetAggregates()`) forms.

## Maintenance cost

All three filter forms are kept in sync incrementally — no scheduled
repair pass needed.

- **Equality** and **not-null** predicates are evaluated in PHP, so the
  package produces a signed delta per mutation and adds one extra
  `UPDATE` to the ancestor chain. Same cost shape as unfiltered
  SUM/COUNT.
- **Raw SQL** predicates can't be evaluated in PHP, so delta arithmetic
  is unavailable. When any watched column changes (or the row is
  created / deleted / moved / restored), the package bulk-recomputes
  the affected raw-filter column over the affected ancestor chain via
  one SELECT plus one UPDATE per ancestor row. Cost: O(depth ×
  subtree-size) per mutation that dirties a watched column, matching
  the MIN/MAX extremum-lost path. Mutations that don't touch a watched
  column skip the recompute entirely.

The fresh-read path (`withFreshAggregates()`, `freshAggregate()`)
always generates correct SQL — `CASE WHEN pred THEN source ELSE … END`
— regardless of filter kind.

## Index tuning

Include every raw-filter *watched column* in the `nestedSet(cover: [...])`
index alongside the source column. The inline
`SUM(CASE WHEN <raw> THEN i.source ELSE 0 END)` shape rides the same
covering range scan as unfiltered aggregates only when the columns the
CASE WHEN reads are all in the cover; otherwise MySQL falls back to a
non-covering scan that fetches each candidate row through the
clustered index (~40× slower at N=10K).

```php
$table->nestedSet(cover: ['articles', 'visibility', 'status']);
$table->nestedSetAggregate('public_articles');  // filtered on visibility
```

For trees over ~5K rows with raw-filter aggregates declared, prefer
`fixAggregates(chunkSize: 1000)` or `queueFixAggregates()` over the
unchunked call — the full-table SELECT still scales linearly with N
but the chunked path bounds each statement so long-running operations
don't lock other writers behind them.
