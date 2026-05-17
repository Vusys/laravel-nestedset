<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

use Vusys\NestedSet\Aggregates\FilterPredicate;
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
     * @param  list<string>  $watches  columns whose changes should trigger re-aggregation.
     */
    public function filterRaw(string $sql, array $watches = []): self
    {
        return new self($this->function, $this->source, $this->inclusive, FilterPredicate::raw($sql, $watches));
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
