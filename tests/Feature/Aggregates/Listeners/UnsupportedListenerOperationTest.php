<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Listeners;

use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Tests\Fixtures\Models\Monster;
use Vusys\NestedSet\Tests\Fixtures\Models\UnsupportedOpMonster;
use Vusys\NestedSet\Tests\TestCase;

/**
 * A listener aggregate declared with an operation the PHP path can't
 * compute fails loudly when read, rather than silently returning a
 * wrong value. Variance/Stddev raise a LogicException (use a SQL
 * aggregate instead); the collection / quantile / derived ops raise an
 * AggregateConfigurationException pointing at #[NestedSetAggregate].
 */
final class UnsupportedListenerOperationTest extends TestCase
{
    private function seedMonsterRoot(): int
    {
        $monster = new Monster(['name' => 'root', 'type' => 'fire', 'base_power' => 10, 'level' => 2]);
        $monster->saveAsRoot();

        return (int) $monster->id;
    }

    public function test_fresh_read_of_a_variance_listener_aggregate_throws(): void
    {
        $node = UnsupportedOpMonster::query()->findOrFail($this->seedMonsterRoot());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Variance / Stddev are not supported');

        $node->freshAggregate('weighted_power');
    }

    public function test_fresh_read_of_a_median_listener_aggregate_throws(): void
    {
        $node = UnsupportedOpMonster::query()->findOrFail($this->seedMonsterRoot());

        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('Listener aggregates do not support');

        $node->freshAggregate('fire_count');
    }
}
