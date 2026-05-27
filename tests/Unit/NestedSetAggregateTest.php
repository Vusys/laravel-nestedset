<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use Attribute;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicateKind;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

final class NestedSetAggregateTest extends TestCase
{
    public function test_sum_declaration_produces_a_sum_definition(): void
    {
        $definition = (new NestedSetAggregate(column: 'tickets_total', sum: 'tickets'))
            ->toDefinition();

        $this->assertSame('tickets_total', $definition->column);
        $this->assertSame(AggregateFunction::Sum, $definition->function);
        $this->assertSame('tickets', $definition->source);
        $this->assertTrue($definition->inclusive);
    }

    public function test_count_true_produces_a_count_star_definition_with_null_source(): void
    {
        $definition = (new NestedSetAggregate(column: 'tickets_count', count: true))
            ->toDefinition();

        $this->assertSame(AggregateFunction::Count, $definition->function);
        $this->assertNull($definition->source);
    }

    public function test_avg_declaration_produces_an_avg_definition(): void
    {
        $definition = (new NestedSetAggregate(column: 'tickets_avg', avg: 'tickets'))
            ->toDefinition();

        $this->assertSame(AggregateFunction::Avg, $definition->function);
        $this->assertSame('tickets', $definition->source);
    }

    public function test_min_declaration_produces_a_min_definition(): void
    {
        $definition = (new NestedSetAggregate(column: 'tickets_min', min: 'tickets'))
            ->toDefinition();

        $this->assertSame(AggregateFunction::Min, $definition->function);
        $this->assertSame('tickets', $definition->source);
    }

    public function test_max_declaration_produces_a_max_definition(): void
    {
        $definition = (new NestedSetAggregate(column: 'tickets_max', max: 'tickets'))
            ->toDefinition();

        $this->assertSame(AggregateFunction::Max, $definition->function);
        $this->assertSame('tickets', $definition->source);
    }

    public function test_variance_declaration_produces_a_variance_definition(): void
    {
        $definition = (new NestedSetAggregate(column: 'tickets_var', variance: 'tickets'))
            ->toDefinition();

        $this->assertSame(AggregateFunction::Variance, $definition->function);
        $this->assertSame('tickets', $definition->source);
        $this->assertFalse($definition->sample, 'default is population variance');
    }

    public function test_stddev_declaration_with_sample_flag(): void
    {
        $definition = (new NestedSetAggregate(
            column: 'tickets_std_samp',
            stddev: 'tickets',
            sample: true,
        ))->toDefinition();

        $this->assertSame(AggregateFunction::Stddev, $definition->function);
        $this->assertSame('tickets', $definition->source);
        $this->assertTrue($definition->sample);
    }

    public function test_sample_flag_is_rejected_on_non_variance_kinds(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('`sample: true` is only valid on variance/stddev');

        (new NestedSetAggregate(
            column: 'tickets_avg',
            avg: 'tickets',
            sample: true,
        ))->toDefinition();
    }

    public function test_bit_or_declaration_produces_a_bit_or_definition(): void
    {
        $definition = (new NestedSetAggregate(column: 'features_or', bitOr: 'feature_bits'))
            ->toDefinition();

        $this->assertSame(AggregateFunction::BitOr, $definition->function);
        $this->assertSame('feature_bits', $definition->source);
    }

    public function test_bit_and_declaration_produces_a_bit_and_definition(): void
    {
        $definition = (new NestedSetAggregate(column: 'features_and', bitAnd: 'feature_bits'))
            ->toDefinition();

        $this->assertSame(AggregateFunction::BitAnd, $definition->function);
        $this->assertSame('feature_bits', $definition->source);
    }

    public function test_bit_xor_declaration_produces_a_bit_xor_definition(): void
    {
        $definition = (new NestedSetAggregate(column: 'features_xor', bitXor: 'feature_bits'))
            ->toDefinition();

        $this->assertSame(AggregateFunction::BitXor, $definition->function);
        $this->assertSame('feature_bits', $definition->source);
    }

    public function test_exclusive_flag_propagates_to_definition(): void
    {
        $definition = (new NestedSetAggregate(
            column: 'descendants_total',
            sum: 'tickets',
            exclusive: true,
        ))->toDefinition();

        $this->assertFalse($definition->inclusive);
    }

    public function test_rejects_declaration_with_no_function(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('no aggregate function declared');

        (new NestedSetAggregate(column: 'tickets_total'))->toDefinition();
    }

