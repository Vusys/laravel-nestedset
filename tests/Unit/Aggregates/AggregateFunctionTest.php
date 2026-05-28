<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSpec;

final class AggregateFunctionTest extends TestCase
{
    public function test_has_exactly_twenty_one_cases(): void
    {
        $this->assertCount(21, AggregateFunction::cases());
    }

    /**
     * Pins each case's string backing plus its two maintenance-routing
     * predicates: whether it can be maintained by a signed delta, and
     * whether its empty-subtree answer is NULL rather than zero.
     */
    #[DataProvider('caseProperties')]
    public function test_case_value_delta_and_nullability(
        AggregateFunction $case,
        string $value,
        bool $supportsDelta,
        bool $nullableOnEmpty,
    ): void {
        $this->assertSame($value, $case->value);
        $this->assertSame($case, AggregateFunction::from($value), 'value must round-trip via from()');
        $this->assertSame($supportsDelta, $case->supportsDelta());
        $this->assertSame($nullableOnEmpty, $case->nullableOnEmpty());
    }

    /**
     * @return iterable<string, array{0: AggregateFunction, 1: string, 2: bool, 3: bool}>
     */
    public static function caseProperties(): iterable
    {
        // case, string value, supportsDelta, nullableOnEmpty
        yield 'sum' => [AggregateFunction::Sum, 'sum', true, false];
        yield 'count' => [AggregateFunction::Count, 'count', true, false];
        yield 'avg' => [AggregateFunction::Avg, 'avg', false, true];
        yield 'min' => [AggregateFunction::Min, 'min', false, true];
        yield 'max' => [AggregateFunction::Max, 'max', false, true];
        yield 'variance' => [AggregateFunction::Variance, 'variance', false, true];
        yield 'stddev' => [AggregateFunction::Stddev, 'stddev', false, true];
        yield 'weighted_avg' => [AggregateFunction::WeightedAvg, 'weighted_avg', false, true];
        yield 'bool_or' => [AggregateFunction::BoolOr, 'bool_or', false, true];
        yield 'bool_and' => [AggregateFunction::BoolAnd, 'bool_and', false, true];
        yield 'geometric_mean' => [AggregateFunction::GeometricMean, 'geometric_mean', false, true];
        yield 'harmonic_mean' => [AggregateFunction::HarmonicMean, 'harmonic_mean', false, true];
        yield 'bit_or' => [AggregateFunction::BitOr, 'bit_or', false, true];
        yield 'bit_and' => [AggregateFunction::BitAnd, 'bit_and', false, true];
        yield 'bit_xor' => [AggregateFunction::BitXor, 'bit_xor', true, true];
        yield 'distinct_count' => [AggregateFunction::DistinctCount, 'distinct_count', false, false];
        yield 'string_agg' => [AggregateFunction::StringAgg, 'string_agg', false, true];
        yield 'json_agg' => [AggregateFunction::JsonAgg, 'json_agg', false, true];
        yield 'json_object_agg' => [AggregateFunction::JsonObjectAgg, 'json_object_agg', false, true];
        yield 'median' => [AggregateFunction::Median, 'median', false, true];
        yield 'percentile' => [AggregateFunction::Percentile, 'percentile', false, true];
    }

    /**
     * Pins the companion-column set each derived kind auto-promotes. Each
     * expected row is `[suffix, underlying-function value, source-transform name]`
     * in declaration order; kinds with no companions yield an empty list.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $expected
     */
    #[DataProvider('companionSetCases')]
    public function test_companion_set(AggregateFunction $function, array $expected): void
    {
        $actual = array_map(
            static fn (CompanionSpec $spec): array => [
                $spec->suffix,
                $spec->function->value,
                $spec->sourceTransform->name,
            ],
            $function->companionSet(),
        );

        $this->assertSame($expected, $actual);
    }

    /**
     * @return iterable<string, array{0: AggregateFunction, 1: list<array{0: string, 1: string, 2: string}>}>
     */
    public static function companionSetCases(): iterable
    {
        yield 'avg → sum + count' => [
            AggregateFunction::Avg,
            [['__sum', 'sum', 'Identity'], ['__count', 'count', 'Identity']],
        ];

        yield 'variance → sum, sum_sq (squared), count' => [
            AggregateFunction::Variance,
            [['__sum', 'sum', 'Identity'], ['__sum_sq', 'sum', 'Square'], ['__count', 'count', 'Identity']],
        ];

        yield 'stddev shares the variance companion shape' => [
            AggregateFunction::Stddev,
            [['__sum', 'sum', 'Identity'], ['__sum_sq', 'sum', 'Square'], ['__count', 'count', 'Identity']],
        ];

        yield 'weighted_avg → sum_wx (value*weight) + sum_w' => [
            AggregateFunction::WeightedAvg,
            [['__sum_wx', 'sum', 'TimesWeight'], ['__sum_w', 'sum', 'Identity']],
        ];

        yield 'bool_or → sum (as int) + count' => [
            AggregateFunction::BoolOr,
            [['__sum', 'sum', 'AsInt'], ['__count', 'count', 'Identity']],
        ];

        yield 'bool_and shares the bool_or companion shape' => [
            AggregateFunction::BoolAnd,
            [['__sum', 'sum', 'AsInt'], ['__count', 'count', 'Identity']],
        ];

        yield 'geometric_mean → sum_log + count, both Ln-domained' => [
            AggregateFunction::GeometricMean,
            [['__sum_log', 'sum', 'Ln'], ['__count', 'count', 'Ln']],
        ];

        yield 'harmonic_mean → sum_recip + count, both Recip-domained' => [
            AggregateFunction::HarmonicMean,
            [['__sum_recip', 'sum', 'Recip'], ['__count', 'count', 'Recip']],
        ];

        yield 'sum declares no companions' => [AggregateFunction::Sum, []];
        yield 'count declares no companions' => [AggregateFunction::Count, []];
        yield 'min declares no companions' => [AggregateFunction::Min, []];
        yield 'max declares no companions' => [AggregateFunction::Max, []];
        yield 'bit_or declares no companions' => [AggregateFunction::BitOr, []];
        yield 'bit_and declares no companions' => [AggregateFunction::BitAnd, []];
        yield 'bit_xor declares no companions' => [AggregateFunction::BitXor, []];
        yield 'distinct_count declares no companions' => [AggregateFunction::DistinctCount, []];
        yield 'string_agg declares no companions' => [AggregateFunction::StringAgg, []];
        yield 'json_agg declares no companions' => [AggregateFunction::JsonAgg, []];
        yield 'json_object_agg declares no companions' => [AggregateFunction::JsonObjectAgg, []];
        yield 'median declares no companions' => [AggregateFunction::Median, []];
        yield 'percentile declares no companions' => [AggregateFunction::Percentile, []];
    }
}
