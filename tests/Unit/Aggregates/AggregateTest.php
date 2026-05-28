<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates;

use Closure;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Expression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicateKind;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

final class AggregateTest extends TestCase
{
    /**
     * Every scalar factory captures its function and source column (and,
     * for weighted average, its weight column), and starts inclusive.
     *
     * @param  Closure(): Aggregate  $factory
     */
    #[DataProvider('factoryCases')]
    public function test_factory_captures_function_source_and_weight(
        Closure $factory,
        AggregateFunction $expectedFunction,
        ?string $expectedSource,
        ?string $expectedWeight,
    ): void {
        $aggregate = $factory();

        $this->assertSame($expectedFunction, $aggregate->function);
        $this->assertSame($expectedSource, $aggregate->source);
        $this->assertSame($expectedWeight, $aggregate->weight);
        $this->assertTrue($aggregate->inclusive, 'every factory starts inclusive');
    }

    /**
     * @return iterable<string, array{0: Closure(): Aggregate, 1: AggregateFunction, 2: ?string, 3: ?string}>
     */
    public static function factoryCases(): iterable
    {
        yield 'sum' => [fn (): Aggregate => Aggregate::sum('tickets'), AggregateFunction::Sum, 'tickets', null];
        yield 'count(*)' => [fn (): Aggregate => Aggregate::count(), AggregateFunction::Count, null, null];
        yield 'count(column)' => [fn (): Aggregate => Aggregate::count('tickets'), AggregateFunction::Count, 'tickets', null];
        yield 'avg' => [fn (): Aggregate => Aggregate::avg('tickets'), AggregateFunction::Avg, 'tickets', null];
        yield 'min' => [fn (): Aggregate => Aggregate::min('tickets'), AggregateFunction::Min, 'tickets', null];
        yield 'max' => [fn (): Aggregate => Aggregate::max('tickets'), AggregateFunction::Max, 'tickets', null];
        yield 'variance' => [fn (): Aggregate => Aggregate::variance('tickets'), AggregateFunction::Variance, 'tickets', null];
        yield 'stddev' => [fn (): Aggregate => Aggregate::stddev('tickets'), AggregateFunction::Stddev, 'tickets', null];
        yield 'bit_or' => [fn (): Aggregate => Aggregate::bitOr('feature_bits'), AggregateFunction::BitOr, 'feature_bits', null];
        yield 'bit_and' => [fn (): Aggregate => Aggregate::bitAnd('feature_bits'), AggregateFunction::BitAnd, 'feature_bits', null];
        yield 'bit_xor' => [fn (): Aggregate => Aggregate::bitXor('feature_bits'), AggregateFunction::BitXor, 'feature_bits', null];
        yield 'bool_or' => [fn (): Aggregate => Aggregate::boolOr('flag'), AggregateFunction::BoolOr, 'flag', null];
        yield 'bool_and' => [fn (): Aggregate => Aggregate::boolAnd('flag'), AggregateFunction::BoolAnd, 'flag', null];
        yield 'geometric_mean' => [fn (): Aggregate => Aggregate::geometricMean('rate'), AggregateFunction::GeometricMean, 'rate', null];
        yield 'harmonic_mean' => [fn (): Aggregate => Aggregate::harmonicMean('rate'), AggregateFunction::HarmonicMean, 'rate', null];
        yield 'median' => [fn (): Aggregate => Aggregate::median('price'), AggregateFunction::Median, 'price', null];
        yield 'percentile' => [fn (): Aggregate => Aggregate::percentile('price', 0.9), AggregateFunction::Percentile, 'price', null];
        yield 'weighted_avg' => [fn (): Aggregate => Aggregate::weightedAvg('score', 'weight'), AggregateFunction::WeightedAvg, 'score', 'weight'];
    }

    /**
     * Each factory rejects malformed configuration with a descriptive
     * {@see AggregateConfigurationException} rather than constructing a
     * silently-broken declaration.
     *
     * @param  Closure(): mixed  $call
     */
    #[DataProvider('factoryValidationCases')]
    public function test_factory_rejects_invalid_configuration(Closure $call, string $expectedMessageFragment): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage($expectedMessageFragment);

