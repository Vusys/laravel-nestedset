<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateFunction;

final class AggregateFunctionTest extends TestCase
{
    public function test_has_exactly_twenty_one_cases(): void
    {
        $this->assertCount(21, AggregateFunction::cases());
    }

    public function test_each_case_is_backed_by_its_string_name(): void
    {
        $this->assertSame('sum', AggregateFunction::Sum->value);
        $this->assertSame('count', AggregateFunction::Count->value);
        $this->assertSame('avg', AggregateFunction::Avg->value);
        $this->assertSame('min', AggregateFunction::Min->value);
        $this->assertSame('max', AggregateFunction::Max->value);
        $this->assertSame('variance', AggregateFunction::Variance->value);
        $this->assertSame('stddev', AggregateFunction::Stddev->value);
        $this->assertSame('weighted_avg', AggregateFunction::WeightedAvg->value);
        $this->assertSame('bool_or', AggregateFunction::BoolOr->value);
        $this->assertSame('bool_and', AggregateFunction::BoolAnd->value);
        $this->assertSame('geometric_mean', AggregateFunction::GeometricMean->value);
        $this->assertSame('harmonic_mean', AggregateFunction::HarmonicMean->value);
        $this->assertSame('bit_or', AggregateFunction::BitOr->value);
        $this->assertSame('bit_and', AggregateFunction::BitAnd->value);
        $this->assertSame('bit_xor', AggregateFunction::BitXor->value);
        $this->assertSame('distinct_count', AggregateFunction::DistinctCount->value);
        $this->assertSame('string_agg', AggregateFunction::StringAgg->value);
        $this->assertSame('json_agg', AggregateFunction::JsonAgg->value);
        $this->assertSame('json_object_agg', AggregateFunction::JsonObjectAgg->value);
        $this->assertSame('median', AggregateFunction::Median->value);
        $this->assertSame('percentile', AggregateFunction::Percentile->value);
    }

    public function test_sum_count_and_bit_xor_support_delta_maintenance(): void
    {
        $this->assertTrue(AggregateFunction::Sum->supportsDelta());
        $this->assertTrue(AggregateFunction::Count->supportsDelta());
        $this->assertTrue(AggregateFunction::BitXor->supportsDelta());
    }

    public function test_derived_and_recompute_kinds_do_not_support_delta_maintenance(): void
    {
        $this->assertFalse(AggregateFunction::Avg->supportsDelta());
        $this->assertFalse(AggregateFunction::Min->supportsDelta());
        $this->assertFalse(AggregateFunction::Max->supportsDelta());
        $this->assertFalse(AggregateFunction::Variance->supportsDelta());
        $this->assertFalse(AggregateFunction::Stddev->supportsDelta());
        $this->assertFalse(AggregateFunction::BitOr->supportsDelta());
        $this->assertFalse(AggregateFunction::BitAnd->supportsDelta());
        $this->assertFalse(AggregateFunction::Median->supportsDelta());
        $this->assertFalse(AggregateFunction::Percentile->supportsDelta());
    }

    public function test_weighted_avg_bool_and_mean_kinds_do_not_support_delta(): void
    {
        $this->assertFalse(AggregateFunction::WeightedAvg->supportsDelta());
        $this->assertFalse(AggregateFunction::BoolOr->supportsDelta());
        $this->assertFalse(AggregateFunction::BoolAnd->supportsDelta());
        $this->assertFalse(AggregateFunction::GeometricMean->supportsDelta());
        $this->assertFalse(AggregateFunction::HarmonicMean->supportsDelta());
    }

    public function test_weighted_avg_bool_and_mean_kinds_are_nullable_on_empty(): void
    {
        $this->assertTrue(AggregateFunction::WeightedAvg->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::BoolOr->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::BoolAnd->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::GeometricMean->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::HarmonicMean->nullableOnEmpty());
    }

    public function test_weighted_avg_declares_sum_wx_and_sum_w_companions(): void
    {
        $companions = AggregateFunction::WeightedAvg->companionSet();

        $this->assertCount(2, $companions);
        $this->assertSame('__sum_wx', $companions[0]->suffix);
        $this->assertSame(AggregateFunction::Sum, $companions[0]->function);
        $this->assertSame('__sum_w', $companions[1]->suffix);
        $this->assertSame(AggregateFunction::Sum, $companions[1]->function);
    }

