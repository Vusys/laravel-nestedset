<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates\Registry;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\ListenerAggregate;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\FireCountListener;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\ListenerMethodArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\ListenerOnlyArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\MixedAggregatesArea;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\WeightedPowerListener;

final class ListenerAggregateRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    public function test_resolves_listener_attribute_declarations(): void
    {
        $definitions = AggregateRegistry::for(ListenerOnlyArea::class);

        $this->assertCount(2, $definitions);

        foreach ($definitions as $definition) {
            $this->assertInstanceOf(ListenerAggregateDefinition::class, $definition);
        }

        $columns = array_map(
            static fn (ListenerAggregateDefinition $d): string => $d->getColumn(),
            // All entries are ListenerAggregateDefinition; narrowed above.
            array_values(array_filter(
                $definitions,
                static fn (object $d): bool => $d instanceof ListenerAggregateDefinition,
            )),
        );

        $this->assertContains('weighted_power', $columns);
        $this->assertContains('fire_count', $columns);
    }

    public function test_resolves_listener_method_override_declarations(): void
    {
        $definitions = AggregateRegistry::for(ListenerMethodArea::class);

        $this->assertCount(1, $definitions);

        $def = $definitions[0];
        $this->assertInstanceOf(ListenerAggregateDefinition::class, $def);
        $this->assertSame('weighted_power', $def->getColumn());
    }

    public function test_resolves_mixed_sql_and_listener_declarations(): void
    {
        $definitions = AggregateRegistry::for(MixedAggregatesArea::class);

        $this->assertCount(2, $definitions);

        $sqlDefs = array_values(array_filter(
            $definitions,
            static fn (object $d): bool => $d instanceof AggregateDefinition,
        ));
        $listenerDefs = array_values(array_filter(
            $definitions,
            static fn (object $d): bool => $d instanceof ListenerAggregateDefinition,
        ));

        $this->assertCount(1, $sqlDefs);
        $this->assertCount(1, $listenerDefs);

        $this->assertSame('tickets_total', $sqlDefs[0]->column);
        $this->assertSame('weighted_power', $listenerDefs[0]->column);
    }

    public function test_listener_definitions_survive_avg_promotion(): void
    {
        // MixedAggregatesArea has no AVG, so promotion is a no-op.
        // The count must remain at 2 (no extra internal companions added).
        $definitions = AggregateRegistry::for(MixedAggregatesArea::class);

        $this->assertCount(2, $definitions);
    }

    public function test_listener_avg_promotes_internal_sum_and_count_companions(): void
    {
        $definitions = AggregateRegistry::for(ListenerAvgArea::class);

        /** @var array<string, ListenerAggregateDefinition> $byColumn */
        $byColumn = [];
        foreach ($definitions as $def) {
            $this->assertInstanceOf(ListenerAggregateDefinition::class, $def);
            $byColumn[$def->getColumn()] = $def;
        }

        $this->assertArrayHasKey('power_avg', $byColumn);
        $this->assertArrayHasKey('power_avg__sum', $byColumn);
        $this->assertArrayHasKey('power_avg__count', $byColumn);

        $this->assertSame(AggregateFunction::Avg, $byColumn['power_avg']->operation);
        $this->assertFalse($byColumn['power_avg']->isInternal());

        $sum = $byColumn['power_avg__sum'];
        $this->assertSame(AggregateFunction::Sum, $sum->operation);
        $this->assertTrue($sum->isInternal());

        $count = $byColumn['power_avg__count'];
        $this->assertSame(AggregateFunction::Count, $count->operation);
        $this->assertTrue($count->isInternal());
    }

    public function test_user_declared_listener_companions_suppress_avg_promotion(): void
    {
        $definitions = AggregateRegistry::for(ListenerAvgWithDeclaredCompanionsArea::class);

        $columns = [];
        foreach ($definitions as $def) {
            $this->assertInstanceOf(ListenerAggregateDefinition::class, $def);
            $columns[] = $def->getColumn();
        }

        // The AVG's Sum and Count companions are already satisfied by the
        // user-declared Sum/Count on the same listener+inclusivity, so the
        // registry must not append internal `__sum` / `__count` companions.
        $this->assertCount(3, $definitions);
        $this->assertNotContains('power_avg__sum', $columns);
        $this->assertNotContains('power_avg__count', $columns);
    }

    public function test_listener_method_override_returns_every_declaration(): void
    {
        $definitions = AggregateRegistry::for(MultiListenerMethodArea::class);

        $columns = [];
        foreach ($definitions as $def) {
            $this->assertInstanceOf(ListenerAggregateDefinition::class, $def);
            $columns[] = $def->getColumn();
        }

        $this->assertCount(2, $definitions);
        $this->assertContains('weighted_power', $columns);
        $this->assertContains('fire_count', $columns);
    }

    public function test_duplicate_column_across_sql_and_listener_throws(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('declared more than once');

        AggregateRegistry::for(DuplicateListenerArea::class);
    }

    public function test_listener_definitions_are_never_internal(): void
    {
        $definitions = AggregateRegistry::for(ListenerOnlyArea::class);

        foreach ($definitions as $definition) {
            $this->assertInstanceOf(ListenerAggregateDefinition::class, $definition);
            $this->assertFalse(
                $definition->isInternal(),
                "ListenerAggregateDefinition for column {$definition->getColumn()} must not be internal.",
            );
        }
    }

    public function test_caches_mixed_definitions(): void
    {
        $first = AggregateRegistry::for(MixedAggregatesArea::class);
        $second = AggregateRegistry::for(MixedAggregatesArea::class);

        $this->assertSame($first, $second);
    }
}

/**
 * Inline fixture: declares the same column as both a SQL aggregate and a
 * listener aggregate. Registry must reject this configuration.
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
#[NestedSetAggregateListener(column: 'tickets_total', listener: WeightedPowerListener::class, operation: AggregateFunction::Sum)]
final class DuplicateListenerArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;
}

/**
 * Inline fixture: a single listener AVG. The registry must auto-promote
 * the `__sum` and `__count` companions as internal definitions.
 */
#[NestedSetAggregateListener(column: 'power_avg', listener: WeightedPowerListener::class, operation: AggregateFunction::Avg)]
final class ListenerAvgArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;
}

/**
 * Inline fixture: a listener AVG plus user-declared Sum and Count on the
 * same listener class and inclusivity. The AVG's companions are already
 * satisfied, so no internal companions should be appended.
 */
#[NestedSetAggregateListener(column: 'power_avg', listener: WeightedPowerListener::class, operation: AggregateFunction::Avg)]
#[NestedSetAggregateListener(column: 'power_total', listener: WeightedPowerListener::class, operation: AggregateFunction::Sum)]
#[NestedSetAggregateListener(column: 'power_n', listener: WeightedPowerListener::class, operation: AggregateFunction::Count)]
final class ListenerAvgWithDeclaredCompanionsArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;
}

/**
 * Inline fixture: two listener aggregates declared via method override.
 * The resolver must return both, not just the first.
 */
final class MultiListenerMethodArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    /** @return list<ListenerAggregateDefinition> */
    protected function nestedSetListenerAggregates(): array
    {
        return [
            ListenerAggregate::sum(WeightedPowerListener::class)->into('weighted_power'),
            ListenerAggregate::sum(FireCountListener::class)->into('fire_count'),
        ];
    }
}