        $call();
    }

    /**
     * @return iterable<string, array{0: Closure(): mixed, 1: string}>
     */
    public static function factoryValidationCases(): iterable
    {
        yield 'weighted_avg empty value' => [fn (): Aggregate => Aggregate::weightedAvg('', 'w'), 'value column must not be empty'];
        yield 'weighted_avg empty weight' => [fn (): Aggregate => Aggregate::weightedAvg('x', ''), 'weight column must not be empty'];
        yield 'weighted_avg identical columns' => [fn (): Aggregate => Aggregate::weightedAvg('x', 'x'), 'value and weight columns must differ'];
        yield 'bool_or empty source' => [fn (): Aggregate => Aggregate::boolOr(''), 'source column must not be empty'];
        yield 'bool_and empty source' => [fn (): Aggregate => Aggregate::boolAnd(''), 'source column must not be empty'];
        yield 'geometric_mean empty source' => [fn (): Aggregate => Aggregate::geometricMean(''), 'source column must not be empty'];
        yield 'harmonic_mean empty source' => [fn (): Aggregate => Aggregate::harmonicMean(''), 'source column must not be empty'];
        yield 'median empty source' => [fn (): Aggregate => Aggregate::median(''), 'source column must not be empty'];
        yield 'percentile empty source' => [fn (): Aggregate => Aggregate::percentile('', 0.5), 'source column must not be empty'];
        yield 'percentile point below range' => [fn (): Aggregate => Aggregate::percentile('x', -0.1), 'percentile point must be in [0.0, 1.0]'];
        yield 'percentile point above range' => [fn (): Aggregate => Aggregate::percentile('x', 1.5), 'percentile point must be in [0.0, 1.0]'];
        yield 'json_agg empty source' => [fn (): Aggregate => Aggregate::jsonAgg(''), 'source column must not be empty'];
        yield 'json_agg negative limit' => [fn (): Aggregate => Aggregate::jsonAgg('x', limit: -1), 'limit must be >= 0'];
        yield 'json_object_agg empty value' => [fn (): Aggregate => Aggregate::jsonObjectAgg('k', ''), 'value column must not be empty'];
        yield 'json_object_agg negative limit' => [fn (): Aggregate => Aggregate::jsonObjectAgg('k', 'v', limit: -1), 'limit must be >= 0'];
    }

    public function test_variance_factory_defaults_to_population(): void
    {
        $aggregate = Aggregate::variance('tickets');

        $this->assertFalse($aggregate->sample, 'variance() default is population variance');
    }

    public function test_variance_factory_with_sample_flag(): void
    {
        $this->assertTrue(Aggregate::variance('tickets', sample: true)->sample);
    }

    public function test_stddev_factory_defaults_to_population(): void
    {
        $this->assertFalse(Aggregate::stddev('tickets')->sample);
    }

    public function test_stddev_factory_with_sample_flag(): void
    {
        $this->assertTrue(Aggregate::stddev('tickets', sample: true)->sample);
    }

    public function test_sample_flag_persists_across_modifier_calls(): void
    {
        $aggregate = Aggregate::stddev('tickets', sample: true)
            ->exclusive()
            ->filter(['type' => 'fire']);

        $this->assertTrue($aggregate->sample);
        $this->assertFalse($aggregate->inclusive);
    }

    public function test_into_propagates_sample_flag_to_definition(): void
    {
        $definition = Aggregate::stddev('tickets', sample: true)->into('tickets_stddev');

        $this->assertTrue($definition->sample);
    }

    public function test_allow_non_positive_sets_the_flag_and_preserves_the_rest(): void
    {
        $base = Aggregate::geometricMean('rate')->exclusive();
        $relaxed = $base->allowNonPositive();

        $this->assertFalse($base->allowNonPositive, 'modifier returns a new instance, leaving the original alone');
        $this->assertTrue($relaxed->allowNonPositive);
        $this->assertSame(AggregateFunction::GeometricMean, $relaxed->function);
        $this->assertSame('rate', $relaxed->source);
        $this->assertFalse($relaxed->inclusive, 'allowNonPositive() preserves the exclusive flag');
    }

    public function test_exclusive_modifier_flips_the_inclusive_flag(): void
    {
        $aggregate = Aggregate::sum('tickets')->exclusive();

        $this->assertFalse($aggregate->inclusive);
    }

    public function test_inclusive_modifier_restores_the_default(): void
    {
        $aggregate = Aggregate::sum('tickets')->exclusive()->inclusive();

        $this->assertTrue($aggregate->inclusive);
    }

    public function test_modifiers_return_new_instances(): void
    {
        $base = Aggregate::sum('tickets');
        $exclusive = $base->exclusive();

        $this->assertNotSame($base, $exclusive);
        $this->assertTrue($base->inclusive);
        $this->assertFalse($exclusive->inclusive);
    }

    public function test_into_produces_a_definition_with_all_fields(): void
    {
        $definition = Aggregate::sum('tickets')->into('tickets_total');

        $this->assertSame('tickets_total', $definition->column);
        $this->assertSame(AggregateFunction::Sum, $definition->function);
        $this->assertSame('tickets', $definition->source);
        $this->assertTrue($definition->inclusive);
        $this->assertFalse($definition->isInternal());
    }

    public function test_into_carries_exclusive_flag_into_the_definition(): void
    {
        $definition = Aggregate::sum('tickets')
            ->exclusive()
            ->into('descendants_total');

        $this->assertFalse($definition->inclusive);
    }

    public function test_into_rejects_empty_column_name(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('must not be empty');

        Aggregate::sum('tickets')->into('');
    }

    public function test_filter_is_null_by_default(): void
    {
        $this->assertNull(Aggregate::sum('tickets')->filter);
    }

    public function test_filter_method_sets_equality_predicate(): void
    {
        $aggregate = Aggregate::sum('tickets')->filter(['type' => 'fire']);

        $this->assertNotNull($aggregate->filter);
        $this->assertSame(FilterPredicateKind::Equality, $aggregate->filter->getKind());
        $this->assertSame(['type' => 'fire'], $aggregate->filter->getConditions());
    }

    public function test_filter_not_null_method_sets_not_null_predicate(): void
    {
        $aggregate = Aggregate::sum('tickets')->filterNotNull('deleted_at');

        $this->assertNotNull($aggregate->filter);
        $this->assertSame(FilterPredicateKind::NotNull, $aggregate->filter->getKind());
    }

    public function test_filter_raw_method_sets_raw_predicate(): void
    {
        $aggregate = Aggregate::sum('tickets')->filterRaw('status = 1', ['status']);

        $this->assertNotNull($aggregate->filter);
        $this->assertSame(FilterPredicateKind::Raw, $aggregate->filter->getKind());
        $this->assertSame('status = 1', $aggregate->filter->getRawSql());
        $this->assertSame(['status'], $aggregate->filter->watchColumns());
    }

    public function test_filter_raw_accepts_db_raw_expression(): void
    {
        // DB::raw() returns a Laravel Expression — the package extracts
        // the underlying SQL string via reflection (Expression::getValue
        // requires a Grammar instance the fluent call site doesn't have).
        $expr = new Expression('status = 1');
        $aggregate = Aggregate::sum('tickets')->filterRaw($expr, ['status']);

        $this->assertNotNull($aggregate->filter);
        $this->assertSame(FilterPredicateKind::Raw, $aggregate->filter->getKind());
        $this->assertSame('status = 1', $aggregate->filter->getRawSql());
        $this->assertSame(['status'], $aggregate->filter->watchColumns());
    }

    public function test_filter_raw_rejects_an_expression_without_a_scalar_value(): void
    {
        // expressionToString() reads the Expression's underlying `value`
        // via reflection. A malformed Expression whose value is non-scalar
        // must be rejected rather than coerced into a broken SQL string.
        $weird = new class implements ExpressionContract
        {
            /** @var list<int> */
            public array $value = [1, 2, 3];

            public function getValue(Grammar $grammar): string
            {
                return '';
            }
        };

        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('did not expose a readable scalar');

        Aggregate::sum('tickets')->filterRaw($weird, ['status']);
    }

    public function test_filter_modifier_returns_new_instance(): void
    {
        $base = Aggregate::sum('tickets');
        $filtered = $base->filter(['type' => 'fire']);

        $this->assertNotSame($base, $filtered);
        $this->assertNull($base->filter);
        $this->assertNotNull($filtered->filter);
    }

    public function test_into_carries_filter_to_definition(): void
    {
        $definition = Aggregate::sum('tickets')
            ->filter(['type' => 'fire'])
            ->into('tickets_total');

        $this->assertNotNull($definition->filter);
        $this->assertSame(FilterPredicateKind::Equality, $definition->filter->getKind());
    }

    public function test_exclusive_preserves_filter(): void
    {
        $aggregate = Aggregate::sum('tickets')
            ->filter(['type' => 'fire'])
            ->exclusive();

        $this->assertNotNull($aggregate->filter);
        $this->assertSame(FilterPredicateKind::Equality, $aggregate->filter->getKind());
        $this->assertFalse($aggregate->inclusive);
    }
}
