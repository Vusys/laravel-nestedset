<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Listeners;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Throwable;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Tests\Fixtures\Models\Monster;
use Vusys\NestedSet\Tests\Fixtures\Models\UnsupportedOpMonster;
use Vusys\NestedSet\Tests\TestCase;

/**
 * A listener aggregate declared with an operation the PHP path can't
 * compute fails loudly when read, rather than silently returning a
 * wrong value. The collection / quantile / derived ops raise an
 * AggregateConfigurationException pointing at #[NestedSetAggregate].
 *
 * UnsupportedOpMonster declares one column per unsupported operation;
 * the data provider drives `freshAggregate(<column>)` for each so each
 * arm of the match in {@see HasNestedSetAggregates::freshListenerAggregate()}
 * gets a dedicated kill — a single shared-arm test would let any one of
 * the shared cases drop without detection.
 */
final class UnsupportedListenerOperationTest extends TestCase
{
    private function seedMonsterRoot(): int
    {
        $monster = new Monster(['name' => 'root', 'type' => 'fire', 'base_power' => 10, 'level' => 2]);
        $monster->saveAsRoot();

        return (int) $monster->id;
    }

    /** @return iterable<string, array{0: string, 1: class-string<Throwable>, 2: string}> */
    public static function unsupportedOpColumns(): iterable
    {
        // AggregateConfigurationException arm — collection / quantile / derived ops.
        yield 'weighted_avg' => ['op_weighted_avg',    AggregateConfigurationException::class, '/Listener aggregates do not support weighted_avg/'];
        yield 'bool_or' => ['op_bool_or',         AggregateConfigurationException::class, '/Listener aggregates do not support bool_or/'];
        yield 'bool_and' => ['op_bool_and',        AggregateConfigurationException::class, '/Listener aggregates do not support bool_and/'];
        yield 'distinct_count' => ['op_distinct_count',  AggregateConfigurationException::class, '/Listener aggregates do not support distinct_count/'];
        yield 'string_agg' => ['op_string_agg',      AggregateConfigurationException::class, '/Listener aggregates do not support string_agg/'];
        yield 'json_agg' => ['op_json_agg',        AggregateConfigurationException::class, '/Listener aggregates do not support json_agg/'];
        yield 'json_object_agg' => ['op_json_object_agg', AggregateConfigurationException::class, '/Listener aggregates do not support json_object_agg/'];
        yield 'median' => ['op_median',          AggregateConfigurationException::class, '/Listener aggregates do not support median/'];
        yield 'percentile' => ['op_percentile',      AggregateConfigurationException::class, '/Listener aggregates do not support percentile/'];
    }

    /**
     * @param  class-string<Throwable>  $exceptionClass
     */
    #[DataProvider('unsupportedOpColumns')]
    #[Test]
    public function fresh_read_of_unsupported_listener_op_throws(
        string $column,
        string $exceptionClass,
        string $messagePattern,
    ): void {
        $node = UnsupportedOpMonster::query()->findOrFail($this->seedMonsterRoot());

        $this->expectException($exceptionClass);
        $this->expectExceptionMessageMatches($messagePattern);

        $node->freshAggregate($column);
    }

    /**
     * Collection / quantile / derived arm: message must redirect to
     * the column-based attribute. Pins the additional Concat mutants
     * on the multi-part message line.
     */
    #[Test]
    public function collection_arm_message_points_at_nested_set_aggregate(): void
    {
        $node = UnsupportedOpMonster::query()->findOrFail($this->seedMonsterRoot());

        try {
            $node->freshAggregate('op_median');
            $this->fail('expected AggregateConfigurationException');
        } catch (AggregateConfigurationException $e) {
            $this->assertStringContainsString('#[NestedSetAggregate]', $e->getMessage());
            $this->assertStringContainsString('column-based', $e->getMessage());
        }
    }
}
