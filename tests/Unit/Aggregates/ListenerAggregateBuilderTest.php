<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\ListenerAggregate;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\FireCountListener;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\WeightedPowerListener;

final class ListenerAggregateBuilderTest extends TestCase
{
    #[Test]
    public function sum_factory_sets_sum_operation(): void
    {
        $aggregate = ListenerAggregate::sum(WeightedPowerListener::class);
        $definition = $aggregate->into('weighted_power');

        $this->assertSame(AggregateFunction::Sum, $definition->operation);
    }

    #[Test]
    public function count_factory_sets_count_operation(): void
    {
        $aggregate = ListenerAggregate::count(FireCountListener::class);
        $definition = $aggregate->into('fire_count');

        $this->assertSame(AggregateFunction::Count, $definition->operation);
    }

    #[Test]
    public function min_factory_sets_min_operation(): void
    {
        $aggregate = ListenerAggregate::min(WeightedPowerListener::class);
        $definition = $aggregate->into('min_power');

        $this->assertSame(AggregateFunction::Min, $definition->operation);
    }

    #[Test]
    public function max_factory_sets_max_operation(): void
    {
        $aggregate = ListenerAggregate::max(WeightedPowerListener::class);
        $definition = $aggregate->into('max_power');

        $this->assertSame(AggregateFunction::Max, $definition->operation);
    }

    #[Test]
    public function inclusive_is_the_default(): void
    {
        $definition = ListenerAggregate::sum(WeightedPowerListener::class)->into('weighted_power');

        $this->assertTrue($definition->inclusive);
    }

    #[Test]
    public function exclusive_modifier_sets_inclusive_to_false(): void
    {
        $definition = ListenerAggregate::sum(WeightedPowerListener::class)
            ->exclusive()
            ->into('weighted_power');

        $this->assertFalse($definition->inclusive);
    }

    #[Test]
    public function inclusive_modifier_restores_default(): void
    {
        $definition = ListenerAggregate::sum(WeightedPowerListener::class)
            ->exclusive()
            ->inclusive()
            ->into('weighted_power');

        $this->assertTrue($definition->inclusive);
    }

    #[Test]
    public function modifiers_return_new_instances(): void
    {
        $base = ListenerAggregate::sum(WeightedPowerListener::class);
        $exclusive = $base->exclusive();

        $this->assertNotSame($base, $exclusive);
    }

    #[Test]
    public function into_returns_listener_aggregate_definition_with_correct_properties(): void
    {
        $definition = ListenerAggregate::sum(WeightedPowerListener::class)->into('weighted_power');

        $this->assertInstanceOf(ListenerAggregateDefinition::class, $definition);
        $this->assertSame('weighted_power', $definition->column);
        $this->assertSame(WeightedPowerListener::class, $definition->listenerClass);
        $this->assertSame(AggregateFunction::Sum, $definition->operation);
        $this->assertTrue($definition->inclusive);
    }

    #[Test]
    public function into_carries_exclusive_flag_to_definition(): void
    {
        $definition = ListenerAggregate::sum(WeightedPowerListener::class)
            ->exclusive()
            ->into('weighted_power');

        $this->assertFalse($definition->inclusive);
    }

    #[Test]
    public function into_throws_for_empty_column(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('must not be empty');

        ListenerAggregate::sum(WeightedPowerListener::class)->into('');
    }
}
