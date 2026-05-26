<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\MetricArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Pins the contract of {@see AggregateRegistry::varianceCompanionsFor()}:
 * for every inclusive Variance / Stddev declaration, the registry must
 * return the three companion column names (Sum, SumSq, Count) plus the
 * sample/population flag the in-UPDATE SET clause needs to pick the
 * right denominator.
 */
final class VarianceCompanionsRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    public function test_variance_and_stddev_companion_maps_use_canonical_suffixes(): void
    {
        $companions = AggregateRegistry::varianceCompanionsFor(MetricArea::class);

        $this->assertSame([
            'sum' => 'tickets_variance__sum',
            'sum_sq' => 'tickets_variance__sum_sq',
            'count' => 'tickets_variance__count',
            'function' => AggregateFunction::Variance,
            'sample' => false,
        ], $companions['tickets_variance'] ?? null);

        $this->assertSame([
            'sum' => 'tickets_stddev__sum',
            'sum_sq' => 'tickets_stddev__sum_sq',
            'count' => 'tickets_stddev__count',
            'function' => AggregateFunction::Stddev,
            'sample' => false,
        ], $companions['tickets_stddev'] ?? null);

        $this->assertSame([
            'sum' => 'tickets_variance_samp__sum',
            'sum_sq' => 'tickets_variance_samp__sum_sq',
            'count' => 'tickets_variance_samp__count',
            'function' => AggregateFunction::Variance,
            'sample' => true,
        ], $companions['tickets_variance_samp'] ?? null);

        $this->assertSame([
            'sum' => 'tickets_stddev_samp__sum',
            'sum_sq' => 'tickets_stddev_samp__sum_sq',
            'count' => 'tickets_stddev_samp__count',
            'function' => AggregateFunction::Stddev,
            'sample' => true,
        ], $companions['tickets_stddev_samp'] ?? null);
    }
}
