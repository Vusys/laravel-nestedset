<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Query;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceTransform;
use Vusys\NestedSet\Query\ChainFoldAccumulator;

/**
 * Pins the PHP fast-path that backs
 * {@see ChainFoldAccumulator::apply()} for every aggregate kind the
 * chain fold supports. The accumulator must agree, value for value,
 * with what the slow SQL path would compute for a chain-shaped subtree
 * — a divergence here silently corrupts stored aggregates when
 * `fixAggregates()` walks a chain.
 *
 * Coverage is split by output type so the assertion can be precise:
 *  - {@see test_exact_running_inclusive} pins int/bool/null kinds with
 *    `assertSame`, so int-vs-float drift fails (the chain fold returns
 *    whole results as `int` to match SUM/MIN/MAX over an integer column).
 *  - {@see test_float_running_inclusive} pins the float kinds within a
 *    small delta — variance / stddev / means accumulate rounding noise.
 *
 * Both feed the rows leaf-first (the order the chain fold uses) and
 * assert the *inclusive* value reported after each row. The helper also
 * checks the exclusive ('previous') return: the value reported before
 * row k must equal the inclusive value reported after row k-1.
 */
final class ChainFoldAccumulatorTest extends TestCase
{
    /**
     * @param  Closure(): AggregateDefinition  $makeDefinition
     * @param  list<array{0: mixed, 1: mixed}>  $rows
     * @param  list<int|float|bool|null>  $expectedInclusive
     */
    #[DataProvider('exactKindCases')]
    #[Test]
    public function exact_running_inclusive(Closure $makeDefinition, array $rows, array $expectedInclusive): void
    {
        $this->assertSame($expectedInclusive, $this->runningInclusive($makeDefinition(), $rows));
    }

    /**
     * @param  Closure(): AggregateDefinition  $makeDefinition
     * @param  list<array{0: mixed, 1: mixed}>  $rows
     * @param  list<float|null>  $expectedInclusive
     */
    #[DataProvider('floatKindCases')]
    #[Test]
    public function float_running_inclusive(Closure $makeDefinition, array $rows, array $expectedInclusive): void
    {
        $actual = $this->runningInclusive($makeDefinition(), $rows);

        $this->assertCount(count($expectedInclusive), $actual);
        foreach ($expectedInclusive as $i => $expected) {
            if ($expected === null) {
                $this->assertNull($actual[$i], "step {$i} should be null");

                continue;
            }
            // assertEqualsWithDelta coerces null to 0 in the subtraction,
            // so a number→null mutation would pass against an expected
            // 0.0. Reject null explicitly first.
            $this->assertNotNull($actual[$i], "step {$i} should not be null");
            $this->assertEqualsWithDelta($expected, $actual[$i], 1e-9, "step {$i}");
        }
    }

