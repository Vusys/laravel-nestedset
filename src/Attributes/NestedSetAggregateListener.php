<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Attributes;

use Attribute;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Contracts\TreeAggregateListener;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * Declares a listener-based precalculated aggregate column on a nested-set
 * model. The attribute is repeatable so multiple aggregates can be declared
 * on a single class:
 *
 *     #[NestedSetAggregateListener(column: 'weighted_power', listener: WeightedPowerListener::class)]
 *     #[NestedSetAggregateListener(column: 'fire_count', listener: FireCountListener::class)]
 *     class Monster extends Model implements HasNestedSet { use NodeTrait; }
 *
 * Unlike {@see NestedSetAggregate}, this attribute accepts a PHP listener
 * class rather than a SQL source column, enabling aggregations that require
 * arbitrary PHP logic to compute each node's contribution.
 *
 * `exclusive: true` opts out of self-inclusion — a leaf's stored value for
 * an exclusive aggregate is always the function's zero/null element.
 *
 * Operations: Sum, Count, Min, Max, Avg. AVG is maintained as a derived
 * value — the registry auto-promotes hidden Sum + Count companions over
 * the same listener class; the AVG column is written as `sum / NULLIF(count, 0)`
 * after every delta. Migrations for AVG listener columns must declare the
 * companion columns (avg-col `__sum` and `__count`) alongside the AVG column.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class NestedSetAggregateListener
{
    /**
     * @param  string  $listener  class-string<TreeAggregateListener>
     */
    public function __construct(
        public string $column,
        public string $listener,
        public AggregateFunction $operation = AggregateFunction::Sum,
        public bool $exclusive = false,
    ) {}

    /**
     * Materialises this declaration as a {@see ListenerAggregateDefinition}.
     *
     * @throws AggregateConfigurationException when $column is empty.
     */
    public function toDefinition(): ListenerAggregateDefinition
    {
        if ($this->column === '') {
            throw new AggregateConfigurationException(
                'NestedSetAggregateListener: `column` must not be empty.',
            );
        }

        return new ListenerAggregateDefinition(
            column: $this->column,
            listenerClass: $this->listener,
            operation: $this->operation,
            inclusive: ! $this->exclusive,
        );
    }
}