    public function test_bool_or_and_bool_and_share_the_same_companion_shape(): void
    {
        $orSet = AggregateFunction::BoolOr->companionSet();
        $andSet = AggregateFunction::BoolAnd->companionSet();

        $this->assertSame(['__sum', '__count'], [$orSet[0]->suffix, $orSet[1]->suffix]);
        $this->assertSame(['__sum', '__count'], [$andSet[0]->suffix, $andSet[1]->suffix]);
        $this->assertSame(AggregateFunction::Sum, $orSet[0]->function);
        $this->assertSame(AggregateFunction::Count, $orSet[1]->function);
        $this->assertSame(AggregateFunction::Sum, $andSet[0]->function);
        $this->assertSame(AggregateFunction::Count, $andSet[1]->function);
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

    public function test_derived_and_recompute_kinds_are_nullable_on_empty(): void
    {
        $this->assertTrue(AggregateFunction::Avg->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::Min->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::Max->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::Variance->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::Stddev->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::BitOr->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::BitAnd->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::BitXor->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::StringAgg->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::JsonAgg->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::JsonObjectAgg->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::Median->nullableOnEmpty());
        $this->assertTrue(AggregateFunction::Percentile->nullableOnEmpty());
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

    public function test_geometric_mean_declares_sum_log_and_count_companions(): void
    {
        $companions = AggregateFunction::GeometricMean->companionSet();

        $this->assertCount(2, $companions);
        $this->assertSame('__sum_log', $companions[0]->suffix);
        $this->assertSame(AggregateFunction::Sum, $companions[0]->function);
        $this->assertSame('__count', $companions[1]->suffix);
        $this->assertSame(AggregateFunction::Count, $companions[1]->function);
    }

    public function test_harmonic_mean_declares_sum_recip_and_count_companions(): void
    {
        $companions = AggregateFunction::HarmonicMean->companionSet();

        $this->assertCount(2, $companions);
        $this->assertSame('__sum_recip', $companions[0]->suffix);
        $this->assertSame(AggregateFunction::Sum, $companions[0]->function);
        $this->assertSame('__count', $companions[1]->suffix);
        $this->assertSame(AggregateFunction::Count, $companions[1]->function);
    }

    public function test_sum_count_min_max_declare_no_companions(): void
    {
        $this->assertSame([], AggregateFunction::Sum->companionSet());
        $this->assertSame([], AggregateFunction::Count->companionSet());
        $this->assertSame([], AggregateFunction::Min->companionSet());
        $this->assertSame([], AggregateFunction::Max->companionSet());
        $this->assertSame([], AggregateFunction::Median->companionSet());
        $this->assertSame([], AggregateFunction::Percentile->companionSet());
    }

    public function test_bitwise_kinds_declare_no_companions(): void
    {
        $this->assertSame([], AggregateFunction::BitOr->companionSet());
        $this->assertSame([], AggregateFunction::BitAnd->companionSet());
        $this->assertSame([], AggregateFunction::BitXor->companionSet());
    }

    public function test_distinct_count_string_and_json_declare_no_companions(): void
    {
        $this->assertSame([], AggregateFunction::DistinctCount->companionSet());
        $this->assertSame([], AggregateFunction::StringAgg->companionSet());
        $this->assertSame([], AggregateFunction::JsonAgg->companionSet());
        $this->assertSame([], AggregateFunction::JsonObjectAgg->companionSet());
        $this->assertSame([], AggregateFunction::Median->companionSet());
        $this->assertSame([], AggregateFunction::Percentile->companionSet());
    }

    public function test_variance_and_stddev_declare_sum_sumsq_count_companions(): void
    {
        foreach ([AggregateFunction::Variance, AggregateFunction::Stddev] as $kind) {
            $companions = $kind->companionSet();

            $this->assertCount(3, $companions, $kind->value.' should declare three companions');

            $signature = [];
            foreach ($companions as $spec) {
                $signature[$spec->suffix] = [
                    'function' => $spec->function->value,
                    'transform' => $spec->sourceTransform->name,
                ];
            }

            $this->assertSame(
                [
                    '__sum' => ['function' => 'sum', 'transform' => 'Identity'],
                    '__sum_sq' => ['function' => 'sum', 'transform' => 'Square'],
                    '__count' => ['function' => 'count', 'transform' => 'Identity'],
                ],
                $signature,
                $kind->value.' must declare Sum, SumSq (squared source), Count',
            );
        }
    }
}
