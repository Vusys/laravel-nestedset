<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Aggregates\CompanionSourceTransform;
use Vusys\NestedSet\Tests\Fixtures\Models\MeanArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Locks in registry auto-promotion for geometric-mean and harmonic-mean:
 *
 *  - GeometricMean `value_gmean` → companion Sum `value_gmean__sum_log`
 *    (transform = Ln, source = value) + Count `value_gmean__count`.
 *  - HarmonicMean `value_hmean` → companion Sum `value_hmean__sum_recip`
 *    (transform = Recip, source = value) + Count `value_hmean__count`.
 *
 * Pins both column names and per-companion metadata against future
 * refactors.
 */
final class MeanCompanionsRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    public function test_geometric_mean_auto_promotes_sum_log_and_count_companions(): void
    {
        $definitions = AggregateRegistry::for(MeanArea::class);

        /** @var array<string, AggregateDefinition> $byColumn */
        $byColumn = [];
        foreach ($definitions as $def) {
            if ($def instanceof AggregateDefinition) {
                $byColumn[$def->column] = $def;
            }
        }

        $this->assertArrayHasKey('value_gmean', $byColumn);
        $this->assertArrayHasKey('value_gmean__sum_log', $byColumn);
        $this->assertArrayHasKey('value_gmean__count', $byColumn);

        $display = $byColumn['value_gmean'];
        $this->assertSame(AggregateFunction::GeometricMean, $display->function);
        $this->assertSame('value', $display->source);

        $sumLog = $byColumn['value_gmean__sum_log'];
        $this->assertSame(AggregateFunction::Sum, $sumLog->function);
        $this->assertSame('value', $sumLog->source);
        $this->assertSame(CompanionSourceTransform::Ln, $sumLog->sourceTransform);
        $this->assertTrue($sumLog->isInternal());

        $count = $byColumn['value_gmean__count'];
        $this->assertSame(AggregateFunction::Count, $count->function);
        $this->assertSame('value', $count->source);
        $this->assertTrue($count->isInternal());
    }

    public function test_harmonic_mean_auto_promotes_sum_recip_and_count_companions(): void
    {
        $definitions = AggregateRegistry::for(MeanArea::class);

        /** @var array<string, AggregateDefinition> $byColumn */
        $byColumn = [];
        foreach ($definitions as $def) {
            if ($def instanceof AggregateDefinition) {
                $byColumn[$def->column] = $def;
            }
        }

        $this->assertArrayHasKey('value_hmean', $byColumn);
        $this->assertArrayHasKey('value_hmean__sum_recip', $byColumn);
        $this->assertArrayHasKey('value_hmean__count', $byColumn);

        $display = $byColumn['value_hmean'];
        $this->assertSame(AggregateFunction::HarmonicMean, $display->function);
        $this->assertSame('value', $display->source);

        $sumRecip = $byColumn['value_hmean__sum_recip'];
        $this->assertSame(AggregateFunction::Sum, $sumRecip->function);
        $this->assertSame('value', $sumRecip->source);
        $this->assertSame(CompanionSourceTransform::Recip, $sumRecip->sourceTransform);
        $this->assertTrue($sumRecip->isInternal());

        $count = $byColumn['value_hmean__count'];
        $this->assertSame(AggregateFunction::Count, $count->function);
        $this->assertSame('value', $count->source);
        $this->assertTrue($count->isInternal());
    }

    public function test_mean_companion_resolver_returns_correct_column_names(): void
    {
        $companions = AggregateRegistry::meanCompanionsFor(MeanArea::class);

        $this->assertArrayHasKey('value_gmean', $companions);
        $this->assertSame('value_gmean__sum_log', $companions['value_gmean']['sum_companion']);
        $this->assertSame('value_gmean__count', $companions['value_gmean']['count']);
        $this->assertSame(AggregateFunction::GeometricMean, $companions['value_gmean']['function']);

        $this->assertArrayHasKey('value_hmean', $companions);
        $this->assertSame('value_hmean__sum_recip', $companions['value_hmean']['sum_companion']);
        $this->assertSame('value_hmean__count', $companions['value_hmean']['count']);
        $this->assertSame(AggregateFunction::HarmonicMean, $companions['value_hmean']['function']);
    }
}
