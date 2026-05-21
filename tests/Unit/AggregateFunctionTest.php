<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateFunction;

final class AggregateFunctionTest extends TestCase
{
    public function test_has_exactly_nine_cases(): void
    {
        $this->assertCount(9, AggregateFunction::cases());
    }

    public function test_each_case_is_backed_by_its_string_name(): void
    {
        $this->assertSame('sum', AggregateFunction::Sum->value);
        $this->assertSame('count', AggregateFunction::Count->value);
        $this->assertSame('avg', AggregateFunction::Avg->value);
        $this->assertSame('min', AggregateFunction::Min->value);
        $this->assertSame('max', AggregateFunction::Max->value);
        $this->assertSame('distinct_count', AggregateFunction::DistinctCount->value);
        $this->assertSame('string_agg', AggregateFunction::StringAgg->value);
        $this->assertSame('json_agg', AggregateFunction::JsonAgg->value);
        $this->assertSame('json_object_agg', AggregateFunction::JsonObjectAgg->value);
    }

    public function test_sum_and_count_support_delta_maintenance(): void
    {
        $this->assertTrue(AggregateFunction::Sum->supportsDelta());
        $this->assertTrue(AggregateFunction::Count->supportsDelta());
    }

    public function test_avg_min_max_do_not_support_delta_maintenance(): void
    {
        $this->assertFalse(AggregateFunction::Avg->supportsDelta());
        $this->assertFalse(AggregateFunction::Min->supportsDelta());
        $this->assertFalse(AggregateFunction::Max->supportsDelta());
    }

    public function test_distinct_count_and_string_and_json_kinds_do_not_support_delta(): void
    {
        $this->assertFalse(AggregateFunction::DistinctCount->supportsDelta());
        $this->assertFalse(AggregateFunction::StringAgg->supportsDelta());
        $this->assertFalse(AggregateFunction::JsonAgg->supportsDelta());
        $this->assertFalse(AggregateFunction::JsonObjectAgg->supportsDelta());
    }

    public function test_sum_count_and_distinct_count_are_non_nullable_on_empty(): void
    {
        $this->assertFalse(AggregateFunction::Sum->nullableOnEmpty());
        $this->assertFalse(AggregateFunction::Count->nullableOnEmpty());
        $this->assertFalse(AggregateFunction::DistinctCount->nullableOnEmpty());
    }

    public function test_avg_min_max_string_and_json_kinds_are_nullable_on_empty(): void
    {
        $this->assertTrue(AggregateFunction::Avg->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::Min->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::Max->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::StringAgg->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::JsonAgg->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::JsonObjectAgg->nullableOnEmpty());
    }

    public function test_avg_declares_sum_and_count_companions(): void
    {
        $companions = AggregateFunction::Avg->companionSet();

        $this->assertCount(2, $companions);

        $suffixesByFunction = [
            $companions[0]->function->value => $companions[0]->suffix,
            $companions[1]->function->value => $companions[1]->suffix,
        ];

        $this->assertSame(
            ['sum' => '__sum', 'count' => '__count'],
            $suffixesByFunction,
        );
    }

    public function test_sum_count_min_max_declare_no_companions(): void
    {
        $this->assertSame([], AggregateFunction::Sum->companionSet());
        $this->assertSame([], AggregateFunction::Count->companionSet());
        $this->assertSame([], AggregateFunction::Min->companionSet());
        $this->assertSame([], AggregateFunction::Max->companionSet());
    }
}
