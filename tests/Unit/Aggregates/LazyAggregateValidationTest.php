<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\ListenerAggregate;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\DoubleTicketsListener;

/**
 * Pure-PHP validation of the lazy / TTL declaration shape — attribute,
 * fluent factory, and direct definition construction. Companion paths
 * that need a database live in the feature LazyAggregateTest.
 */
final class LazyAggregateValidationTest extends TestCase
{
    // ----------------------------------------------------------------
    // Allowed shapes round-trip.
    // ----------------------------------------------------------------

    public function test_lazy_sum_attribute_round_trips_to_definition(): void
    {
        $attr = new NestedSetAggregate(
            column: 'lazy_total',
            sum: 'tickets',
            lazy: true,
            ttl: 60,
        );

        $def = $attr->toDefinition();

        $this->assertTrue($def->lazy);
        $this->assertSame(60, $def->ttl);
        $this->assertSame('lazy_total_computed_at', $def->lazyStampColumn());
        $this->assertSame(AggregateFunction::Sum, $def->function);
    }

    public function test_lazy_fluent_round_trips_to_definition(): void
    {
        $def = Aggregate::count()->lazy(120)->into('lazy_count');

        $this->assertTrue($def->lazy);
        $this->assertSame(120, $def->ttl);
        $this->assertTrue($def->isLazy());
        $this->assertSame(120, $def->lazyTtlSeconds());
    }

    public function test_lazy_fluent_without_ttl_is_null_ttl(): void
    {
        $def = Aggregate::sum('tickets')->lazy()->into('lazy_total');

        $this->assertTrue($def->lazy);
        $this->assertNull($def->ttl);
    }

    public function test_listener_lazy_attribute_round_trips(): void
    {
        $attr = new NestedSetAggregateListener(
            column: 'lazy_listener_sum',
            listener: DoubleTicketsListener::class,
            operation: AggregateFunction::Sum,
            lazy: true,
            ttl: 30,
        );

        $def = $attr->toDefinition();

        $this->assertTrue($def->lazy);
        $this->assertSame(30, $def->ttl);
    }

    public function test_listener_lazy_fluent_round_trips(): void
    {
        $def = ListenerAggregate::sum(DoubleTicketsListener::class)
            ->lazy()
            ->into('lazy_listener_sum');

        $this->assertTrue($def->lazy);
        $this->assertNull($def->ttl);
    }

    // ----------------------------------------------------------------
    // AggregateFunction::supportsLazy() matrix.
    // ----------------------------------------------------------------

    public function test_supports_lazy_returns_true_for_simple_kinds(): void
    {
        foreach ([
            AggregateFunction::Sum, AggregateFunction::Count,
            AggregateFunction::Min, AggregateFunction::Max,
            AggregateFunction::BitOr, AggregateFunction::BitAnd, AggregateFunction::BitXor,
            AggregateFunction::DistinctCount, AggregateFunction::StringAgg,
            AggregateFunction::JsonAgg, AggregateFunction::JsonObjectAgg,
        ] as $function) {
            $this->assertTrue($function->supportsLazy(), $function->value.' should support lazy');
        }
    }

    public function test_supports_lazy_returns_false_for_companion_derived_kinds(): void
    {
        foreach ([
            AggregateFunction::Avg, AggregateFunction::Variance, AggregateFunction::Stddev,
            AggregateFunction::WeightedAvg, AggregateFunction::BoolOr, AggregateFunction::BoolAnd,
            AggregateFunction::GeometricMean, AggregateFunction::HarmonicMean,
            AggregateFunction::Median, AggregateFunction::Percentile,
        ] as $function) {
            $this->assertFalse($function->supportsLazy(), $function->value.' should not support lazy');
        }
    }

    // ----------------------------------------------------------------
    // Validation: lazy on disallowed function throws.
    // ----------------------------------------------------------------

    public function test_lazy_on_avg_throws_at_definition_build_via_attribute(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessageMatches('/lazy is not supported on avg/');

        (new NestedSetAggregate(column: 'avg_col', avg: 'tickets', lazy: true))
            ->toDefinition();
    }

    public function test_lazy_on_variance_throws_at_definition_build_via_attribute(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessageMatches('/lazy is not supported on variance/');

        (new NestedSetAggregate(column: 'var_col', variance: 'tickets', lazy: true))
            ->toDefinition();
    }

    public function test_lazy_on_listener_avg_throws_at_construction(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessageMatches('/Sum, Count, Min, Max/');

        new ListenerAggregateDefinition(
            column: 'avg_listener',
            listenerClass: DoubleTicketsListener::class,
            operation: AggregateFunction::Avg,
            lazy: true,
        );
    }

    public function test_ttl_without_lazy_throws(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessageMatches('/`ttl` only applies when `lazy: true`/');

        new AggregateDefinition(
            column: 'col',
            function: AggregateFunction::Sum,
            source: 'tickets',
            inclusive: true,
            ttl: 60,
        );
    }

    public function test_zero_ttl_throws(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessageMatches('/`ttl` must be a positive integer/');

        new AggregateDefinition(
            column: 'col',
            function: AggregateFunction::Sum,
            source: 'tickets',
            inclusive: true,
            lazy: true,
            ttl: 0,
        );
    }

    public function test_negative_ttl_throws(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessageMatches('/`ttl` must be a positive integer/');

        new AggregateDefinition(
            column: 'col',
            function: AggregateFunction::Sum,
            source: 'tickets',
            inclusive: true,
            lazy: true,
            ttl: -1,
        );
    }

    public function test_internal_companion_cannot_be_lazy(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessageMatches('/auto-promoted internal companion cannot be lazy/');

        new AggregateDefinition(
            column: 'col__sum',
            function: AggregateFunction::Sum,
            source: 'tickets',
            inclusive: true,
            internal: true,
            lazy: true,
        );
    }
}
