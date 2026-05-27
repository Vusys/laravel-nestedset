<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateDefinitionContract;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\TreeAggregateListener;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\WeightedPowerListener;

final class ListenerAggregateDefinitionTest extends TestCase
{
    public function test_implements_aggregate_definition_contract(): void
    {
        $definition = new ListenerAggregateDefinition(
            column: 'weighted_power',
            listenerClass: WeightedPowerListener::class,
            operation: AggregateFunction::Sum,
        );

        $this->assertInstanceOf(AggregateDefinitionContract::class, $definition);
    }

    public function test_get_column_returns_constructor_column(): void
    {
        $definition = new ListenerAggregateDefinition(
            column: 'weighted_power',
            listenerClass: WeightedPowerListener::class,
            operation: AggregateFunction::Sum,
        );

        $this->assertSame('weighted_power', $definition->getColumn());
    }

    public function test_is_inclusive_returns_true_by_default(): void
    {
        $definition = new ListenerAggregateDefinition(
            column: 'weighted_power',
            listenerClass: WeightedPowerListener::class,
            operation: AggregateFunction::Sum,
        );

        $this->assertTrue($definition->isInclusive());
    }

    public function test_is_inclusive_returns_false_when_constructed_exclusive(): void
    {
        $definition = new ListenerAggregateDefinition(
            column: 'weighted_power',
            listenerClass: WeightedPowerListener::class,
            operation: AggregateFunction::Sum,
            inclusive: false,
        );

        $this->assertFalse($definition->isInclusive());
    }

    public function test_is_internal_always_returns_false(): void
    {
        $definition = new ListenerAggregateDefinition(
            column: 'weighted_power',
            listenerClass: WeightedPowerListener::class,
            operation: AggregateFunction::Sum,
        );

        $this->assertFalse($definition->isInternal());
    }

    public function test_make_listener_returns_correct_listener_instance(): void
    {
        $definition = new ListenerAggregateDefinition(
            column: 'weighted_power',
            listenerClass: WeightedPowerListener::class,
            operation: AggregateFunction::Sum,
        );

        $listener = $definition->makeListener();

        $this->assertInstanceOf(WeightedPowerListener::class, $listener);
        $this->assertInstanceOf(TreeAggregateListener::class, $listener);
    }

    public function test_make_listener_throws_for_nonexistent_class(): void
    {
        $definition = new ListenerAggregateDefinition(
            column: 'weighted_power',
            listenerClass: 'Vusys\NestedSet\Tests\Fixtures\Aggregates\NonExistentListener',
            operation: AggregateFunction::Sum,
        );

        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('does not exist');

        $definition->makeListener();
    }

    public function test_make_listener_throws_when_class_does_not_implement_interface(): void
    {
        $definition = new ListenerAggregateDefinition(
            column: 'weighted_power',
            listenerClass: \stdClass::class,
            operation: AggregateFunction::Sum,
        );

        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('does not implement');

        $definition->makeListener();
    }

    public function test_rejects_bitwise_operations(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('cannot use a bitwise operation');

        new ListenerAggregateDefinition(
            column: 'features_or',
            listenerClass: WeightedPowerListener::class,
            operation: AggregateFunction::BitOr,
        );
    }
}
