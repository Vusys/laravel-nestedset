<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates\Listeners;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Listeners\ListenerMaintenance;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Tests\Feature\Aggregates\Listeners\UnsupportedListenerOperationTest;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\WeightedPowerListener;

/**
 * `ListenerMaintenance::applyListenerOperation()` and the matching
 * match-arm in `HasNestedSetAggregates::recomputeListenerFromTree()`
 * both reject every {@see AggregateFunction} that the PHP listener
 * path can't compute. Each rejection arm has its own user-facing
 * exception class and message — Variance/Stddev raise a
 * {@see LogicException} pointing at the SQL alternative, the
 * collection / quantile / derived ops raise an
 * {@see AggregateConfigurationException} pointing at
 * `#[NestedSetAggregate]`.
 *
 * Until this test existed only `Variance` and `Median` were exercised
 * (in {@see UnsupportedListenerOperationTest}),
 * leaving the other eleven listener-unsupported arms covered only by
 * the shared-arm clause — so dropping any one of the shared cases left
 * the remaining cases still throwing the same exception type and the
 * mutation passed. One row per operation in the data provider pins
 * the specific arm; `expectExceptionMessageMatches` over an arm-specific
 * needle pins the message-string Concat mutants on the same line.
 */
final class ListenerMaintenanceUnsupportedOpsTest extends TestCase
{
    /** @return iterable<string, array{0: AggregateFunction, 1: class-string<Throwable>, 2: string}> */
    public static function unsupportedOps(): iterable
    {
        // LogicException arm: variance + stddev share the throw. The
        // message points at the SQL alternative.
        yield 'variance' => [AggregateFunction::Variance,        LogicException::class,                  '/Variance \/ Stddev .* SQL aggregate/'];
        yield 'stddev' => [AggregateFunction::Stddev,          LogicException::class,                  '/Variance \/ Stddev .* SQL aggregate/'];

        // AggregateConfigurationException arm: every collection /
        // quantile / derived op. The message names the offending
        // function and points at #[NestedSetAggregate].
        yield 'weighted_avg' => [AggregateFunction::WeightedAvg,     AggregateConfigurationException::class, '/Listener aggregates do not support weighted_avg/'];
        yield 'bool_or' => [AggregateFunction::BoolOr,          AggregateConfigurationException::class, '/Listener aggregates do not support bool_or/'];
        yield 'bool_and' => [AggregateFunction::BoolAnd,         AggregateConfigurationException::class, '/Listener aggregates do not support bool_and/'];
        yield 'geometric_mean' => [AggregateFunction::GeometricMean,   AggregateConfigurationException::class, '/Listener aggregates do not support geometric_mean/'];
        yield 'harmonic_mean' => [AggregateFunction::HarmonicMean,    AggregateConfigurationException::class, '/Listener aggregates do not support harmonic_mean/'];
        yield 'distinct_count' => [AggregateFunction::DistinctCount,   AggregateConfigurationException::class, '/Listener aggregates do not support distinct_count/'];
        yield 'string_agg' => [AggregateFunction::StringAgg,       AggregateConfigurationException::class, '/Listener aggregates do not support string_agg/'];
        yield 'json_agg' => [AggregateFunction::JsonAgg,         AggregateConfigurationException::class, '/Listener aggregates do not support json_agg/'];
        yield 'json_object_agg' => [AggregateFunction::JsonObjectAgg,   AggregateConfigurationException::class, '/Listener aggregates do not support json_object_agg/'];
        yield 'median' => [AggregateFunction::Median,          AggregateConfigurationException::class, '/Listener aggregates do not support median/'];
        yield 'percentile' => [AggregateFunction::Percentile,      AggregateConfigurationException::class, '/Listener aggregates do not support percentile/'];
    }

    /**
     * @param  class-string<Throwable>  $exceptionClass
     */
    #[DataProvider('unsupportedOps')]
    public function test_apply_listener_operation_rejects_unsupported_op(
        AggregateFunction $op,
        string $exceptionClass,
        string $messagePattern,
    ): void {
        $def = new ListenerAggregateDefinition(
            column: 'whatever',
            listenerClass: WeightedPowerListener::class,
            operation: $op,
        );

        $this->expectException($exceptionClass);
        $this->expectExceptionMessageMatches($messagePattern);

        ListenerMaintenance::applyListenerOperation($def, [1, 2, 3]);
    }

    /**
     * Companion message details that the per-operation arm-specific
     * provider doesn't already pin — the SQL-alternative hint in the
     * Variance/Stddev arm, and the #[NestedSetAggregate] redirect in
     * the collection / quantile arm. Pins additional message Concat
     * mutants that would otherwise survive even with the arm-specific
     * tests.
     */
    public function test_variance_arm_message_mentions_the_sql_alternative(): void
    {
        $def = new ListenerAggregateDefinition(
            column: 'x',
            listenerClass: WeightedPowerListener::class,
            operation: AggregateFunction::Variance,
        );

        try {
            ListenerMaintenance::applyListenerOperation($def, []);
            $this->fail('expected LogicException');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Aggregate::variance / ::stddev', $e->getMessage());
            $this->assertStringContainsString('maintain Sum + Count manually', $e->getMessage());
        }
    }

    public function test_collection_arm_message_points_at_nested_set_aggregate(): void
    {
        $def = new ListenerAggregateDefinition(
            column: 'x',
            listenerClass: WeightedPowerListener::class,
            operation: AggregateFunction::Median,
        );

        try {
            ListenerMaintenance::applyListenerOperation($def, [1, 2, 3]);
            $this->fail('expected AggregateConfigurationException');
        } catch (AggregateConfigurationException $e) {
            $this->assertStringContainsString('#[NestedSetAggregate]', $e->getMessage());
            $this->assertStringContainsString('column-based', $e->getMessage());
        }
    }
}
