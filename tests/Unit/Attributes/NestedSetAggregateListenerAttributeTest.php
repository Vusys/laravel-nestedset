<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Attributes;

use Attribute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\FireCountListener;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\WeightedPowerListener;

final class NestedSetAggregateListenerAttributeTest extends TestCase
{
    #[Test]
    public function to_definition_returns_listener_aggregate_definition_with_expected_column(): void
    {
        $attribute = new NestedSetAggregateListener(
            column: 'weighted_power',
            listener: WeightedPowerListener::class,
            operation: AggregateFunction::Sum,
        );

        $definition = $attribute->toDefinition();

        $this->assertInstanceOf(ListenerAggregateDefinition::class, $definition);
        $this->assertSame('weighted_power', $definition->column);
    }

    #[Test]
    public function to_definition_carries_listener_class(): void
    {
        $attribute = new NestedSetAggregateListener(
            column: 'weighted_power',
            listener: WeightedPowerListener::class,
            operation: AggregateFunction::Sum,
        );

        $definition = $attribute->toDefinition();

        $this->assertSame(WeightedPowerListener::class, $definition->listenerClass);
    }

    #[Test]
    public function to_definition_carries_operation(): void
    {
        $attribute = new NestedSetAggregateListener(
            column: 'fire_count',
            listener: FireCountListener::class,
            operation: AggregateFunction::Sum,
        );

        $definition = $attribute->toDefinition();

        $this->assertSame(AggregateFunction::Sum, $definition->operation);
    }

    #[Test]
    public function to_definition_carries_min_operation(): void
    {
        $attribute = new NestedSetAggregateListener(
            column: 'min_power',
            listener: WeightedPowerListener::class,
            operation: AggregateFunction::Min,
        );

        $definition = $attribute->toDefinition();

        $this->assertSame(AggregateFunction::Min, $definition->operation);
    }

    #[Test]
    public function to_definition_carries_max_operation(): void
    {
        $attribute = new NestedSetAggregateListener(
            column: 'max_power',
            listener: WeightedPowerListener::class,
            operation: AggregateFunction::Max,
        );

        $definition = $attribute->toDefinition();

        $this->assertSame(AggregateFunction::Max, $definition->operation);
    }

    #[Test]
    public function inclusive_is_the_default(): void
    {
        $attribute = new NestedSetAggregateListener(
            column: 'weighted_power',
            listener: WeightedPowerListener::class,
        );

        $definition = $attribute->toDefinition();

        $this->assertTrue($definition->inclusive);
        $this->assertTrue($definition->isInclusive());
    }

    #[Test]
    public function exclusive_true_maps_to_inclusive_false(): void
    {
        $attribute = new NestedSetAggregateListener(
            column: 'weighted_power',
            listener: WeightedPowerListener::class,
            exclusive: true,
        );

        $definition = $attribute->toDefinition();

        $this->assertFalse($definition->inclusive);
        $this->assertFalse($definition->isInclusive());
    }

    #[Test]
    public function avg_operation_produces_definition(): void
    {
        // AVG listener defs are supported; the registry auto-promotes
        // Sum + Count companions over the same listener class so the
        // delta machinery can maintain the AVG display column.
        $attribute = new NestedSetAggregateListener(
            column: 'avg_power',
            listener: WeightedPowerListener::class,
            operation: AggregateFunction::Avg,
        );

        $definition = $attribute->toDefinition();

        $this->assertSame('avg_power', $definition->column);
        $this->assertSame(AggregateFunction::Avg, $definition->operation);
    }

    #[Test]
    public function empty_column_throws(): void
    {
        $attribute = new NestedSetAggregateListener(
            column: '',
            listener: WeightedPowerListener::class,
        );

        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('`column` must not be empty');

        $attribute->toDefinition();
    }

    #[Test]
    public function is_declared_as_a_repeatable_class_level_attribute(): void
    {
        $reflection = new ReflectionClass(NestedSetAggregateListener::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        $this->assertCount(1, $attributes);

        /** @var Attribute $attr */
        $attr = $attributes[0]->newInstance();

        $this->assertSame(
            Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE,
            $attr->flags,
        );
    }

    #[Test]
    public function sum_is_the_default_operation(): void
    {
        $attribute = new NestedSetAggregateListener(
            column: 'fire_count',
            listener: FireCountListener::class,
        );

        $definition = $attribute->toDefinition();

        $this->assertSame(AggregateFunction::Sum, $definition->operation);
    }
}
