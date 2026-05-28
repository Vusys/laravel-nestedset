<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates\Definitions;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Contracts\AggregateDefinitionContract;

final class AggregateDefinitionContractTest extends TestCase
{
    public function test_aggregate_definition_implements_contract(): void
    {
        $definition = new AggregateDefinition(
            column: 'tickets_total',
            function: AggregateFunction::Sum,
            source: 'tickets',
            inclusive: true,
        );

        $this->assertInstanceOf(AggregateDefinitionContract::class, $definition);
    }

    public function test_get_column_delegates_to_column_property(): void
    {
        $definition = new AggregateDefinition(
            column: 'tickets_total',
            function: AggregateFunction::Sum,
            source: 'tickets',
            inclusive: true,
        );

        $this->assertSame('tickets_total', $definition->getColumn());
    }

    public function test_is_inclusive_delegates_to_inclusive_property(): void
    {
        $inclusive = new AggregateDefinition(
            column: 'a',
            function: AggregateFunction::Sum,
            source: 'tickets',
            inclusive: true,
        );

        $exclusive = new AggregateDefinition(
            column: 'b',
            function: AggregateFunction::Sum,
            source: 'tickets',
            inclusive: false,
        );

        $this->assertTrue($inclusive->isInclusive());
        $this->assertFalse($exclusive->isInclusive());
    }

    public function test_is_internal_returns_false_by_default(): void
    {
        $definition = new AggregateDefinition(
            column: 'tickets_total',
            function: AggregateFunction::Sum,
            source: 'tickets',
            inclusive: true,
        );

        $this->assertFalse($definition->isInternal());
    }

    public function test_is_internal_returns_true_when_flagged(): void
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

    public function test_contract_surface_is_usable_without_narrowing(): void
    {
        $definition = new AggregateDefinition(
            column: 'tickets_count',
            function: AggregateFunction::Count,
            source: null,
            inclusive: true,
        );

        // Call through the interface methods to confirm they delegate correctly.
        $this->assertSame('tickets_count', $definition->getColumn());
        $this->assertTrue($definition->isInclusive());
        $this->assertFalse($definition->isInternal());
    }
}
