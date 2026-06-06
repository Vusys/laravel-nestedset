<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Listeners;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceTransform;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\StatsMonster;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Listener-side variance / stddev — mirrors the SQL aggregate behaviour
 * (`(n·SumSq − Sum²) / n²` for population variance, `sqrt(variance)` for
 * stddev). The auto-promoted companion columns (`__sum`, `__sum_sq`,
 * `__count`) stay consistent with the display column on every mutation
 * and after `fixAggregates()`.
 */
final class ListenerVarianceMaintenanceTest extends TestCase
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
     * Seeds a 4-node tree with scores [1, 3, 5, 7] (root + 3 children).
     *
     * mean   = 4
     * sumSq  = 1 + 9 + 25 + 49 = 84
     * popVar = (4·84 − 16²) / 4² = (336 − 256) / 16 = 5
     * stddev = sqrt(5) ≈ 2.2360679…
     */
    private function seedFour(): StatsMonster
    {
        $root = new StatsMonster(['name' => 'r', 'type' => 'fire', 'score' => 1.0]);
        $root->saveAsRoot();

        foreach ([3.0, 5.0, 7.0] as $i => $score) {
            (new StatsMonster(['name' => "c$i", 'type' => 'fire', 'score' => $score]))
                ->appendToNode($root)
                ->save();
        }

        return $root->refresh();
    }

    #[Test]
    public function variance_value_matches_textbook_formula(): void
    {
        $root = $this->seedFour();

        $this->assertEqualsWithDelta(5.0, $this->asFloat($root->score_variance), 1e-9);
    }

    #[Test]
    public function stddev_value_matches_sqrt_of_variance(): void
    {
        $root = $this->seedFour();

        $this->assertEqualsWithDelta(sqrt(5.0), $this->asFloat($root->score_stddev), 1e-9);
    }

    #[Test]
    public function companion_columns_track_running_totals(): void
    {
        $root = $this->seedFour();

        $this->assertEqualsWithDelta(16.0, $this->asFloat($root->score_variance__sum), 1e-9);
        $this->assertEqualsWithDelta(84.0, $this->asFloat($root->score_variance__sum_sq), 1e-9);
        $this->assertSame(4, (int) $root->score_variance__count);
    }

    #[Test]
    public function inserting_a_node_rerolls_variance(): void
    {
        $root = $this->seedFour();

        // Adding score=9 → values {1,3,5,7,9}, mean=5, sumSq=165
        // popVar = (5·165 − 25²) / 25 = (825 − 625) / 25 = 8
        (new StatsMonster(['name' => 'extra', 'type' => 'fire', 'score' => 9.0]))
            ->appendToNode($root)
            ->save();

        $root->refresh();
        $this->assertEqualsWithDelta(8.0, $this->asFloat($root->score_variance), 1e-9);
        $this->assertSame(5, (int) $root->score_variance__count);
    }

    #[Test]
    public function deleting_a_node_rerolls_variance(): void
    {
        $root = $this->seedFour();
        $third = StatsMonster::query()->where('name', 'c2')->firstOrFail();
        $third->delete();

        // Remaining values {1,3,5}, mean=3, sumSq=35
        // popVar = (3·35 − 9²) / 9 = (105 − 81) / 9 ≈ 2.6666…
        $root->refresh();
        $this->assertEqualsWithDelta(8.0 / 3.0, $this->asFloat($root->score_variance), 1e-9);
        $this->assertSame(3, (int) $root->score_variance__count);
    }

    #[Test]
    public function single_value_variance_is_zero_and_stddev_is_zero(): void
    {
        $root = new StatsMonster(['name' => 'r', 'type' => 'fire', 'score' => 42.0]);
        $root->saveAsRoot();
        $root->refresh();

        $this->assertSame(0.0, $this->asFloat($root->score_variance));
        $this->assertSame(0.0, $this->asFloat($root->score_stddev));
        $this->assertSame(1, (int) $root->score_variance__count);
    }

    #[Test]
    public function empty_score_subtree_yields_null_variance(): void
    {
        // Score is null → ScoreListener returns null → row excluded.
        $root = new StatsMonster(['name' => 'r', 'type' => 'fire', 'score' => null]);
        $root->saveAsRoot();
        $root->refresh();

        $this->assertNull($root->score_variance);
        $this->assertNull($root->score_stddev);
        $this->assertSame(0, (int) $root->score_variance__count);
    }

    #[Test]
    public function fix_aggregates_restores_drifted_variance(): void
    {
        $root = $this->seedFour();

        StatsMonster::query()->where('id', $root->id)->update([
            'score_variance' => 0,
            'score_variance__sum' => 0,
            'score_variance__sum_sq' => 0,
            'score_variance__count' => 0,
        ]);

        StatsMonster::fixAggregates();

        $root->refresh();
        $this->assertEqualsWithDelta(5.0, $this->asFloat($root->score_variance), 1e-9);
        $this->assertEqualsWithDelta(16.0, $this->asFloat($root->score_variance__sum), 1e-9);
        $this->assertEqualsWithDelta(84.0, $this->asFloat($root->score_variance__sum_sq), 1e-9);
        $this->assertSame(4, (int) $root->score_variance__count);
    }

    /**
     * Auto-promotion must produce companions with the right
     * sourceTransform so __sum_sq stores Σ(x²), not Σ(x).
     */
    #[Test]
    public function sum_sq_companion_carries_square_transform(): void
    {
        $defs = AggregateRegistry::for(StatsMonster::class);

        $found = null;
        foreach ($defs as $def) {
            if ($def instanceof ListenerAggregateDefinition
                && $def->getColumn() === 'score_variance__sum_sq'
            ) {
                $found = $def;
                break;
            }
        }

        $this->assertNotNull($found, '__sum_sq companion should be auto-promoted');
        $this->assertSame(
            CompanionSourceTransform::Square,
            $found->sourceTransform,
        );
    }
}
