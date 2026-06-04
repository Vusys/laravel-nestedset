<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Attributes;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

final class NestedSetAggregateCollectionKindsTest extends TestCase
{
    public function test_distinct_count_produces_a_distinct_count_definition(): void
    {
        $def = (new NestedSetAggregate(
            column: 'distinct_owners',
            distinctCount: 'owner_id',
        ))->toDefinition();

        $this->assertSame(AggregateFunction::DistinctCount, $def->function);
        $this->assertSame('owner_id', $def->source);
        $this->assertTrue($def->inclusive);
    }

    public function test_string_agg_with_separator_limit_and_order_by(): void
    {
        $def = (new NestedSetAggregate(
            column: 'child_names',
            stringAgg: 'name',
            separator: '; ',
            limit: 20,
            orderBy: 'name',
        ))->toDefinition();

        $this->assertSame(AggregateFunction::StringAgg, $def->function);
        $this->assertSame('name', $def->source);
        $this->assertSame('; ', $def->separator);
        $this->assertSame(20, $def->limit);
        $this->assertSame('name', $def->orderBy);
        $this->assertFalse($def->distinct);
    }

    public function test_string_agg_distinct_flag_lights_distinct_in_definition(): void
    {
        $def = (new NestedSetAggregate(
            column: 'distinct_tags',
            stringAgg: 'tag',
            distinct: true,
        ))->toDefinition();

        $this->assertTrue($def->distinct);
        $this->assertSame('tag', $def->orderBy);
    }

    public function test_string_agg_distinct_with_custom_order_by_rejects(): void
    {
        try {
            (new NestedSetAggregate(
                column: 'distinct_tags',
                stringAgg: 'tag',
                orderBy: 'created_at',
                distinct: true,
            ))->toDefinition();
            $this->fail('expected AggregateConfigurationException');
        } catch (AggregateConfigurationException $e) {
            // Contiguous across all three operands of the message, so any
            // dropped or reordered literal — including the parenthetical
            // rationale — fails the assertion.
            $this->assertStringContainsString(
                'stringAgg with distinct: true requires orderBy to match the source column '
                .'(PG only accepts ORDER BY columns that appear in the DISTINCT set; the package '
                .'enforces this across backends).',
                $e->getMessage(),
            );
        }
    }

    public function test_json_agg_scalar_form(): void
    {
        $def = (new NestedSetAggregate(
            column: 'descendant_ids',
            jsonAgg: 'id',
        ))->toDefinition();

        $this->assertSame(AggregateFunction::JsonAgg, $def->function);
        $this->assertSame('id', $def->source);
        $this->assertSame([], $def->sources);
        $this->assertSame('id', $def->orderBy);
    }

    public function test_json_agg_list_form(): void
    {
        $def = (new NestedSetAggregate(
            column: 'summary',
            jsonAgg: ['id', 'name'],
        ))->toDefinition();

        $this->assertNull($def->source);
        $this->assertSame(['id' => 'id', 'name' => 'name'], $def->sources);
    }

    public function test_json_agg_assoc_form(): void
    {
        $def = (new NestedSetAggregate(
            column: 'summary',
            jsonAgg: ['nodeId' => 'id', 'label' => 'name'],
        ))->toDefinition();

        $this->assertSame(['nodeId' => 'id', 'label' => 'name'], $def->sources);
    }

    public function test_json_agg_rejects_duplicate_list_keys(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        (new NestedSetAggregate(
            column: 'summary',
            jsonAgg: ['name', 'name'],
        ))->toDefinition();
    }

    public function test_json_agg_rejects_empty_string_key(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        (new NestedSetAggregate(
            column: 'summary',
            jsonAgg: ['' => 'id'],
        ))->toDefinition();
    }

    public function test_json_agg_rejects_empty_source_array(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        (new NestedSetAggregate(
            column: 'summary',
            jsonAgg: [],
        ))->toDefinition();
    }

    public function test_json_object_agg_definition(): void
    {
        $def = (new NestedSetAggregate(
            column: 'slug_to_name',
            jsonObjectAgg: ['key' => 'slug', 'value' => 'name'],
        ))->toDefinition();

        $this->assertSame(AggregateFunction::JsonObjectAgg, $def->function);
        $this->assertNull($def->source);
        $this->assertSame('slug', $def->keyColumn);
        $this->assertSame('name', $def->valueColumn);
        $this->assertSame('slug', $def->orderBy);
        $this->assertFalse($def->allowNullKeys);
    }

    public function test_json_object_agg_allow_null_keys(): void
    {
        $def = (new NestedSetAggregate(
            column: 'slug_to_name',
            jsonObjectAgg: ['key' => 'slug', 'value' => 'name'],
            allowNullKeys: true,
        ))->toDefinition();

        $this->assertTrue($def->allowNullKeys);
    }

    public function test_json_object_agg_rejects_missing_key_or_value(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage("requires `['key' => …, 'value' => …]`");

        // Pass a typed-correct array that lacks the required 'value' offset
        // at runtime, to exercise the guard inside jsonObjectAggDefinition().
        $bad = ['key' => 'slug'];
        (new NestedSetAggregate(
            column: 'lookup',
            jsonObjectAgg: $bad,
        ))->toDefinition();
    }

    public function test_rejects_two_function_declarations(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('multiple aggregate functions declared');

        (new NestedSetAggregate(
            column: 'broken',
            sum: 'x',
            stringAgg: 'y',
        ))->toDefinition();
    }

    public function test_no_function_declared_rejects(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        (new NestedSetAggregate(column: 'broken'))->toDefinition();
    }

    public function test_negative_limit_rejected_on_string_agg(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        (new NestedSetAggregate(
            column: 'x',
            stringAgg: 'name',
            limit: -1,
        ))->toDefinition();
    }

    public function test_negative_limit_rejected_on_json_agg(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        (new NestedSetAggregate(
            column: 'x',
            jsonAgg: 'name',
            limit: -1,
        ))->toDefinition();
    }
}
