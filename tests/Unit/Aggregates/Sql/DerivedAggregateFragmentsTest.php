<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates\Sql;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\BoundFragment;
use Vusys\NestedSet\Aggregates\Sql\DerivedAggregateFragments;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * Snapshot tests for the derived-from-companions SQL builder. These
 * fragments are backend-agnostic (plain SUM/COUNT/CASE arithmetic), so
 * a single expected string per case is exercised on every backend. Both
 * the unfiltered form and the `$filterSql`-wrapped form are pinned —
 * the filtered branches feed `withFreshAggregates()` and delta-time SET
 * clauses for filtered weighted/bool/mean columns.
 */
final class DerivedAggregateFragmentsTest extends TestCase
{
    #[DataProvider('handlesCases')]
    public function test_handles_identifies_derived_kinds(AggregateFunction $function, bool $expected): void
    {
        $this->assertSame($expected, DerivedAggregateFragments::handles($function));
    }

    /**
     * @return iterable<string, array{0: AggregateFunction, 1: bool}>
     */
    public static function handlesCases(): iterable
    {
        yield 'weighted_avg is derived' => [AggregateFunction::WeightedAvg, true];
        yield 'bool_or is derived' => [AggregateFunction::BoolOr, true];
        yield 'bool_and is derived' => [AggregateFunction::BoolAnd, true];
        yield 'geometric_mean is derived' => [AggregateFunction::GeometricMean, true];
        yield 'harmonic_mean is derived' => [AggregateFunction::HarmonicMean, true];
        yield 'sum is not derived' => [AggregateFunction::Sum, false];
        yield 'avg is not derived' => [AggregateFunction::Avg, false];
        yield 'variance is not derived' => [AggregateFunction::Variance, false];
        yield 'bit_or is not derived' => [AggregateFunction::BitOr, false];
        yield 'string_agg is not derived' => [AggregateFunction::StringAgg, false];
    }

    /**
     * @param  Closure(): AggregateDefinition  $makeDefinition
     */
    #[DataProvider('buildCases')]
    public function test_build_emits_derived_sql(Closure $makeDefinition, ?string $filterSql, string $expected): void
    {
        $filter = $filterSql === null ? null : BoundFragment::literal($filterSql);
        $this->assertSame($expected, DerivedAggregateFragments::build($makeDefinition(), 'i.', $filter)->sql);
    }

    /**
     * @return iterable<string, array{0: Closure(): AggregateDefinition, 1: ?string, 2: string}>
     */
    public static function buildCases(): iterable
    {
        $weighted = static fn (): AggregateDefinition => Aggregate::weightedAvg('val', 'wt')->into('col');
        $boolOr = static fn (): AggregateDefinition => Aggregate::boolOr('flag')->into('col');
        $boolAnd = static fn (): AggregateDefinition => Aggregate::boolAnd('flag')->into('col');
        $geo = static fn (): AggregateDefinition => Aggregate::geometricMean('rate')->into('col');
        $harmonic = static fn (): AggregateDefinition => Aggregate::harmonicMean('rate')->into('col');

        yield 'weighted_avg unfiltered' => [
            $weighted, null,
            '(1.0 * (SUM((i.wt * i.val)))) / NULLIF((SUM(i.wt)), 0)',
        ];
        yield 'weighted_avg filtered' => [
            $weighted, 'i.active = 1',
            '(1.0 * (SUM(CASE WHEN i.active = 1 THEN (i.wt * i.val) ELSE 0 END))) / NULLIF((SUM(CASE WHEN i.active = 1 THEN i.wt ELSE 0 END)), 0)',
        ];

        yield 'bool_or unfiltered' => [
            $boolOr, null,
            'CASE WHEN (COUNT(i.flag)) = 0 THEN NULL WHEN (SUM((CASE WHEN i.flag THEN 1 ELSE 0 END))) > 0 THEN TRUE ELSE FALSE END',
        ];
        yield 'bool_or filtered' => [
            $boolOr, 'i.active = 1',
            'CASE WHEN (COUNT(CASE WHEN i.active = 1 AND i.flag IS NOT NULL THEN 1 ELSE NULL END)) = 0 THEN NULL WHEN (SUM(CASE WHEN i.active = 1 THEN (CASE WHEN i.flag THEN 1 ELSE 0 END) ELSE 0 END)) > 0 THEN TRUE ELSE FALSE END',
        ];
        yield 'bool_and unfiltered' => [
            $boolAnd, null,
            'CASE WHEN (COUNT(i.flag)) = 0 THEN NULL WHEN (SUM((CASE WHEN i.flag THEN 1 ELSE 0 END))) = (COUNT(i.flag)) THEN TRUE ELSE FALSE END',
        ];

        yield 'geometric_mean unfiltered' => [
            $geo, null,
            'EXP(SUM(LN(CASE WHEN i.rate > 0 THEN i.rate ELSE NULL END)) / NULLIF(COUNT(CASE WHEN i.rate > 0 THEN i.rate ELSE NULL END), 0))',
        ];
        yield 'geometric_mean filtered' => [
            $geo, 'i.active = 1',
            'EXP(SUM(LN(CASE WHEN (i.active = 1) AND i.rate > 0 THEN i.rate ELSE NULL END)) / NULLIF(COUNT(CASE WHEN (i.active = 1) AND i.rate > 0 THEN i.rate ELSE NULL END), 0))',
        ];

        yield 'harmonic_mean unfiltered' => [
            $harmonic, null,
            'NULLIF(COUNT(NULLIF(i.rate, 0)), 0) / NULLIF(SUM((1.0 / NULLIF(i.rate, 0))), 0)',
        ];
        yield 'harmonic_mean filtered' => [
            $harmonic, 'i.active = 1',
            'NULLIF(COUNT(CASE WHEN (i.active = 1) AND i.rate <> 0 THEN 1 ELSE NULL END), 0) / NULLIF(SUM(CASE WHEN (i.active = 1) AND i.rate <> 0 THEN (1.0 / i.rate) ELSE NULL END), 0)',
        ];
    }

    public function test_build_rejects_a_definition_without_a_source(): void
    {
        $definition = new AggregateDefinition(
            column: 'col',
            function: AggregateFunction::GeometricMean,
            source: null,
            inclusive: true,
        );

        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('requires a source column');

        DerivedAggregateFragments::build($definition, 'i.');
    }

    public function test_build_rejects_weighted_avg_without_a_weight(): void
    {
        $definition = new AggregateDefinition(
            column: 'col',
            function: AggregateFunction::WeightedAvg,
            source: 'val',
            inclusive: true,
            weight: null,
        );

        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('missing its weight column');

        DerivedAggregateFragments::build($definition, 'i.');
    }
}
