<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

final class AggregateTest extends TestCase
{
    public function test_sum_factory_captures_source_and_function(): void
    {
        $aggregate = Aggregate::sum('tickets');

        $this->assertSame(AggregateFunction::Sum, $aggregate->function);
        $this->assertSame('tickets', $aggregate->source);
    }

    public function test_count_with_no_argument_uses_null_source(): void
    {
        $aggregate = Aggregate::count();

        $this->assertSame(AggregateFunction::Count, $aggregate->function);
        $this->assertNull($aggregate->source);
    }

    public function test_count_with_column_captures_it_as_source(): void
    {
        $aggregate = Aggregate::count('tickets');

        $this->assertSame(AggregateFunction::Count, $aggregate->function);
        $this->assertSame('tickets', $aggregate->source);
    }

    public function test_avg_factory_captures_source(): void
    {
        $this->assertSame('tickets', Aggregate::avg('tickets')->source);
        $this->assertSame(AggregateFunction::Avg, Aggregate::avg('tickets')->function);
    }

    public function test_min_factory_captures_source(): void
    {
        $this->assertSame('tickets', Aggregate::min('tickets')->source);
        $this->assertSame(AggregateFunction::Min, Aggregate::min('tickets')->function);
    }

    public function test_max_factory_captures_source(): void
    {
        $this->assertSame('tickets', Aggregate::max('tickets')->source);
        $this->assertSame(AggregateFunction::Max, Aggregate::max('tickets')->function);
    }

    public function test_inclusive_is_the_default(): void
    {
        $this->assertTrue(Aggregate::sum('tickets')->inclusive);
        $this->assertTrue(Aggregate::count()->inclusive);
        $this->assertTrue(Aggregate::avg('tickets')->inclusive);
        $this->assertTrue(Aggregate::min('tickets')->inclusive);
        $this->assertTrue(Aggregate::max('tickets')->inclusive);
    }

    public function test_exclusive_modifier_flips_the_inclusive_flag(): void
    {
        $aggregate = Aggregate::sum('tickets')->exclusive();

        $this->assertFalse($aggregate->inclusive);
    }

    public function test_inclusive_modifier_restores_the_default(): void
    {
        $aggregate = Aggregate::sum('tickets')->exclusive()->inclusive();

        $this->assertTrue($aggregate->inclusive);
    }

    public function test_modifiers_return_new_instances(): void
    {
        $base = Aggregate::sum('tickets');
        $exclusive = $base->exclusive();

        $this->assertNotSame($base, $exclusive);
        $this->assertTrue($base->inclusive);
        $this->assertFalse($exclusive->inclusive);
    }

    public function test_into_produces_a_definition_with_all_fields(): void
    {
        $definition = Aggregate::sum('tickets')->into('tickets_total');

        $this->assertSame('tickets_total', $definition->column);
        $this->assertSame(AggregateFunction::Sum, $definition->function);
        $this->assertSame('tickets', $definition->source);
        $this->assertTrue($definition->inclusive);
        $this->assertFalse($definition->isInternal());
    }

    public function test_into_carries_exclusive_flag_into_the_definition(): void
    {
        $definition = Aggregate::sum('tickets')
            ->exclusive()
            ->into('descendants_total');

        $this->assertFalse($definition->inclusive);
    }

    public function test_into_rejects_empty_column_name(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('must not be empty');

        Aggregate::sum('tickets')->into('');
    }
}
