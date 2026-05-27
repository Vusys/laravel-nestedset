<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;

final class AggregateDefinitionTest extends TestCase
{
    public function test_holds_every_resolved_field(): void
    {
        $definition = new AggregateDefinition(
            column: 'tickets_total',
            function: AggregateFunction::Sum,
            source: 'tickets',
            inclusive: true,
        );

        $this->assertSame('tickets_total', $definition->column);
        $this->assertSame(AggregateFunction::Sum, $definition->function);
        $this->assertSame('tickets', $definition->source);
        $this->assertTrue($definition->inclusive);
    }

    public function test_is_not_internal_by_default(): void
    {
        $definition = new AggregateDefinition(
            column: 'tickets_total',
            function: AggregateFunction::Sum,
            source: 'tickets',
            inclusive: true,
        );

        $this->assertFalse($definition->isInternal());
    }

    public function test_can_be_marked_internal_for_avg_companions(): void
    {
        $definition = new AggregateDefinition(
            column: 'tickets_avg__sum',
            function: AggregateFunction::Sum,
            source: 'tickets',
            inclusive: true,
            internal: true,
        );

        $this->assertTrue($definition->isInternal());
    }

    public function test_count_definitions_carry_null_source(): void
    {
        $definition = new AggregateDefinition(
            column: 'tickets_count',
            function: AggregateFunction::Count,
            source: null,
            inclusive: true,
        );

        $this->assertNull($definition->source);
    }
}
