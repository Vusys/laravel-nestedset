<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Listeners;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;
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
        // LogicException arm — Variance/Stddev redirect to the SQL aggregate.
        yield 'variance' => ['op_variance',        LogicException::class,                  '/Variance \/ Stddev .* SQL aggregate/'];
        yield 'stddev' => ['op_stddev',          LogicException::class,                  '/Variance \/ Stddev .* SQL aggregate/'];

        // AggregateConfigurationException arm — collection / quantile / derived ops.
        yield 'weighted_avg' => ['op_weighted_avg',    AggregateConfigurationException::class, '/Listener aggregates do not support weighted_avg/'];
        yield 'bool_or' => ['op_bool_or',         AggregateConfigurationException::class, '/Listener aggregates do not support bool_or/'];
        yield 'bool_and' => ['op_bool_and',        AggregateConfigurationException::class, '/Listener aggregates do not support bool_and/'];
        yield 'geometric_mean' => ['op_geometric_mean',  AggregateConfigurationException::class, '/Listener aggregates do not support geometric_mean/'];
        yield 'harmonic_mean' => ['op_harmonic_mean',   AggregateConfigurationException::class, '/Listener aggregates do not support harmonic_mean/'];
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
    public function test_fresh_read_of_unsupported_listener_op_throws(
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
     * Variance-arm message pins the SQL-alternative hint. Stddev shares
     * the arm, so the same details apply.
     */
    public function test_variance_arm_message_mentions_the_sql_alternative(): void
    {
        $node = UnsupportedOpMonster::query()->findOrFail($this->seedMonsterRoot());

        try {
            $node->freshAggregate('op_variance');
            $this->fail('expected LogicException');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Aggregate::variance / ::stddev', $e->getMessage());
            $this->assertStringContainsString('maintain Sum + Count manually', $e->getMessage());
        }
    }

    /**
     * Collection / quantile / derived arm: message must redirect to
     * the column-based attribute. Pins the additional Concat mutants
     * on the multi-part message line.
     */
    public function test_collection_arm_message_points_at_nested_set_aggregate(): void
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