    /**
     * @return iterable<string, array{0: Closure(): AggregateDefinition, 1: list<array{0: mixed, 1: mixed}>, 2: list<int|float|bool|null>}>
     */
    public static function exactKindCases(): iterable
    {
        // sum keeps a fractional total as float once the running sum
        // becomes fractional — pins the int/float branch of isWhole().
        yield 'sum returns float when the running total has a fractional part' => [
            fn (): AggregateDefinition => Aggregate::sum('x')->into('s'),
            [[2, null], [1.5, null], [0.5, null]],
            [2, 3.5, 4],  // step 2: 3.5 (float, not whole); step 3: 4 (back to int via isWhole)
        ];

        yield 'sum folds ints and returns int' => [
            fn (): AggregateDefinition => Aggregate::sum('x')->into('s'),
            [[2, null], [3, null], [5, null]],
            [2, 5, 10],
        ];

        yield 'sum treats non-numeric as zero' => [
            fn (): AggregateDefinition => Aggregate::sum('x')->into('s'),
            [['abc', null], [4, null]],
            [0, 4],
        ];

        yield 'count(*) counts every row including null source' => [
            fn (): AggregateDefinition => Aggregate::count()->into('c'),
            [[null, null], ['x', null], [7, null]],
            [1, 2, 3],
        ];

        yield 'count(column) ignores null source rows' => [
            fn (): AggregateDefinition => Aggregate::count('x')->into('c'),
            [[5, null], [null, null], [9, null]],
            [1, 1, 2],
        ];

        yield 'min tracks the running minimum' => [
            fn (): AggregateDefinition => Aggregate::min('x')->into('m'),
            [[5, null], [2, null], [8, null]],
            [5, 2, 2],
        ];

        yield 'min stays null then skips non-numeric' => [
            fn (): AggregateDefinition => Aggregate::min('x')->into('m'),
            [['x', null], [4, null], [3, null]],
            [null, 4, 3],
        ];

        yield 'max tracks the running maximum and skips null rows' => [
            fn (): AggregateDefinition => Aggregate::max('x')->into('m'),
            [[5, null], [2, null], [null, null], [8, null]],
            [5, 5, 5, 8],
        ];

        // A non-numeric row must be SKIPPED, not folded as 0.0. With a
        // negative running maximum the difference is observable: skipping
        // keeps -5, whereas max(0.0, -5) would jump the maximum to 0.
        // All-positive fixtures mask this (max(0.0, positive) == positive).
        yield 'max skips a non-numeric row without clamping a negative running max to zero' => [
            fn (): AggregateDefinition => Aggregate::max('x')->into('m'),
            [[-5, null], ['x', null], [-2, null]],
            [-5, -5, -2],
        ];

        yield 'bit_or accumulates set bits' => [
            fn (): AggregateDefinition => Aggregate::bitOr('x')->into('b'),
            [[1, null], [2, null], [4, null]],
            [1, 3, 7],
        ];

        yield 'bit_and intersects bits and skips null rows' => [
            fn (): AggregateDefinition => Aggregate::bitAnd('x')->into('b'),
            [[7, null], [6, null], [null, null], [4, null]],
            [7, 6, 6, 4],
        ];

        yield 'bit_xor toggles bits and skips null rows' => [
            fn (): AggregateDefinition => Aggregate::bitXor('x')->into('b'),
            [[1, null], [3, null], [null, null], [1, null]],
            [1, 2, 2, 3],
        ];

        yield 'bit_or stays null then skips non-numeric' => [
            fn (): AggregateDefinition => Aggregate::bitOr('x')->into('b'),
            [['x', null], [2, null]],
            [null, 2],
        ];

        // A leading non-numeric row must leave the accumulator null, not
        // seed it with (int)'x' == 0. Unlike BitOr/BitAnd this is only
        // observable for BitXor at the first step: were the non-numeric
        // guard dropped, step 0 would report 0 instead of null.
        yield 'bit_xor stays null then skips a leading non-numeric row' => [
            fn (): AggregateDefinition => Aggregate::bitXor('x')->into('b'),
            [['x', null], [5, null]],
            [null, 5],
        ];

        yield 'bool_or is null until a row contributes, then ORs' => [
            fn (): AggregateDefinition => Aggregate::boolOr('x')->into('b'),
            [[null, null], [0, null], [1, null]],
            [null, false, true],
        ];

        yield 'bool_and is true only while every row is truthy' => [
            fn (): AggregateDefinition => Aggregate::boolAnd('x')->into('b'),
            [[1, null], [1, null], [0, null]],
            [true, true, false],
        ];

        yield 'bool_or reads string driver markers (f/0/false vs t)' => [
            fn (): AggregateDefinition => Aggregate::boolOr('x')->into('b'),
            [['false', null], ['0', null], ['true', null]],
            [false, false, true],
        ];

        yield 'bool_or reads native PHP booleans' => [
            fn (): AggregateDefinition => Aggregate::boolOr('x')->into('b'),
            [[false, null], [true, null]],
            [false, true],
        ];

        // bool_and compares boolSum === boolCount, so it pins the *exact*
        // 0/1 contribution asBoolInt() produces — bool_or only checks
        // `> 0` and so masks an over-large contribution.
        yield 'bool_and reads native PHP booleans as a 0/1 contribution' => [
            fn (): AggregateDefinition => Aggregate::boolAnd('x')->into('b'),
            [[true, null], [true, null]],
            [true, true],
        ];

        yield 'bool_and treats a float 1.0 source as truthy' => [
            fn (): AggregateDefinition => Aggregate::boolAnd('x')->into('b'),
            [[1.0, null]],
            [true],
        ];

        yield 'bool_and reads a textual truthy marker as a single contribution' => [
            fn (): AggregateDefinition => Aggregate::boolAnd('x')->into('b'),
            [['true', null], ['t', null]],
            [true, true],
        ];

        yield 'bool_and lower-cases string markers before matching' => [
            fn (): AggregateDefinition => Aggregate::boolAnd('x')->into('b'),
            [['FALSE', null]],
            [false],
        ];

        yield 'bool_and trims whitespace before matching string markers' => [
            fn (): AggregateDefinition => Aggregate::boolAnd('x')->into('b'),
            [[' false ', null]],
            [false],
        ];

        yield 'bool_and reads an empty string as a false marker' => [
            fn (): AggregateDefinition => Aggregate::boolAnd('x')->into('b'),
            [['', null]],
            [false],
        ];

        // Companion definitions carry a source transform. These can only
        // arise internally (variance/weighted-avg/bool auto-promote them),
        // so build them directly — there is no fluent factory.
        yield 'square companion (variance __sum_sq) folds squared source' => [
            fn (): AggregateDefinition => new AggregateDefinition(
                column: '__sum_sq',
                function: AggregateFunction::Sum,
                source: 'x',
                inclusive: true,
                internal: true,
                sourceTransform: CompanionSourceTransform::Square,
            ),
            [[2, null], [3, null]],
            [4, 13],
        ];

        yield 'times-weight companion (weighted-avg __sum_wx) folds value*weight' => [
            fn (): AggregateDefinition => new AggregateDefinition(
                column: '__sum_wx',
                function: AggregateFunction::Sum,
                source: 'x',
                inclusive: true,
                internal: true,
                sourceTransform: CompanionSourceTransform::TimesWeight,
                weight: 'w',
            ),
            [[2, 3], [4, 5]],
            [6, 26],
        ];

        // String-typed numeric sources — sqlite (and a handful of cast
        // configurations on other backends) return numeric column values
        // as PHP strings. The chain-fold relies on explicit (int)/(float)
        // casts at the boundary; dropping any of them would either coerce
        // implicitly (silently passing) or violate the int|float return
        // type and throw downstream. Stringy inputs pin those boundary
        // casts directly — pure-int rows can't distinguish "value cast
        // to float" from "value already float".
        yield 'sum folds stringy-numeric sources to ints when whole' => [
            fn (): AggregateDefinition => Aggregate::sum('x')->into('s'),
            [['2', null], ['3', null], ['5', null]],
            [2, 5, 10],
        ];

        yield 'sum keeps a fractional total as float when sources are stringy' => [
            fn (): AggregateDefinition => Aggregate::sum('x')->into('s'),
            [['1.5', null], ['2.25', null]],
            [1.5, 3.75],
        ];

        yield 'min tracks stringy-numeric sources with correct types' => [
            fn (): AggregateDefinition => Aggregate::min('x')->into('m'),
            [['10', null], ['3', null], ['7', null]],
            [10, 3, 3],
        ];

        yield 'max tracks stringy-numeric sources with correct types' => [
            fn (): AggregateDefinition => Aggregate::max('x')->into('m'),
            [['1', null], ['10', null], ['4', null]],
            [1, 10, 10],
        ];

        yield 'bit_or accepts stringy-numeric sources' => [
            fn (): AggregateDefinition => Aggregate::bitOr('x')->into('b'),
            [['1', null], ['10', null], ['0', null]],
            [1, 11, 11],
        ];

        yield 'bit_and accepts stringy-numeric sources' => [
            fn (): AggregateDefinition => Aggregate::bitAnd('x')->into('b'),
            [['15', null], ['10', null], ['12', null]],
            [15, 10, 8],
        ];

        yield 'bit_xor accepts stringy-numeric sources' => [
            fn (): AggregateDefinition => Aggregate::bitXor('x')->into('b'),
            [['5', null], ['3', null], ['6', null]],
            [5, 6, 0],
        ];

        yield 'as-int companion (bool __sum) folds the 0/1 contribution' => [
            fn (): AggregateDefinition => new AggregateDefinition(
                column: '__sum',
                function: AggregateFunction::Sum,
                source: 'x',
                inclusive: true,
                internal: true,
                sourceTransform: CompanionSourceTransform::AsInt,
            ),
            [[true, null], [0, null], ['x', null]],
            [1, 1, 2],
        ];
    }

