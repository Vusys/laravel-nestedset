<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Listeners;

use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceTransform;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\StatsMonster;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Listener-side geometric / harmonic mean — mirrors the SQL aggregate
 * shape (`EXP(Σ LN(x) / n)` for geomean, `n / Σ(1/x)` for harmonic).
 * Auto-promoted companions carry the Ln / Recip source transforms so
 * non-positive (geomean) and zero (harmonic) rows are excluded from
 * both the sum and the count, matching the SQL `__count` semantics.
 */
final class ListenerMeanMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    private function asFloat(mixed $value): float
    {
        if ($value === null || ! is_numeric($value)) {
            $this->fail('Expected numeric, got '.get_debug_type($value));
        }

        return (float) $value;
    }

    /**
     * Seeds a 3-node tree with scores [2, 4, 8].
     *
     * geomean = (2·4·8)^(1/3) = 64^(1/3) = 4
     * harmonic = 3 / (1/2 + 1/4 + 1/8) = 3 / 0.875 ≈ 3.42857142…
     */
    private function seedThree(): StatsMonster
    {
        $root = new StatsMonster(['name' => 'r', 'type' => 'fire', 'score' => 2.0]);
        $root->saveAsRoot();

        foreach ([4.0, 8.0] as $i => $score) {
            (new StatsMonster(['name' => "c$i", 'type' => 'fire', 'score' => $score]))
                ->appendToNode($root)
                ->save();
        }

        return $root->refresh();
    }

    public function test_geometric_mean_value_matches_formula(): void
    {
        $root = $this->seedThree();
        $this->assertEqualsWithDelta(4.0, $this->asFloat($root->score_geomean), 1e-9);
    }

    public function test_harmonic_mean_value_matches_formula(): void
    {
        $root = $this->seedThree();
        $this->assertEqualsWithDelta(3.0 / 0.875, $this->asFloat($root->score_harmean), 1e-9);
    }

    public function test_geomean_companions_only_count_positive_rows(): void
    {
        $root = new StatsMonster(['name' => 'r', 'type' => 'fire', 'score' => 2.0]);
        $root->saveAsRoot();

        (new StatsMonster(['name' => 'c1', 'type' => 'fire', 'score' => 4.0]))
            ->appendToNode($root)
            ->save();
        // Non-positive: ln(0) undefined; row excluded from sum_log AND count.
        (new StatsMonster(['name' => 'c2', 'type' => 'fire', 'score' => 0.0]))
            ->appendToNode($root)
            ->save();

        $root->refresh();
        // Two positive rows {2, 4}: geomean = sqrt(8) ≈ 2.8284271…
        $this->assertEqualsWithDelta(sqrt(8.0), $this->asFloat($root->score_geomean), 1e-9);
        $this->assertSame(2, (int) $root->score_geomean__count);
    }

    public function test_harmonic_companions_only_count_non_zero_rows(): void
    {
        $root = new StatsMonster(['name' => 'r', 'type' => 'fire', 'score' => 2.0]);
        $root->saveAsRoot();

        (new StatsMonster(['name' => 'c1', 'type' => 'fire', 'score' => 4.0]))
            ->appendToNode($root)
            ->save();
        (new StatsMonster(['name' => 'c2', 'type' => 'fire', 'score' => 0.0]))
            ->appendToNode($root)
            ->save();

        $root->refresh();
        // Non-zero rows {2, 4}: harmonic = 2 / (1/2 + 1/4) = 2 / 0.75 ≈ 2.6666…
        $this->assertEqualsWithDelta(2.0 / 0.75, $this->asFloat($root->score_harmean), 1e-9);
        $this->assertSame(2, (int) $root->score_harmean__count);
    }

    public function test_empty_geomean_subtree_yields_null(): void
    {
        $root = new StatsMonster(['name' => 'r', 'type' => 'fire', 'score' => null]);
        $root->saveAsRoot();
        $root->refresh();

        $this->assertNull($root->score_geomean);
        $this->assertNull($root->score_harmean);
    }

    public function test_fix_aggregates_restores_drifted_means(): void
    {
        $root = $this->seedThree();

        StatsMonster::query()->where('id', $root->id)->update([
            'score_geomean' => 0,
            'score_geomean__sum_log' => 0,
            'score_geomean__count' => 0,
            'score_harmean' => 0,
            'score_harmean__sum_recip' => 0,
            'score_harmean__count' => 0,
        ]);

        StatsMonster::fixAggregates();

        $root->refresh();
        $this->assertEqualsWithDelta(4.0, $this->asFloat($root->score_geomean), 1e-9);
        $this->assertEqualsWithDelta(3.0 / 0.875, $this->asFloat($root->score_harmean), 1e-9);
    }

    /**
     * Companion transforms must be Ln for geomean's __sum_log and
     * __count, and Recip for harmonic's __sum_recip and __count.
     * Without these the companion would store Σ(x) and Count(*),
     * silently driving the display formula off.
     */
    public function test_companions_carry_domain_transforms(): void
    {
        $defs = AggregateRegistry::for(StatsMonster::class);

        /** @var array<string, ListenerAggregateDefinition> $by */
        $by = [];
        foreach ($defs as $def) {
            if ($def instanceof ListenerAggregateDefinition) {
                $by[$def->getColumn()] = $def;
            }
        }

        $this->assertSame(CompanionSourceTransform::Ln, $by['score_geomean__sum_log']->sourceTransform);
        $this->assertSame(CompanionSourceTransform::Ln, $by['score_geomean__count']->sourceTransform);
        $this->assertSame(CompanionSourceTransform::Recip, $by['score_harmean__sum_recip']->sourceTransform);
        $this->assertSame(CompanionSourceTransform::Recip, $by['score_harmean__count']->sourceTransform);
    }
}
