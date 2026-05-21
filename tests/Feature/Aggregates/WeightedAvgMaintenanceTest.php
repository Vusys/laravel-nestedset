<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\WeightedArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * M3 maintenance tests: WeightedAvg stays correct as rows are inserted,
 * updated, moved, and deleted. The display column rides on two
 * delta-maintained companions (`__sum_wx` = Σ(weight·value),
 * `__sum_w` = Σ(weight)) and is written from those on every mutation.
 */
final class WeightedAvgMaintenanceTest extends TestCase
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

    public function test_root_alone_has_weighted_average_equal_to_its_own_value(): void
    {
        $root = new WeightedArea(['name' => 'Root', 'value' => 50, 'weight' => 3]);
        $root->saveAsRoot();
        $root->refresh();

        // Σ(w·x) = 150, Σ(w) = 3 → 50.
        $this->assertEqualsWithDelta(50.0, $this->asFloat($root->value_wavg), 0.0001);
    }

    public function test_zero_weight_root_yields_null_weighted_average(): void
    {
        $root = new WeightedArea(['name' => 'Root', 'value' => 50, 'weight' => 0]);
        $root->saveAsRoot();
        $root->refresh();

        // Σ(w) = 0 → display is NULL (SQL `0 / 0 = NULL`).
        $this->assertNull($root->value_wavg);
    }

    public function test_weighted_average_across_subtree(): void
    {
        // Root(value=10, weight=1) > A(value=20, weight=2)
        //                          > B(value=30, weight=7)
        // Σ(w·x) = 10 + 40 + 210 = 260; Σ(w) = 1 + 2 + 7 = 10; weighted avg = 26.0.
        $root = new WeightedArea(['name' => 'Root', 'value' => 10, 'weight' => 1]);
        $root->saveAsRoot();

        $a = new WeightedArea(['name' => 'A', 'value' => 20, 'weight' => 2]);
        $a->appendToNode($root)->save();

        $b = new WeightedArea(['name' => 'B', 'value' => 30, 'weight' => 7]);
        $b->appendToNode($root->refresh())->save();

        $root->refresh();
        $this->assertEqualsWithDelta(26.0, $this->asFloat($root->value_wavg), 0.0001);

        // A alone is its own weighted average (only one contributor).
        $a->refresh();
        $this->assertEqualsWithDelta(20.0, $this->asFloat($a->value_wavg), 0.0001);
    }

    public function test_updating_value_propagates_to_ancestor_weighted_average(): void
    {
        $root = new WeightedArea(['name' => 'Root', 'value' => 10, 'weight' => 1]);
        $root->saveAsRoot();

        $a = new WeightedArea(['name' => 'A', 'value' => 20, 'weight' => 2]);
        $a->appendToNode($root)->save();

        // Bump A's value: 20 → 50. Now Σ(w·x) = 10 + 100 = 110; Σ(w) = 3.
        // Weighted avg = 110 / 3 ≈ 36.6667.
        $a->refresh()->update(['value' => 50]);
        $root->refresh();

        $this->assertEqualsWithDelta(110 / 3, $this->asFloat($root->value_wavg), 0.0001);
    }

    public function test_updating_weight_propagates_to_ancestor_weighted_average(): void
    {
        $root = new WeightedArea(['name' => 'Root', 'value' => 10, 'weight' => 1]);
        $root->saveAsRoot();

        $a = new WeightedArea(['name' => 'A', 'value' => 20, 'weight' => 2]);
        $a->appendToNode($root)->save();

        // Bump A's weight: 2 → 8. Now Σ(w·x) = 10 + 160 = 170; Σ(w) = 9.
        // Weighted avg = 170 / 9 ≈ 18.8889. Without the weight column on
        // the trigger set, this update wouldn't reach delta capture and
        // the ancestor's stored weighted average would silently drift.
        $a->refresh()->update(['weight' => 8]);
        $root->refresh();

        $this->assertEqualsWithDelta(170 / 9, $this->asFloat($root->value_wavg), 0.0001);
    }

    public function test_deleting_descendant_rolls_out_of_weighted_average(): void
    {
        $root = new WeightedArea(['name' => 'Root', 'value' => 10, 'weight' => 1]);
        $root->saveAsRoot();

        $a = new WeightedArea(['name' => 'A', 'value' => 20, 'weight' => 2]);
        $a->appendToNode($root)->save();

        $b = new WeightedArea(['name' => 'B', 'value' => 100, 'weight' => 5]);
        $b->appendToNode($root->refresh())->save();

        // Pre-delete: Σ(w·x) = 10 + 40 + 500 = 550, Σ(w) = 8, wavg = 68.75.
        // Delete B: Σ(w·x) = 50, Σ(w) = 3, wavg ≈ 16.6667.
        $b->refresh()->delete();
        $root->refresh();

        $this->assertEqualsWithDelta(50 / 3, $this->asFloat($root->value_wavg), 0.0001);
    }

    public function test_fix_aggregates_reproduces_delta_maintained_weighted_average(): void
    {
        $root = new WeightedArea(['name' => 'Root', 'value' => 5, 'weight' => 1]);
        $root->saveAsRoot();

        $values = [[7, 2], [12, 3], [18, 1], [22, 4]];
        foreach ($values as $i => [$v, $w]) {
            $node = new WeightedArea(['name' => 'Child'.$i, 'value' => $v, 'weight' => $w]);
            $node->appendToNode($root->refresh())->save();
        }

        $root->refresh();
        $deltaMaintained = $this->asFloat($root->value_wavg);

        WeightedArea::fixAggregates($root);
        $root->refresh();

        $this->assertEqualsWithDelta($deltaMaintained, $this->asFloat($root->value_wavg), 0.0001);
    }

    public function test_fix_aggregates_repairs_corrupted_display_column(): void
    {
        $root = new WeightedArea(['name' => 'Root', 'value' => 10, 'weight' => 1]);
        $root->saveAsRoot();

        $a = new WeightedArea(['name' => 'A', 'value' => 30, 'weight' => 2]);
        $a->appendToNode($root)->save();

        // Corrupt the stored weighted average; companions still good.
        WeightedArea::query()->where('id', $root->refresh()->getKey())->update([
            'value_wavg' => 999.9999,
        ]);

        WeightedArea::fixAggregates($root);
        $root->refresh();

        // Σ(w·x) = 10 + 60 = 70, Σ(w) = 3 → 70 / 3 ≈ 23.3333.
        $this->assertEqualsWithDelta(70 / 3, $this->asFloat($root->value_wavg), 0.0001);
    }
}
