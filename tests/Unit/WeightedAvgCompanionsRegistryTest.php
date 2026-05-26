<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Aggregates\CompanionSourceTransform;
use Vusys\NestedSet\Tests\Fixtures\Models\WeightedArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Locks in the registry's auto-promotion behaviour for weighted-average
 * declarations: the user-facing `value_wavg` column gets two internal
 * companion Sums — `__sum_wx` (transform = TimesWeight, source = value)
 * and `__sum_w` (transform = Identity, source = weight). Without those
 * fields the delta path can't reconstruct `Σ(w · x) / Σ(w)` at SET
 * time, so this test pins both the column names and the per-companion
 * metadata against future refactors.
 */
final class WeightedAvgCompanionsRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    public function test_weighted_avg_auto_promotes_two_internal_sum_companions(): void
    {
        $definitions = AggregateRegistry::for(WeightedArea::class);

        /** @var array<string, AggregateDefinition> $byColumn */
        $byColumn = [];
        foreach ($definitions as $def) {
            if ($def instanceof AggregateDefinition) {
                $byColumn[$def->column] = $def;
            }
        }

        $this->assertArrayHasKey('value_wavg', $byColumn);
        $this->assertArrayHasKey('value_wavg__sum_wx', $byColumn);
        $this->assertArrayHasKey('value_wavg__sum_w', $byColumn);

        $display = $byColumn['value_wavg'];
        $this->assertSame(AggregateFunction::WeightedAvg, $display->function);
        $this->assertSame('value', $display->source);
        $this->assertSame('weight', $display->weight);

        $sumWx = $byColumn['value_wavg__sum_wx'];
        $this->assertSame(AggregateFunction::Sum, $sumWx->function);
        $this->assertSame('value', $sumWx->source);
        $this->assertSame('weight', $sumWx->weight);
        $this->assertSame(CompanionSourceTransform::TimesWeight, $sumWx->sourceTransform);
        $this->assertTrue($sumWx->isInternal());

        $sumW = $byColumn['value_wavg__sum_w'];
        $this->assertSame(AggregateFunction::Sum, $sumW->function);
        // Override picks up the weight column as the companion's source.
        $this->assertSame('weight', $sumW->source);
        $this->assertNull($sumW->weight);
        $this->assertSame(CompanionSourceTransform::Identity, $sumW->sourceTransform);
        $this->assertTrue($sumW->isInternal());
    }

    public function test_companion_resolver_returns_both_column_names(): void
    {
        $companions = AggregateRegistry::weightedAvgCompanionsFor(WeightedArea::class);

        $this->assertArrayHasKey('value_wavg', $companions);
        $this->assertSame('value_wavg__sum_wx', $companions['value_wavg']['sum_wx']);
        $this->assertSame('value_wavg__sum_w', $companions['value_wavg']['sum_w']);
    }
}
