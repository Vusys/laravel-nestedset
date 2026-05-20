<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

use Illuminate\Contracts\Database\Query\Expression;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * Fluent factory for declaring a precalculated aggregate column on a
 * nested-set model. Used by the method-override declaration form:
 *
 *     protected function nestedSetAggregates(): array
 *     {
 *         return [
 *             Aggregate::sum('tickets')->into('tickets_total'),
 *             Aggregate::count()->into('tickets_count'),
 *             Aggregate::avg('tickets')->into('tickets_avg'),
 *             Aggregate::max('tickets')->into('tickets_max')->exclusive(),
 *         ];
 *     }
 *
 * The attribute form ({@see NestedSetAggregate})
 * is preferred for static declarations; this class is the escape hatch
 * for runtime / conditional declarations.
 *
 * Instances are immutable; modifiers like {@see exclusive()} return a
 * new instance. The terminal call {@see into()} produces an
 * {@see AggregateDefinition} that the registry consumes.
 */
final readonly class Aggregate
{
    /**
     * @param  array<string,string>  $sources  multi-column source map for JsonAgg
     *                                         (JSON key => source column).
     */
    private function __construct(
        public AggregateFunction $function,
        public ?string $source,
        public bool $inclusive,
        public ?FilterPredicate $filter = null,
        public string $separator = ', ',
        public ?int $limit = null,
        public ?string $orderBy = null,
        public bool $distinct = false,
        public bool $allowNullKeys = false,
        public ?string $keyColumn = null,
        public ?string $valueColumn = null,
        public array $sources = [],
    ) {}

    /**
     * SUM(source) over the subtree.
     */
    public static function sum(string $source): self
    {
        return new self(AggregateFunction::Sum, $source, true);
    }

    /**
     * COUNT(*) when called with no argument, COUNT(source) when a column
     * is named. The two are not equivalent on nullable source columns —
     * COUNT(source) excludes rows where the source is NULL.
     */
    public static function count(?string $source = null): self
    {
        return new self(AggregateFunction::Count, $source, true);
    }

    /**
     * AVG(source) over the subtree. Stored as a derived value: the
     * registry auto-promotes companion SUM and COUNT definitions over
     * the same source if the user has not declared them explicitly.
     */
    public static function avg(string $source): self
    {
        return new self(AggregateFunction::Avg, $source, true);
    }

    public static function min(string $source): self
    {
        return new self(AggregateFunction::Min, $source, true);
    }

    public static function max(string $source): self
    {
        return new self(AggregateFunction::Max, $source, true);
    }

    /**
     * COUNT(DISTINCT source) over the subtree — cardinality of values
     * in the named column across descendants (and self when inclusive).
     * Always recompute-only: a removed value might or might not still
     * appear elsewhere in the subtree, so no signed delta exists.
     */
    public static function distinctCount(string $source): self
    {
        if ($source === '') {
            throw new AggregateConfigurationException(
                'Aggregate::distinctCount(): source column must not be empty.',
            );
        }

        return new self(AggregateFunction::DistinctCount, $source, true);
    }

    /**
     * Concatenated text aggregate. Renders as STRING_AGG / GROUP_CONCAT
     * per backend. `$orderBy` defaults to the source column for stable
     * output bytes; pass `null` to opt out (output order becomes
     * backend-defined).
     */
    public static function stringAgg(
        string $source,
        string $separator = ', ',
        ?int $limit = null,
        ?string $orderBy = null,
    ): self {
        if ($source === '') {
            throw new AggregateConfigurationException(
                'Aggregate::stringAgg(): source column must not be empty.',
            );
        }

        if ($limit !== null && $limit < 0) {
            throw new AggregateConfigurationException(
                'Aggregate::stringAgg(): limit must be >= 0 when set.',
            );
        }

        return new self(
            AggregateFunction::StringAgg,
            $source,
            true,
            null,
            $separator,
            $limit,
            $orderBy ?? $source,
        );
    }

    /**
     * JSON array of values from the subtree. Accepts three input shapes:
     *
     *   'id'                          → scalar array: [1, 2, 3]
     *   ['id', 'name']                → array of objects keyed by column
     *                                   name: [{"id":1,"name":…}, …]
     *   ['key' => 'id', 'label' => …] → array of objects keyed by the
     *                                   array's keys: [{"key":1,"label":…}]
     *
     * The assoc form is the escape hatch for renaming columns into the
     * JSON output (snake_case → camelCase, frontend contract, …).
     *
     * @param  string|list<string>|array<string,string>  $source
     */
    public static function jsonAgg(
        string|array $source,
        ?int $limit = null,
        ?string $orderBy = null,
    ): self {
        if ($limit !== null && $limit < 0) {
            throw new AggregateConfigurationException(
                'Aggregate::jsonAgg(): limit must be >= 0 when set.',
            );
        }

        if (is_string($source)) {
            if ($source === '') {
                throw new AggregateConfigurationException(
                    'Aggregate::jsonAgg(): source column must not be empty.',
                );
            }

            return new self(
                AggregateFunction::JsonAgg,
                $source,
                true,
                null,
                ', ',
                $limit,
                $orderBy ?? $source,
            );
        }

        $sources = self::normaliseJsonAggSource($source);

        return new self(
            AggregateFunction::JsonAgg,
            null,
            true,
            null,
            ', ',
            $limit,
            $orderBy,
            sources: $sources,
        );
    }

    /**
     * JSON object built from one key column and one value column —
     * `{<key>: <value>, …}` across the subtree. PG's strict-typed
     * `JSON_OBJECT_AGG` key is bridged by an unconditional `::text` cast
     * so integer / UUID / date keys behave identically to the other
     * backends (which implicit-cast). NULL keys are filtered out by
     * default; pass `allowNullKeys: true` to keep the backend-native
     * behaviour.
     */
    public static function jsonObjectAgg(
        string $key,
        string $value,
        ?int $limit = null,
        ?string $orderBy = null,
        bool $allowNullKeys = false,
    ): self {
        if ($key === '') {
            throw new AggregateConfigurationException(
                'Aggregate::jsonObjectAgg(): key column must not be empty.',
            );
        }

        if ($value === '') {
            throw new AggregateConfigurationException(
                'Aggregate::jsonObjectAgg(): value column must not be empty.',
            );
        }

        if ($limit !== null && $limit < 0) {
            throw new AggregateConfigurationException(
                'Aggregate::jsonObjectAgg(): limit must be >= 0 when set.',
            );
        }

        return new self(
            AggregateFunction::JsonObjectAgg,
            null,
            true,
            null,
            ', ',
            $limit,
            $orderBy ?? $key,
            false,
            $allowNullKeys,
            $key,
            $value,
        );
    }

    /**
     * Distinct modifier for `stringAgg`. SQL emission switches to
     * `STRING_AGG(DISTINCT …)` / `GROUP_CONCAT(DISTINCT …)`. The
     * `orderBy` is forced back to the source column on `distinct: true`
     * (PG only accepts ORDER BY columns that appear in the DISTINCT
     * set) — passing a different `orderBy` with `distinct()` is
     * rejected at attribute-construction time. Here, calling
     * `distinct()` after a custom `orderBy` raises immediately.
     */
    public function distinct(): self
    {
        if ($this->function !== AggregateFunction::StringAgg) {
            throw new AggregateConfigurationException(
                'Aggregate::distinct(): only stringAgg supports the distinct modifier.',
            );
        }

        if ($this->orderBy !== null && $this->orderBy !== $this->source) {
            throw new AggregateConfigurationException(
                'Aggregate::stringAgg(...)->distinct(): custom orderBy is incompatible with distinct. '
                .'PG requires ORDER BY columns to appear in the DISTINCT set; for cross-backend portability '
                .'the package emits ORDER BY only when it matches the source column.',
            );
        }

        return new self(
            $this->function,
            $this->source,
            $this->inclusive,
            $this->filter,
            $this->separator,
            $this->limit,
            $this->orderBy,
            true,
            $this->allowNullKeys,
            $this->keyColumn,
            $this->valueColumn,
            $this->sources,
        );
    }

    /**
     * Self-inclusive aggregation — the node's own source value
     * participates in its stored aggregate. This is the default and the
     * mental model for "give me the rollup for this subtree".
     */
    public function inclusive(): self
    {
        return $this->withInclusive(true);
    }

    /**
     * Exclusive aggregation — the node's own source value is excluded;
     * only descendants contribute. A leaf's exclusive aggregate is
     * always the zero/null element for the function.
     */
    public function exclusive(): self
    {
        return $this->withInclusive(false);
    }

    private function withInclusive(bool $inclusive): self
    {
        return new self(
            $this->function,
            $this->source,
            $inclusive,
            $this->filter,
            $this->separator,
            $this->limit,
            $this->orderBy,
            $this->distinct,
            $this->allowNullKeys,
            $this->keyColumn,
            $this->valueColumn,
            $this->sources,
        );
    }

    /**
     * Only aggregate rows where the given column/value pairs all match.
     *
     * Security: values are inlined into the generated SQL via
     * {@see FilterPredicate::equality()} — they bypass PDO parameter
     * binding. Pass only **trusted constants** (class-level literals,
     * config values you control). **Never pass user-supplied input** —
     * a string like `"x' OR 1=1 --"` would render as a SQL fragment.
     *
     * In the attribute form `#[NestedSetAggregate(..., filter: [...])]`,
     * PHP requires attribute values to be compile-time constants, so the
     * concern only applies to the method-override form here.
     *
     * @param  array<string,mixed>  $conditions
     */
    public function filter(array $conditions): self
    {
        return $this->withFilter(FilterPredicate::equality($conditions));
    }

    /**
     * Only aggregate rows where $column IS NOT NULL.
     */
    public function filterNotNull(string $column): self
    {
        return $this->withFilter(FilterPredicate::notNull($column));
    }

    /**
     * Only aggregate rows matching the given raw SQL expression.
     *
     * Accepts either a string or a Laravel
     * {@see Expression} — the
     * latter (e.g. `DB::raw('active = 1')`) reads as obviously-raw at
     * the call site and is the conventional Laravel signal for
     * "I know this is SQL, I take responsibility".
     *
     * Security: the SQL is inlined verbatim into generated aggregate
     * queries — no escaping, no parameter binding. Pass only fragments
     * you write yourself; **never pass user-supplied input**. Use this
     * for predicates the equality / not-null forms can't express
     * (e.g. `status IN ('open','triaged')`, `active = 1`).
     *
     * `$watches` has no default — every column the SQL references must
     * be listed, or delta maintenance won't notice a contributing row
     * flipping in/out of the filter and the stored aggregate silently
     * drifts. Pass `[]` only for genuinely column-free predicates.
     *
     * @param  list<string>  $watches  columns whose changes should trigger re-aggregation.
     */
    public function filterRaw(string|Expression $sql, array $watches): self
    {
        return $this->withFilter(
            FilterPredicate::raw($this->expressionToString($sql), $watches),
        );
    }

    private function withFilter(FilterPredicate $filter): self
    {
        return new self(
            $this->function,
            $this->source,
            $this->inclusive,
            $filter,
            $this->separator,
            $this->limit,
            $this->orderBy,
            $this->distinct,
            $this->allowNullKeys,
            $this->keyColumn,
            $this->valueColumn,
            $this->sources,
        );
    }

    /**
     * Extract the underlying SQL from a string-or-Expression argument.
     * Laravel's Expression::getValue() requires a Grammar; we don't
     * have a Connection at fluent-call time, so we read the protected
     * `$value` property via reflection. Compatible with the
     * package's pinned Laravel range (11+).
     */
    private function expressionToString(string|Expression $sql): string
    {
        if (is_string($sql)) {
            return $sql;
        }

        $reflection = new \ReflectionClass($sql);
        // Walk the parent chain — subclasses may shadow the property.
        while ($reflection !== false) {
            if ($reflection->hasProperty('value')) {
                $property = $reflection->getProperty('value');
                $value = $property->getValue($sql);
                if (is_string($value) || is_int($value) || is_float($value)) {
                    return (string) $value;
                }
                break;
            }
            $reflection = $reflection->getParentClass();
        }

        throw new AggregateConfigurationException(
            'filterRaw(): Expression instance did not expose a readable scalar `$value` property. '
            .'Pass the SQL as a string, or use `DB::raw(...)` which returns a standard '.Expression::class.'.',
        );
    }

    /**
     * Bind this aggregate to a stored column on the model. Returns the
     * immutable resolved definition the registry stores.
     *
     * @throws AggregateConfigurationException when $column is empty.
     */
    public function into(string $column): AggregateDefinition
    {
        if ($column === '') {
            throw new AggregateConfigurationException(
                'Aggregate target column name must not be empty.',
            );
        }

        return new AggregateDefinition(
            column: $column,
            function: $this->function,
            source: $this->source,
            inclusive: $this->inclusive,
            filter: $this->filter,
            separator: $this->separator,
            limit: $this->limit,
            orderBy: $this->orderBy,
            distinct: $this->distinct,
            allowNullKeys: $this->allowNullKeys,
            keyColumn: $this->keyColumn,
            valueColumn: $this->valueColumn,
            sources: $this->sources,
        );
    }

    /**
     * @param  list<string>|array<string,string>  $source
     * @return array<string,string>
     */
    private static function normaliseJsonAggSource(array $source): array
    {
        if ($source === []) {
            throw new AggregateConfigurationException(
                'Aggregate::jsonAgg(): source array must not be empty.',
            );
        }

        $isList = array_is_list($source);
        $result = [];

        foreach ($source as $jsonKey => $column) {
            if ($column === '') {
                throw new AggregateConfigurationException(
                    'Aggregate::jsonAgg(): every source column must be a non-empty string.',
                );
            }

            $key = $isList ? $column : (string) $jsonKey;

            if ($key === '') {
                throw new AggregateConfigurationException(
                    'Aggregate::jsonAgg(): JSON keys must not be empty strings.',
                );
            }

            if (array_key_exists($key, $result)) {
                throw new AggregateConfigurationException(sprintf(
                    'Aggregate::jsonAgg(): duplicate JSON key "%s" in source array. '
                    .'Use the assoc form `[\'a\' => \'%s\', \'b\' => \'%s\']` to disambiguate.',
                    $key,
                    $column,
                    $column,
                ));
            }

            $result[$key] = $column;
        }

        return $result;
    }
}
