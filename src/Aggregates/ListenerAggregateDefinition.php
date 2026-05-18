<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * Immutable resolved declaration for a listener-based aggregate column.
 *
 * Produced by {@see ListenerAggregate::into()} (method-override form) or
 * by {@see NestedSetAggregateListener::toDefinition()}
 * (attribute form), and stored in the aggregate registry.
 *
 * Implements {@see AggregateDefinitionContract} so it can be handled
 * alongside {@see AggregateDefinition} by code that does not need to know
 * which kind it holds.
 */
final readonly class ListenerAggregateDefinition implements AggregateDefinitionContract
{
    /**
     * @param  string  $listenerClass  class-string<TreeAggregateListener>
     */
    public function __construct(
        public string $column,
        public string $listenerClass,
        public AggregateFunction $operation,
        public bool $inclusive = true,
    ) {}

    public function getColumn(): string
    {
        return $this->column;
    }

    public function isInclusive(): bool
    {
        return $this->inclusive;
    }

    /**
     * Listener aggregates are never auto-generated internal companions.
     */
    public function isInternal(): bool
    {
        return false;
    }

    /**
     * Resolves and returns an instance of the listener class.
     *
     * @throws AggregateConfigurationException if the class does not exist
     *                                         or does not implement {@see TreeAggregateListener}.
     */
    public function makeListener(): TreeAggregateListener
    {
        if (! class_exists($this->listenerClass)) {
            throw new AggregateConfigurationException(sprintf(
                'Listener class "%s" does not exist.',
                $this->listenerClass,
            ));
        }

        if (! is_a($this->listenerClass, TreeAggregateListener::class, true)) {
            throw new AggregateConfigurationException(sprintf(
                'Listener class "%s" does not implement %s.',
                $this->listenerClass,
                TreeAggregateListener::class,
            ));
        }

        return new ($this->listenerClass)();
    }
}
