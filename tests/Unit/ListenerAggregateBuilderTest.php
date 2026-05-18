<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\ListenerAggregate;
use Vusys\NestedSet\Aggregates\ListenerAggregateDefinition;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\FireCountListener;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\WeightedPowerListener;

final class ListenerAggregateBuilderTest extends TestCase
{
    public function test_sum_factory_sets_sum_operation(): void
    {
        $aggregate = ListenerAggregate::sum(WeightedPowerListener::class);
        $definition = $aggregate->into('weighted_power');

        $this->assertSame(AggregateFunction::Sum, $definition->operation);
    }

    public function test_count_factory_sets_count_operation(): void
    {
        $aggregate = ListenerAggregate::count(FireCountListener::class);
        $definition = $aggregate->into('fire_count');

        $this->assertSame(AggregateFunction::Count, $definition->operation);
    }

    public function test_min_factory_sets_min_operation(): void
    {
        $aggregate = ListenerAggregate::min(WeightedPowerListener::class);
        $definition = $aggregate->into('min_power');

        $this->assertSame(AggregateFunction::Min, $definition->operation);
    }

    public function test_max_factory_sets_max_operation(): void
    {
        $aggregate = ListenerAggregate::max(WeightedPowerListener::class);
        $definition = $aggregate->into('max_power');

        $this->assertSame(AggregateFunction::Max, $definition->operation);
    }

    public function test_inclusive_is_the_default(): void
    {
        $definition = ListenerAggregate::sum(WeightedPowerListener::class)->into('weighted_power');

        $this->assertTrue($definition->inclusive);
    }

    public function test_exclusive_modifier_sets_inclusive_to_false(): void
    {
        $definition = ListenerAggregate::sum(WeightedPowerListener::class)
            ->exclusive()
            ->into('weighted_power');

        $this->assertFalse($definition->inclusive);
    }

    public function test_inclusive_modifier_restores_default(): void
    {
        $definition = ListenerAggregate::sum(WeightedPowerListener::class)
            ->exclusive()
            ->inclusive()
            ->into('weighted_power');

        $this->assertTrue($definition->inclusive);
    }

    public function test_modifiers_return_new_instances(): void
    {
        $base = ListenerAggregate::sum(WeightedPowerListener::class);
        $exclusive = $base->exclusive();

        $this->assertNotSame($base, $exclusive);
    }

    public function test_into_returns_listener_aggregate_definition_with_correct_properties(): void
    {
        $definition = ListenerAggregate::sum(WeightedPowerListener::class)->into('weighted_power');

        $this->assertInstanceOf(ListenerAggregateDefinition::class, $definition);
        $this->assertSame('weighted_power', $definition->column);
        $this->assertSame(WeightedPowerListener::class, $definition->listenerClass);
        $this->assertSame(AggregateFunction::Sum, $definition->operation);
        $this->assertTrue($definition->inclusive);
    }

    public function test_into_carries_exclusive_flag_to_definition(): void
    {
        $definition = ListenerAggregate::sum(WeightedPowerListener::class)
            ->exclusive()
            ->into('weighted_power');

        $this->assertFalse($definition->inclusive);
    }

    public function test_into_throws_for_empty_column(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('must not be empty');

        ListenerAggregate::sum(WeightedPowerListener::class)->into('');
    }
}
