<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AttributeOnlyArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AvgOnlyArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AvgWithCompanionsArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\DuplicateColumnArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\HybridArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\MethodOnlyArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\NoAggregateArea;

final class AggregateRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    public function test_returns_empty_list_for_a_model_with_no_declarations(): void
    {
        $this->assertSame([], AggregateRegistry::for(NoAggregateArea::class));
    }

    public function test_resolves_attribute_declarations(): void
    {
        $definitions = AggregateRegistry::for(AttributeOnlyArea::class);

        $this->assertCount(2, $definitions);
        $this->assertSame('tickets_total', $definitions[0]->column);
        $this->assertSame(AggregateFunction::Sum, $definitions[0]->function);
        $this->assertSame('tickets_count', $definitions[1]->column);
        $this->assertSame(AggregateFunction::Count, $definitions[1]->function);
    }

    public function test_resolves_method_override_declarations(): void
    {
        $definitions = AggregateRegistry::for(MethodOnlyArea::class);

        $this->assertCount(2, $definitions);
        $this->assertSame('tickets_total', $definitions[0]->column);
        $this->assertSame(AggregateFunction::Sum, $definitions[0]->function);
        $this->assertSame('tickets_max', $definitions[1]->column);
        $this->assertSame(AggregateFunction::Max, $definitions[1]->function);
    }

    public function test_attribute_declarations_precede_method_override_declarations(): void
    {
        $definitions = AggregateRegistry::for(HybridArea::class);

        $this->assertCount(2, $definitions);
        $this->assertSame('tickets_total', $definitions[0]->column);
        $this->assertSame(AggregateFunction::Sum, $definitions[0]->function);
        $this->assertSame('tickets_max', $definitions[1]->column);
        $this->assertSame(AggregateFunction::Max, $definitions[1]->function);
    }

    public function test_avg_without_companions_auto_promotes_internal_sum_and_count(): void
    {
        $definitions = AggregateRegistry::for(AvgOnlyArea::class);

        $this->assertCount(3, $definitions);

        $userFacing = array_values(array_filter(
            $definitions,
            static fn (AggregateDefinition $d): bool => ! $d->isInternal(),
        ));
        $internal = array_values(array_filter(
            $definitions,
            static fn (AggregateDefinition $d): bool => $d->isInternal(),
        ));

        $this->assertCount(1, $userFacing);
        $this->assertSame('tickets_avg', $userFacing[0]->column);
        $this->assertSame(AggregateFunction::Avg, $userFacing[0]->function);

        $this->assertCount(2, $internal);

        $internalFunctions = array_map(
            static fn (AggregateDefinition $d): AggregateFunction => $d->function,
            $internal,
        );

        $this->assertContains(AggregateFunction::Sum, $internalFunctions);
        $this->assertContains(AggregateFunction::Count, $internalFunctions);
    }

    public function test_avg_companion_columns_use_predictable_suffixes(): void
    {
        $definitions = AggregateRegistry::for(AvgOnlyArea::class);

        $columns = array_map(
            static fn (AggregateDefinition $d): string => $d->column,
            $definitions,
        );

        $this->assertContains('tickets_avg'.AggregateRegistry::AVG_SUM_SUFFIX, $columns);
        $this->assertContains('tickets_avg'.AggregateRegistry::AVG_COUNT_SUFFIX, $columns);
    }

    public function test_avg_does_not_auto_promote_when_user_already_declared_companions(): void
    {
        $definitions = AggregateRegistry::for(AvgWithCompanionsArea::class);

        // 3 user declarations, no internal companions
        $this->assertCount(3, $definitions);

        foreach ($definitions as $definition) {
            $this->assertFalse(
                $definition->isInternal(),
                "Column {$definition->column} should not be internal — user declared it.",
            );
        }
    }

    public function test_rejects_duplicate_target_columns(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('declared more than once');

        AggregateRegistry::for(DuplicateColumnArea::class);
    }

    public function test_caches_resolved_definitions_across_calls(): void
    {
        $first = AggregateRegistry::for(AttributeOnlyArea::class);
        $second = AggregateRegistry::for(AttributeOnlyArea::class);

        // Same instances — cache hit returns the stored array.
        $this->assertSame($first, $second);
    }

    public function test_flush_clears_the_cache(): void
    {
        $first = AggregateRegistry::for(AttributeOnlyArea::class);
        AggregateRegistry::flush();
        $second = AggregateRegistry::for(AttributeOnlyArea::class);

        // Definitions are equal in value but the underlying array was
        // rebuilt, so the AggregateDefinition instances differ.
        $this->assertNotSame($first[0], $second[0]);
        $this->assertEquals($first[0]->column, $second[0]->column);
    }
}