    public function test_rejects_declaration_with_two_functions_at_once(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        // The message must list the *function names* that were declared
        // (`sum`, `max`) so the user can find the conflict, not the
        // source columns / values that were passed alongside them. This
        // assertion guards `implode(', ', array_keys($declared))` —
        // dropping the `array_keys` wrapper would join the values
        // (here both 'tickets') instead.
        //
        // The regex anchors inside the parenthesised list because the
        // trailing static text ("Each declaration must use exactly one
        // of sum, count, avg, min, max.") also contains "sum" and
        // "max" — without the `\(...\)` anchor a substring-style match
        // would still pass when the dynamic list is wrong.
        $this->expectExceptionMessageMatches('/\((sum, max|max, sum)\)/');

        (new NestedSetAggregate(
            column: 'tickets_total',
            sum: 'tickets',
            max: 'tickets',
        ))->toDefinition();
    }

    public function test_rejects_declaration_with_three_functions_at_once(): void
    {
        $this->expectException(AggregateConfigurationException::class);

        (new NestedSetAggregate(
            column: 'tickets_total',
            sum: 'tickets',
            count: true,
            avg: 'tickets',
        ))->toDefinition();
    }

    public function test_rejects_empty_column_name(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('`column` must not be empty');

        (new NestedSetAggregate(column: '', sum: 'tickets'))->toDefinition();
    }

    public function test_is_declared_as_a_repeatable_class_level_attribute(): void
    {
        $reflection = new ReflectionClass(NestedSetAggregate::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        $this->assertCount(1, $attributes);

        /** @var Attribute $attr */
        $attr = $attributes[0]->newInstance();

        $this->assertSame(
            Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE,
            $attr->flags,
        );
    }

    public function test_filter_param_produces_equality_predicate_on_definition(): void
    {
        $definition = (new NestedSetAggregate(
            column: 'tickets_total',
            sum: 'tickets',
            filter: ['type' => 'fire'],
        ))->toDefinition();

        $this->assertNotNull($definition->filter);
        $this->assertSame(FilterPredicateKind::Equality, $definition->filter->getKind());
        $this->assertSame(['type' => 'fire'], $definition->filter->getConditions());
    }

    public function test_filter_not_null_param_produces_not_null_predicate_on_definition(): void
    {
        $definition = (new NestedSetAggregate(
            column: 'tickets_total',
            sum: 'tickets',
            filterNotNull: 'deleted_at',
        ))->toDefinition();

        $this->assertNotNull($definition->filter);
        $this->assertSame(FilterPredicateKind::NotNull, $definition->filter->getKind());
        $this->assertSame('deleted_at', $definition->filter->getNotNullColumn());
    }

    public function test_filter_raw_param_produces_raw_predicate_on_definition(): void
    {
        $definition = (new NestedSetAggregate(
            column: 'tickets_total',
            sum: 'tickets',
            filterRaw: 'status = 1',
            filterRawWatches: ['status'],
        ))->toDefinition();

        $this->assertNotNull($definition->filter);
        $this->assertSame(FilterPredicateKind::Raw, $definition->filter->getKind());
        $this->assertSame('status = 1', $definition->filter->getRawSql());
        $this->assertSame(['status'], $definition->filter->watchColumns());
    }

    public function test_multiple_filter_params_throws(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('at most one filter form');

        (new NestedSetAggregate(
            column: 'tickets_total',
            sum: 'tickets',
            filter: ['type' => 'fire'],
            filterNotNull: 'deleted_at',
        ))->toDefinition();
    }

    public function test_filter_raw_without_watches_throws(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('filterRawWatches');

        (new NestedSetAggregate(
            column: 'tickets_total',
            sum: 'tickets',
            filterRaw: 'status = 1',
        ))->toDefinition();
    }

    public function test_filter_raw_with_explicit_no_column_dependencies_flag_is_allowed_with_empty_watches(): void
    {
        $definition = (new NestedSetAggregate(
            column: 'tickets_total',
            sum: 'tickets',
            filterRaw: '1 = 1',
            filterRawNoColumnDependencies: true,
        ))->toDefinition();

        $this->assertNotNull($definition->filter);
        $this->assertSame(FilterPredicateKind::Raw, $definition->filter->getKind());
        $this->assertSame([], $definition->filter->watchColumns());
    }

    public function test_definition_has_no_filter_when_none_declared(): void
    {
        $definition = (new NestedSetAggregate(
            column: 'tickets_total',
            sum: 'tickets',
        ))->toDefinition();

        $this->assertNull($definition->filter);
    }
}
