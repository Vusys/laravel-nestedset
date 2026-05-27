<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\NodeTrait;
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
final class DuplicateListenerArea extends Model implements HasNestedSet
{
    use NodeTrait;
}
