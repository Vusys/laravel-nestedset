<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateDefinitionContract;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Aggregates\FilterPredicateKind;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AggregateColumnGuardedArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AggregateInFillableArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AttributeOnlyArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AvgOnlyArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\AvgWithCompanionsArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\BadListenerMethodEntryArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\BadListenerMethodReturnArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\BadMethodEntryArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\BadMethodReturnArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\DuplicateColumnArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\FilteredAvgArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\HybridArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\MethodOnlyArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\NoAggregateArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\PartiallyGuardedArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\UnguardedArea;

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
        $def0 = $definitions[0];
        $def1 = $definitions[1];
        $this->assertInstanceOf(AggregateDefinition::class, $def0);
        $this->assertInstanceOf(AggregateDefinition::class, $def1);
        $this->assertSame('tickets_total', $def0->column);
        $this->assertSame(AggregateFunction::Sum, $def0->function);
        $this->assertSame('tickets_count', $def1->column);
        $this->assertSame(AggregateFunction::Count, $def1->function);
    }

    public function test_resolves_method_override_declarations(): void
    {
        $definitions = AggregateRegistry::for(MethodOnlyArea::class);

        $this->assertCount(2, $definitions);
        $def0 = $definitions[0];
        $def1 = $definitions[1];
        $this->assertInstanceOf(AggregateDefinition::class, $def0);
        $this->assertInstanceOf(AggregateDefinition::class, $def1);
        $this->assertSame('tickets_total', $def0->column);
        $this->assertSame(AggregateFunction::Sum, $def0->function);
        $this->assertSame('tickets_max', $def1->column);
        $this->assertSame(AggregateFunction::Max, $def1->function);
    }

    public function test_attribute_declarations_precede_method_override_declarations(): void
    {
        $definitions = AggregateRegistry::for(HybridArea::class);

        $this->assertCount(2, $definitions);
        $def0 = $definitions[0];
        $def1 = $definitions[1];
        $this->assertInstanceOf(AggregateDefinition::class, $def0);
        $this->assertInstanceOf(AggregateDefinition::class, $def1);
        $this->assertSame('tickets_total', $def0->column);
        $this->assertSame(AggregateFunction::Sum, $def0->function);
        $this->assertSame('tickets_max', $def1->column);
        $this->assertSame(AggregateFunction::Max, $def1->function);
    }

    public function test_avg_without_companions_auto_promotes_internal_sum_and_count(): void
    {
        $definitions = AggregateRegistry::for(AvgOnlyArea::class);

        $this->assertCount(3, $definitions);

        $userFacing = [];
        $internal = [];
        foreach ($definitions as $def) {
            if ($def instanceof AggregateDefinition) {
                if ($def->isInternal()) {
                    $internal[] = $def;
                } else {
                    $userFacing[] = $def;
                }
            }
        }

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
            static fn (AggregateDefinitionContract $d): string => $d->getColumn(),
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
                "Column {$definition->getColumn()} should not be internal — user declared it.",
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
        $this->assertEquals($first[0]->getColumn(), $second[0]->getColumn());
    }

    public function test_avg_filtered_companions_inherit_the_filter(): void
    {
        $definitions = AggregateRegistry::for(FilteredAvgArea::class);

        $this->assertCount(3, $definitions);

        $companions = array_filter(
            $definitions,
            static fn (AggregateDefinitionContract $d): bool => $d instanceof AggregateDefinition && $d->isInternal(),
        );

        $this->assertCount(2, $companions);

        foreach ($companions as $companion) {
            $this->assertInstanceOf(AggregateDefinition::class, $companion);
            $this->assertNotNull($companion->filter);
            $this->assertSame(FilterPredicateKind::Equality, $companion->filter->getKind());
            $this->assertSame(['type' => 'fire'], $companion->filter->getConditions());
        }
    }

    public function test_method_override_must_return_array(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('nestedSetAggregates() must return an array');

        AggregateRegistry::for(BadMethodReturnArea::class);
    }

    public function test_method_override_entry_must_be_aggregate_definition(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('entry at index 0 is not an AggregateDefinition');

        AggregateRegistry::for(BadMethodEntryArea::class);
    }

    public function test_listener_method_override_must_return_array(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('nestedSetListenerAggregates() must return an array');

        AggregateRegistry::for(BadListenerMethodReturnArea::class);
    }

    public function test_listener_method_override_entry_must_be_listener_aggregate_definition(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('entry at index 0 is not a ListenerAggregateDefinition');

        AggregateRegistry::for(BadListenerMethodEntryArea::class);
    }

    public function test_aggregate_column_in_fillable_throws(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('aggregate column(s) [tickets_total] appear in $fillable');

        AggregateRegistry::for(AggregateInFillableArea::class);
    }

    public function test_unguarded_model_with_aggregate_column_throws(): void
    {
        // `protected $guarded = []` (modern Laravel idiom) makes every
        // column mass-assignable. Combined with an aggregate
        // declaration, that's a silent-clobber footgun — the registry
        // must reject it at boot.
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('aggregate column(s) [tickets_total] are mass-assignable');

        AggregateRegistry::for(UnguardedArea::class);
    }

    public function test_partially_guarded_model_omitting_aggregate_column_throws(): void
    {
        // `$guarded` is set but doesn't include the aggregate column.
        // Same risk as the unguarded case.
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('aggregate column(s) [tickets_total] are mass-assignable');

        AggregateRegistry::for(PartiallyGuardedArea::class);
    }

    public function test_aggregate_column_listed_in_guarded_is_accepted(): void
    {
        // Recommended escape hatch when a project generally avoids
        // `$fillable`: list the aggregate columns in `$guarded`.
        $defs = AggregateRegistry::for(AggregateColumnGuardedArea::class);

        $this->assertNotSame([], $defs);
    }

    public function test_default_guard_star_is_accepted(): void
    {
        // Eloquent's out-of-the-box `protected $guarded = ['*']`
        // guards everything — no aggregate column can be
        // mass-assigned. AttributeOnlyArea uses the default config
        // (no fillable, no overridden guarded) and must register
        // cleanly.
        $defs = AggregateRegistry::for(AttributeOnlyArea::class);

        $this->assertNotSame([], $defs);
    }
}
