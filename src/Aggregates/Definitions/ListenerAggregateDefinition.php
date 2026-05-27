<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Definitions;

use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\ListenerAggregate;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\AggregateDefinitionContract;
use Vusys\NestedSet\Contracts\TreeAggregateListener;
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
     * @param  bool  $internal  true when this definition was auto-added
     *                          by the registry as a companion to a
     *                          listener AVG declaration. Internal
     *                          companions are maintained by the engine
     *                          but excluded from the user-facing
     *                          inspection API (getAggregateDefinitions(),
     *                          aggregateErrors() output).
     */
    public function __construct(
        public string $column,
        public string $listenerClass,
        public AggregateFunction $operation,
        public bool $inclusive = true,
        public bool $internal = false,
    ) {
        if (in_array($this->operation, [AggregateFunction::BitOr, AggregateFunction::BitAnd, AggregateFunction::BitXor], true)) {
            throw new AggregateConfigurationException(sprintf(
                'Listener aggregate "%s" cannot use a bitwise operation (%s). '
                .'Bitwise rollups are SQL-only — declare via #[NestedSetAggregate(bitOr: ...)] or Aggregate::bitOr(...) on a source column.',
                $column,
                $operation->value,
            ));
        }
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function isInclusive(): bool
    {
        return $this->inclusive;
    }

    /**
     * True when this definition was auto-added by the registry as a
     * companion to a listener AVG declaration.
     */
    public function isInternal(): bool
    {
        return $this->internal;
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