    /**
     * @return iterable<string, array{0: Closure(): AggregateDefinition, 1: list<array{0: mixed, 1: mixed}>, 2: list<float|null>}>
     */
    public static function floatKindCases(): iterable
    {
        yield 'avg of ints' => [
            fn (): AggregateDefinition => Aggregate::avg('x')->into('a'),
            [[2, null], [4, null], [6, null]],
            [2.0, 3.0, 4.0],
        ];

        yield 'avg leaves count unchanged for non-numeric rows' => [
            fn (): AggregateDefinition => Aggregate::avg('x')->into('a'),
            [[10, null], ['x', null], [20, null]],
            [10.0, 10.0, 15.0],
        ];

        yield 'avg is null before the first contributor' => [
            fn (): AggregateDefinition => Aggregate::avg('x')->into('a'),
            [['x', null], [4, null]],
            [null, 4.0],
        ];

        yield 'population variance' => [
            fn (): AggregateDefinition => Aggregate::variance('x')->into('v'),
            [[2, null], [4, null], [6, null]],
            [0.0, 1.0, 24 / 9],
        ];

        yield 'sample variance is null below two values' => [
            fn (): AggregateDefinition => Aggregate::variance('x', sample: true)->into('v'),
            [[2, null], [4, null], [6, null]],
            [null, 2.0, 4.0],
        ];

        yield 'population stddev is the root of population variance' => [
            fn (): AggregateDefinition => Aggregate::stddev('x')->into('sd'),
            [[2, null], [4, null], [6, null]],
            [0.0, 1.0, sqrt(24 / 9)],
        ];

        yield 'sample stddev is null below two values' => [
            fn (): AggregateDefinition => Aggregate::stddev('x', sample: true)->into('sd'),
            [[2, null], [4, null]],
            [null, sqrt(2.0)],
        ];

        yield 'weighted avg' => [
            fn (): AggregateDefinition => Aggregate::weightedAvg('x', 'w')->into('wa'),
            [[10, 1], [20, 3]],
            [10.0, 17.5],
        ];

        yield 'weighted avg is null when total weight is zero' => [
            fn (): AggregateDefinition => Aggregate::weightedAvg('x', 'w')->into('wa'),
            [[10, 0], [20, 0]],
            [null, null],
        ];

        yield 'weighted avg coerces non-numeric value and weight to zero' => [
            fn (): AggregateDefinition => Aggregate::weightedAvg('x', 'w')->into('wa'),
            [['x', 5], [4, 'y']],
            [0.0, 0.0],
        ];

        yield 'geometric mean' => [
            fn (): AggregateDefinition => Aggregate::geometricMean('x')->into('g'),
            [[2, null], [8, null]],
            [2.0, 4.0],
        ];

        // Stringy-numeric weighted-avg sources — pins the (float)
        // casts at ChainFoldAccumulator.php:92 / :93. Without the
        // casts $weightNumeric * $valueNumeric would coerce implicitly,
        // hiding mistakes in the accumulator's running types.
        yield 'weighted avg accepts stringy-numeric values and weights' => [
            fn (): AggregateDefinition => Aggregate::weightedAvg('x', 'w')->into('wa'),
            [['2', '5'], ['4', '5']],
            [2.0, 3.0],  // (5*2 + 5*4) / (5 + 5) = 30/10 = 3.0
        ];

        // Stringy-numeric companion-derived sources — pins the (float)
        // cast at ChainFoldAccumulator.php:81 for sum-of-square and
        // similar companions in the variance / stddev path.
        yield 'population variance accepts stringy-numeric sources' => [
            fn (): AggregateDefinition => Aggregate::variance('x')->into('v'),
            [['2', null], ['4', null], ['6', null]],
            // mean = 4, variance = ((2-4)^2 + (4-4)^2 + (6-4)^2)/3 = 8/3
            [0.0, 1.0, 8 / 3],
        ];

        // Stringy-numeric geometric-mean source — pins the (float)
        // cast at ChainFoldAccumulator.php:112.
        yield 'geometric mean accepts stringy-numeric sources' => [
            fn (): AggregateDefinition => Aggregate::geometricMean('x')->into('g'),
            [['2', null], ['8', null]],
            [2.0, 4.0],
        ];

        yield 'geometric mean skips non-positive source values' => [
            fn (): AggregateDefinition => Aggregate::geometricMean('x')->into('g'),
            [[0, null], [-5, null], [4, null]],
            [null, null, 4.0],
        ];

        yield 'harmonic mean' => [
            fn (): AggregateDefinition => Aggregate::harmonicMean('x')->into('h'),
            [[2, null], [4, null]],
            [2.0, 2 / 0.75],
        ];

        yield 'harmonic mean skips zero source values' => [
            fn (): AggregateDefinition => Aggregate::harmonicMean('x')->into('h'),
            [[0, null], [4, null]],
            [null, 4.0],
        ];

        // Reciprocals that cancel to exactly zero must report null, not
        // divide by zero — pins the `sumRecip !== 0.0` guard at the
        // display.
        yield 'harmonic mean is null when reciprocals cancel to zero' => [
            fn (): AggregateDefinition => Aggregate::harmonicMean('x')->into('h'),
            [[2, null], [-2, null]],
            [2.0, null],
        ];

        // Σ(1/x) landing on exactly 1.0 must still divide — guards
        // against the guard being written against the literal 1.0.
        yield 'harmonic mean divides when reciprocal sum is one' => [
            fn (): AggregateDefinition => Aggregate::harmonicMean('x')->into('h'),
            [[2, null], [2, null]],
            [2.0, 2.0],
        ];

        yield 'ln companion (geometric-mean __sum_log) folds log(source)' => [
            fn (): AggregateDefinition => new AggregateDefinition(
                column: '__sum_log',
                function: AggregateFunction::Sum,
                source: 'x',
                inclusive: true,
                internal: true,
                sourceTransform: CompanionSourceTransform::Ln,
            ),
            [[0, null], [2, null], [8, null]],
            [0.0, log(2), log(2) + log(8)],
        ];

        yield 'recip companion (harmonic-mean __sum_recip) folds 1/source' => [
            fn (): AggregateDefinition => new AggregateDefinition(
                column: '__sum_recip',
                function: AggregateFunction::Sum,
                source: 'x',
                inclusive: true,
                internal: true,
                sourceTransform: CompanionSourceTransform::Recip,
            ),
            [[0, null], [2, null], [4, null]],
            [0.0, 0.5, 0.75],
        ];
    }

    /**
     * Feeds the (source, weight) rows through a fresh accumulator and
     * returns the inclusive value reported *after* each row. Also pins
     * the exclusive ('previous') return: the value reported before row k
     * must equal the inclusive value reported after row k-1.
     *
     * @param  list<array{0: mixed, 1: mixed}>  $rows
     * @return list<int|float|bool|null>
     */
    private function runningInclusive(AggregateDefinition $definition, array $rows): array
    {
        $accumulator = new ChainFoldAccumulator($definition);

        $currents = [];
        $previous = [];
        foreach ($rows as [$source, $weight]) {
            $step = $accumulator->apply($source, $weight);
            $previous[] = $step['previous'];
            $currents[] = $step['current'];
        }

        $count = count($currents);
        for ($k = 1; $k < $count; $k++) {
            $this->assertSame(
                $currents[$k - 1],
                $previous[$k],
                "exclusive value before row {$k} must equal the inclusive value after row ".($k - 1),
            );
        }

        return $currents;
    }
}
