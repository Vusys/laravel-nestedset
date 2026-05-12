<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateFunction;

final class AggregateFunctionTest extends TestCase
{
    public function test_has_exactly_five_cases(): void
    {
        $this->assertCount(5, AggregateFunction::cases());
    }

    public function test_each_case_is_backed_by_its_lowercase_name(): void
    {
        $this->assertSame('sum', AggregateFunction::Sum->value);
        $this->assertSame('count', AggregateFunction::Count->value);
        $this->assertSame('avg', AggregateFunction::Avg->value);
        $this->assertSame('min', AggregateFunction::Min->value);
        $this->assertSame('max', AggregateFunction::Max->value);
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

    public function test_sum_and_count_are_non_nullable_on_empty(): void
    {
        $this->assertFalse(AggregateFunction::Sum->nullableOnEmpty());
        $this->assertFalse(AggregateFunction::Count->nullableOnEmpty());
    }

    public function test_avg_min_max_are_nullable_on_empty(): void
    {
        $this->assertTrue(AggregateFunction::Avg->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::Min->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::Max->nullableOnEmpty());
    }
}
