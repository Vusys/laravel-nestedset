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
    private function __construct(
        public AggregateFunction $function,
        public ?string $source,
        public bool $inclusive,
        public ?FilterPredicate $filter = null,
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
     * Self-inclusive aggregation — the node's own source value
     * participates in its stored aggregate. This is the default and the
     * mental model for "give me the rollup for this subtree".
     */
    public function inclusive(): self
    {
        return new self($this->function, $this->source, true, $this->filter);
    }

    /**
     * Exclusive aggregation — the node's own source value is excluded;
     * only descendants contribute. A leaf's exclusive aggregate is
     * always the zero/null element for the function.
     */
    public function exclusive(): self
    {
        return new self($this->function, $this->source, false, $this->filter);
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
        return new self($this->function, $this->source, $this->inclusive, FilterPredicate::equality($conditions));
    }

    /**
     * Only aggregate rows where $column IS NOT NULL.
     */
    public function filterNotNull(string $column): self
    {
        return new self($this->function, $this->source, $this->inclusive, FilterPredicate::notNull($column));
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
        return new self(
            $this->function,
            $this->source,
            $this->inclusive,
            FilterPredicate::raw($this->expressionToString($sql), $watches),
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
        );
    }
}
