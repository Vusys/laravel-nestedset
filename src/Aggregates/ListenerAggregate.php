<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * Fluent factory for declaring a listener-based aggregate column on a
 * nested-set model. Used by the method-override declaration form:
 *
 *     protected function nestedSetAggregates(): array
 *     {
 *         return [
 *             ListenerAggregate::sum(WeightedPowerListener::class)->into('weighted_power'),
 *             ListenerAggregate::sum(FireCountListener::class)->into('fire_count'),
 *         ];
 *     }
 *
 * The attribute form ({@see NestedSetAggregateListener})
 * is preferred for static declarations; this class is the escape hatch
 * for runtime / conditional declarations.
 *
 * Instances are immutable; modifiers like {@see exclusive()} return a
 * new instance. The terminal call {@see into()} produces a
 * {@see ListenerAggregateDefinition} that the registry consumes.
 */
final readonly class ListenerAggregate
{
    private function __construct(
        private string $listenerClass,
        private AggregateFunction $operation,
        private bool $inclusive,
        private bool $lazy = false,
        private ?int $ttl = null,
    ) {}

    /**
     * SUM of listener contributions over the subtree.
     */
    public static function sum(string $listenerClass): self
    {
        return new self($listenerClass, AggregateFunction::Sum, true);
    }

    /**
     * COUNT of non-null listener contributions over the subtree.
     */
    public static function count(string $listenerClass): self
    {
        return new self($listenerClass, AggregateFunction::Count, true);
    }

    /**
     * MIN of listener contributions over the subtree.
     */
    public static function min(string $listenerClass): self
    {
        return new self($listenerClass, AggregateFunction::Min, true);
    }

    /**
     * MAX of listener contributions over the subtree.
     */
    public static function max(string $listenerClass): self
    {
        return new self($listenerClass, AggregateFunction::Max, true);
    }

    /**
     * AVG of listener contributions over the subtree. Stored as a
     * derived value: the registry auto-promotes Sum and Count
     * companions over the same listener class; the AVG display
     * column is maintained as `sum / NULLIF(count, 0)` after every
     * delta.
     */
    public static function avg(string $listenerClass): self
    {
        return new self($listenerClass, AggregateFunction::Avg, true);
    }

    /**
     * Self-inclusive aggregation — the node's own contribution participates
     * in its stored aggregate. This is the default.
     */
    public function inclusive(): self
    {
        return new self($this->listenerClass, $this->operation, true, $this->lazy, $this->ttl);
    }

    /**
     * Exclusive aggregation — the node's own contribution is excluded;
     * only descendants contribute. A leaf's exclusive aggregate is
     * always the zero/null element for the function.
     */
    public function exclusive(): self
    {
        return new self($this->listenerClass, $this->operation, false, $this->lazy, $this->ttl);
    }

    /**
     * Mark this listener aggregate as lazily maintained. Mutations
     * invalidate the column (set value and `<column>_computed_at` to
     * NULL on every ancestor); the first read past the invalidation
     * runs the listener over the subtree, stores the result, and
     * stamps the companion. Use when listener contributions are
     * expensive and reads are rarer than mutations.
     *
     * Allowed on Sum / Count / Min / Max only — Avg routes through
     * companion-derived display columns and rejects lazy at definition
     * build time. `$ttl` (seconds) sets a freshness window; pass
     * `null` to disable time-based expiry (refresh only on
     * read-after-mutation).
     */
    public function lazy(?int $ttl = null): self
    {
        return new self($this->listenerClass, $this->operation, $this->inclusive, true, $ttl);
    }

    /**
     * Bind this aggregate to a stored column on the model. Returns the
     * immutable resolved definition the registry stores.
     *
     * @throws AggregateConfigurationException when $column is empty.
     */
    public function into(string $column): ListenerAggregateDefinition
    {
        if ($column === '') {
            throw new AggregateConfigurationException(
                'ListenerAggregate target column name must not be empty.',
            );
        }

        return new ListenerAggregateDefinition(
            column: $column,
            listenerClass: $this->listenerClass,
            operation: $this->operation,
            inclusive: $this->inclusive,
            lazy: $this->lazy,
            ttl: $this->ttl,
        );
    }
}
