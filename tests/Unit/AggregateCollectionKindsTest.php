<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

final class AggregateCollectionKindsTest extends TestCase
{
    public function test_distinct_count_factory(): void
    {
        $agg = Aggregate::distinctCount('owner_id');

        $this->assertSame(AggregateFunction::DistinctCount, $agg->function);
        $this->assertSame('owner_id', $agg->source);
        $this->assertTrue($agg->inclusive);
    }

    public function test_distinct_count_rejects_empty_source(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        Aggregate::distinctCount('');
    }

    public function test_string_agg_captures_separator_limit_and_order_by(): void
    {
        $agg = Aggregate::stringAgg('name', separator: '; ', limit: 20, orderBy: 'name');

        $this->assertSame(AggregateFunction::StringAgg, $agg->function);
        $this->assertSame('name', $agg->source);
        $this->assertSame('; ', $agg->separator);
        $this->assertSame(20, $agg->limit);
        $this->assertSame('name', $agg->orderBy);
        $this->assertFalse($agg->distinct);
    }

    public function test_string_agg_defaults_order_by_to_source(): void
    {
        $agg = Aggregate::stringAgg('name');

        $this->assertSame('name', $agg->orderBy);
    }

    public function test_string_agg_distinct_modifier(): void
    {
        $agg = Aggregate::stringAgg('tag')->distinct();

        $this->assertTrue($agg->distinct);
    }

    public function test_string_agg_distinct_rejects_custom_order_by(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('orderBy is incompatible with distinct');

        Aggregate::stringAgg('tag', orderBy: 'created_at')->distinct();
    }

    public function test_distinct_modifier_only_works_on_string_agg(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        Aggregate::sum('tickets')->distinct();
    }

    public function test_string_agg_rejects_empty_source(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        Aggregate::stringAgg('');
    }

    public function test_string_agg_rejects_negative_limit(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        Aggregate::stringAgg('name', limit: -1);
    }

    public function test_json_agg_scalar_form(): void
    {
        $agg = Aggregate::jsonAgg('id');

        $this->assertSame(AggregateFunction::JsonAgg, $agg->function);
        $this->assertSame('id', $agg->source);
        $this->assertSame([], $agg->sources);
        $this->assertSame('id', $agg->orderBy);
    }

    public function test_json_agg_list_form_uses_column_name_as_key(): void
    {
        $agg = Aggregate::jsonAgg(['id', 'name']);

        $this->assertNull($agg->source);
        $this->assertSame(['id' => 'id', 'name' => 'name'], $agg->sources);
    }

    public function test_json_agg_assoc_form_renames_keys(): void
    {
        $agg = Aggregate::jsonAgg(['nodeId' => 'id', 'label' => 'name']);

        $this->assertSame(['nodeId' => 'id', 'label' => 'name'], $agg->sources);
    }

    public function test_json_agg_rejects_empty_array(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        Aggregate::jsonAgg([]);
    }

    public function test_json_agg_rejects_duplicate_list_keys(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        Aggregate::jsonAgg(['name', 'name']);
    }

    public function test_json_agg_rejects_empty_string_key(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        Aggregate::jsonAgg(['' => 'id']);
    }

    public function test_json_agg_rejects_empty_string_column(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        Aggregate::jsonAgg(['k' => '']);
    }

    public function test_json_object_agg_captures_key_and_value(): void
    {
        $agg = Aggregate::jsonObjectAgg(key: 'slug', value: 'name');

        $this->assertSame(AggregateFunction::JsonObjectAgg, $agg->function);
        $this->assertSame('slug', $agg->keyColumn);
        $this->assertSame('name', $agg->valueColumn);
        $this->assertSame('slug', $agg->orderBy);
        $this->assertFalse($agg->allowNullKeys);
    }

    public function test_json_object_agg_allow_null_keys_opt_out(): void
    {
        $agg = Aggregate::jsonObjectAgg(key: 'slug', value: 'name', allowNullKeys: true);
        $this->assertTrue($agg->allowNullKeys);
    }

    public function test_json_object_agg_rejects_empty_key_or_value(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        Aggregate::jsonObjectAgg(key: '', value: 'name');
    }

    public function test_new_kinds_default_to_inclusive(): void
    {
        $this->assertTrue(Aggregate::distinctCount('x')->inclusive);
        $this->assertTrue(Aggregate::stringAgg('x')->inclusive);
        $this->assertTrue(Aggregate::jsonAgg('x')->inclusive);
        $this->assertTrue(Aggregate::jsonObjectAgg(key: 'k', value: 'v')->inclusive);
    }

    public function test_new_kinds_carry_exclusive_into_definition(): void
    {
        $this->assertFalse(Aggregate::distinctCount('x')->exclusive()->into('c')->inclusive);
        $this->assertFalse(Aggregate::stringAgg('x')->exclusive()->into('c')->inclusive);
        $this->assertFalse(Aggregate::jsonAgg('x')->exclusive()->into('c')->inclusive);
        $this->assertFalse(Aggregate::jsonObjectAgg(key: 'k', value: 'v')->exclusive()->into('c')->inclusive);
    }

    public function test_into_propagates_new_kind_settings(): void
    {
        $def = Aggregate::stringAgg('tag', separator: '; ', limit: 5)
            ->distinct()
            ->into('tags_csv');

        $this->assertSame('tags_csv', $def->column);
        $this->assertSame('; ', $def->separator);
        $this->assertSame(5, $def->limit);
        $this->assertTrue($def->distinct);
        $this->assertSame('tag', $def->orderBy);
    }

    public function test_into_propagates_json_agg_multi_column_sources(): void
    {
        $def = Aggregate::jsonAgg(['id' => 'id', 'label' => 'name'])
            ->into('descendant_summary');

        $this->assertSame(['id' => 'id', 'label' => 'name'], $def->sources);
        $this->assertNull($def->source);
    }

    public function test_into_propagates_json_object_agg_key_and_value(): void
    {
        $def = Aggregate::jsonObjectAgg(key: 'slug', value: 'name', allowNullKeys: true)
            ->into('lookup');

        $this->assertSame('slug', $def->keyColumn);
        $this->assertSame('name', $def->valueColumn);
        $this->assertTrue($def->allowNullKeys);
    }

    public function test_filter_carries_into_new_kinds(): void
    {
        $agg = Aggregate::stringAgg('tag')->filter(['published' => true]);

        $this->assertNotNull($agg->filter);
    }
}
