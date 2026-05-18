<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\Pokemon;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Phase 8 maintenance tests: listener-based aggregate columns
 * (weighted_power, fire_count) stay in sync as Pokemon nodes are
 * created, updated, and deleted.
 *
 * The Pokemon fixture declares:
 *   - weighted_power: Sum of (base_power × level) via WeightedPowerListener
 *   - fire_count: Sum of 1-when-fire via FireCountListener
 */
final class ListenerAggregateMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    private function asInt(mixed $value): int
    {
        if ($value === null || ! is_numeric($value)) {
            $this->fail('Expected numeric, got '.get_debug_type($value));
        }

        return (int) $value;
    }

    // ----------------------------------------------------------------
    // Create: listener aggregates initialise correctly on creation
    // ----------------------------------------------------------------

    public function test_create_pokemon_updates_weighted_power_on_ancestors(): void
    {
        $root = new Pokemon(['name' => 'Root', 'type' => 'water', 'base_power' => 2, 'level' => 5]);
        $root->saveAsRoot();
        $root->refresh();

        // Root's own weighted_power = 2 * 5 = 10
        $this->assertSame(10, $this->asInt($root->weighted_power));

        $child = new Pokemon(['name' => 'Child', 'type' => 'water', 'base_power' => 10, 'level' => 3]);
        $child->appendToNode($root)->save();

        $root->refresh();

        // Root's weighted_power = root's own (10) + child's (10 * 3 = 30) = 40
        $this->assertSame(40, $this->asInt($root->weighted_power));

        // Child's weighted_power = its own (10 * 3 = 30)
        $child->refresh();
        $this->assertSame(30, $this->asInt($child->weighted_power));
    }

    public function test_create_pokemon_updates_fire_count_on_ancestors(): void
    {
        $root = new Pokemon(['name' => 'RootFire', 'type' => 'fire', 'base_power' => 5, 'level' => 1]);
        $root->saveAsRoot();
        $root->refresh();

        // Root is fire → fire_count = 1
        $this->assertSame(1, $this->asInt($root->fire_count));

        $child = new Pokemon(['name' => 'ChildFire', 'type' => 'fire', 'base_power' => 5, 'level' => 1]);
        $child->appendToNode($root)->save();

        $root->refresh();

        // Root now has fire_count = 2 (itself + child)
        $this->assertSame(2, $this->asInt($root->fire_count));
    }

    // ----------------------------------------------------------------
    // Update: listener aggregates propagate deltas correctly
    // ----------------------------------------------------------------

    public function test_update_base_power_updates_weighted_power_on_ancestors(): void
    {
        $root = new Pokemon(['name' => 'Root', 'type' => 'water', 'base_power' => 1, 'level' => 1]);
        $root->saveAsRoot();

        $child = new Pokemon(['name' => 'Child', 'type' => 'water', 'base_power' => 5, 'level' => 2]);
        $child->appendToNode($root)->save();

        $root->refresh();
        $child->refresh();

        // root: 1*1=1, child: 5*2=10, root total = 11
        $this->assertSame(11, $this->asInt($root->weighted_power));

        // Update child's base_power from 5 to 10 → contribution becomes 10*2=20, delta=+10
        $child->base_power = 10;
        $child->save();

        $root->refresh();

        // root total = 1 + 20 = 21
        $this->assertSame(21, $this->asInt($root->weighted_power));
    }

    public function test_update_type_to_fire_increments_fire_count(): void
    {
        $root = new Pokemon(['name' => 'Root', 'type' => 'water', 'base_power' => 1, 'level' => 1]);
        $root->saveAsRoot();

        $child = new Pokemon(['name' => 'Child', 'type' => 'water', 'base_power' => 1, 'level' => 1]);
        $child->appendToNode($root)->save();

        $root->refresh();
        $this->assertSame(0, $this->asInt($root->fire_count));

        // Update child type to fire → fire_count delta = +1
        $child->type = 'fire';
        $child->save();

        $root->refresh();
        $this->assertSame(1, $this->asInt($root->fire_count));
    }

    public function test_update_type_from_fire_decrements_fire_count(): void
    {
        $root = new Pokemon(['name' => 'Root', 'type' => 'water', 'base_power' => 1, 'level' => 1]);
        $root->saveAsRoot();

        $child = new Pokemon(['name' => 'Child', 'type' => 'fire', 'base_power' => 1, 'level' => 1]);
        $child->appendToNode($root)->save();

        $root->refresh();
        $this->assertSame(1, $this->asInt($root->fire_count));

        // Change child's type away from fire → fire_count delta = -1
        $child->type = 'water';
        $child->save();

        $root->refresh();
        $this->assertSame(0, $this->asInt($root->fire_count));
    }

    // ----------------------------------------------------------------
    // Delete: listener aggregates subtract contributions on deletion
    // ----------------------------------------------------------------

    public function test_delete_pokemon_updates_listener_aggregates_on_ancestors(): void
    {
        $root = new Pokemon(['name' => 'Root', 'type' => 'water', 'base_power' => 2, 'level' => 2]);
        $root->saveAsRoot();

        // child: base_power=3, level=4 → weighted_power contrib = 12; type='fire' → fire_count contrib = 1
        $child = new Pokemon(['name' => 'Child', 'type' => 'fire', 'base_power' => 3, 'level' => 4]);
        $child->appendToNode($root)->save();

        $root->refresh();
        $child->refresh();

        // root: 2*2=4, child: 3*4=12, total = 16; fire_count = 1
        $this->assertSame(16, $this->asInt($root->weighted_power));
        $this->assertSame(1, $this->asInt($root->fire_count));

        $child->delete();

        $root->refresh();

        // After deleting child: root weighted_power = 4 (only itself), fire_count = 0
        $this->assertSame(4, $this->asInt($root->weighted_power));
        $this->assertSame(0, $this->asInt($root->fire_count));
    }

    // ----------------------------------------------------------------
    // No-op: listener aggregates not updated when watch column unchanged
    // ----------------------------------------------------------------

    public function test_listener_aggregate_not_updated_when_watch_column_unchanged(): void
    {
        $root = new Pokemon(['name' => 'Root', 'type' => 'water', 'base_power' => 1, 'level' => 1]);
        $root->saveAsRoot();

        $child = new Pokemon(['name' => 'Child', 'type' => 'water', 'base_power' => 5, 'level' => 2]);
        $child->appendToNode($root)->save();

        $root->refresh();
        $beforeWeightedPower = $this->asInt($root->weighted_power);

        // Update only name — not in watchColumns for WeightedPowerListener or FireCountListener
        $child->name = 'ChildRenamed';
        $child->save();

        $root->refresh();

        // Root's weighted_power must be unchanged
        $this->assertSame($beforeWeightedPower, $this->asInt($root->weighted_power));
    }

    // ----------------------------------------------------------------
    // Float-listener path: HalfWeightedPowerListener returns
    // (base_power * level) / 2 as a float. Regression guard against
    // truncating-to-int at the maintenance boundary, which would lose
    // every odd-product node's .5.
    // ----------------------------------------------------------------

    private function asFloat(mixed $value): float
    {
        if ($value === null || ! is_numeric($value)) {
            $this->fail('Expected numeric, got '.get_debug_type($value));
        }

        return (float) $value;
    }

    public function test_float_listener_preserves_half_on_create(): void
    {
        $root = new Pokemon(['name' => 'Root', 'type' => 'water', 'base_power' => 3, 'level' => 5]);
        $root->saveAsRoot();
        $root->refresh();

        // Root's half_weighted_power = (3 * 5) / 2 = 7.5
        $this->assertSame(7.5, $this->asFloat($root->half_weighted_power));

        $child = new Pokemon(['name' => 'Child', 'type' => 'water', 'base_power' => 5, 'level' => 3]);
        $child->appendToNode($root)->save();
        $root->refresh();

        // Root rollup = 7.5 + (5 * 3) / 2 = 7.5 + 7.5 = 15.0
        $this->assertSame(15.0, $this->asFloat($root->half_weighted_power));
    }

    public function test_float_listener_preserves_half_on_update(): void
    {
        $root = new Pokemon(['name' => 'Root', 'type' => 'water', 'base_power' => 2, 'level' => 4]);
        $root->saveAsRoot();

        $child = new Pokemon(['name' => 'Child', 'type' => 'water', 'base_power' => 2, 'level' => 2]);
        $child->appendToNode($root)->save();
        $root->refresh();

        // Root = (2*4)/2 + (2*2)/2 = 4 + 2 = 6.0
        $this->assertSame(6.0, $this->asFloat($root->half_weighted_power));

        // Bump child's level from 2 → 3 → contribution moves from 2.0 to 3.0
        // (delta = +1.0). Root must end at 5.0 + 2.0 = 7.0 — not 6.0
        // (which would happen if the float delta were truncated to int 0).
        // To exhibit the regression we need the delta to be non-integer; use
        // base_power=3, level: 1→2 changes contribution from 1.5 to 3.0 (delta = +1.5).
        $child->base_power = 3;
        $child->level = 1;
        $child->save();
        $root->refresh();
        // Root = (2*4)/2 + (3*1)/2 = 4 + 1.5 = 5.5
        $this->assertSame(5.5, $this->asFloat($root->half_weighted_power));

        $child->level = 2;
        $child->save();
        $root->refresh();
        // Root = 4 + (3*2)/2 = 4 + 3 = 7.0
        $this->assertSame(7.0, $this->asFloat($root->half_weighted_power));
    }

    public function test_float_listener_preserves_half_on_delete(): void
    {
        $root = new Pokemon(['name' => 'Root', 'type' => 'water', 'base_power' => 2, 'level' => 2]);
        $root->saveAsRoot();

        $child = new Pokemon(['name' => 'Child', 'type' => 'water', 'base_power' => 3, 'level' => 1]);
        $child->appendToNode($root)->save();
        $root->refresh();
        // Refresh child so its stored aggregate columns are loaded before
        // delete reads them to compute the subtraction delta.
        $child->refresh();

        // Root = (2*2)/2 + (3*1)/2 = 2 + 1.5 = 3.5
        $this->assertSame(3.5, $this->asFloat($root->half_weighted_power));

        $child->delete();
        $root->refresh();

        // Removing the 1.5 contribution must leave 2.0. An int-truncated
        // delta would leave 3.5 - 1 = 2.5 instead.
        $this->assertSame(2.0, $this->asFloat($root->half_weighted_power));
    }

    // ----------------------------------------------------------------
    // Listener MIN recompute path: deleting / updating a node that
    // held the stored minimum triggers applyListenerMinMaxRecomputes
    // for each affected ancestor. Covers the batched-read refactor.
    // ----------------------------------------------------------------

    public function test_min_listener_recomputes_on_delete_of_extremum_holder(): void
    {
        $root = new Pokemon(['name' => 'Root', 'type' => 'water', 'base_power' => 1, 'level' => 5]);
        $root->saveAsRoot();

        $a = new Pokemon(['name' => 'A', 'type' => 'water', 'base_power' => 1, 'level' => 3]);
        $a->appendToNode($root)->save();

        $b = new Pokemon(['name' => 'B', 'type' => 'water', 'base_power' => 1, 'level' => 7]);
        $b->appendToNode($root)->save();

        $root->refresh();
        $a->refresh();

        // Root's weakest_level = min(5, 3, 7) = 3 (held by A).
        $this->assertSame(3, $this->asInt($root->weakest_level));
        $this->assertSame(3, $this->asInt($a->weakest_level));

        // Deleting A removes the extremum holder. Root must recompute
        // to min(5, 7) = 5.
        $a->delete();
        $root->refresh();

        $this->assertSame(5, $this->asInt($root->weakest_level));
    }

    public function test_min_listener_recompute_batches_subtree_reads(): void
    {
        $root = new Pokemon(['name' => 'Root', 'type' => 'water', 'base_power' => 1, 'level' => 10]);
        $root->saveAsRoot();

        // Two intermediate ancestors deep, with a leaf at the bottom.
        $a = new Pokemon(['name' => 'A', 'type' => 'water', 'base_power' => 1, 'level' => 8]);
        $a->appendToNode($root)->save();

        $b = new Pokemon(['name' => 'B', 'type' => 'water', 'base_power' => 1, 'level' => 6]);
        $b->appendToNode($a)->save();

        $c = new Pokemon(['name' => 'C', 'type' => 'water', 'base_power' => 1, 'level' => 4]);
        $c->appendToNode($b)->save();

        $root->refresh();
        $c->refresh();
        $this->assertSame(4, $this->asInt($root->weakest_level));

        // Reset query log; delete C and observe how many SELECT/UPDATE
        // statements the recompute path issues. The pre-refactor code
        // ran one SELECT per (ancestor × listener-Min/Max definition);
        // with one Min listener and 3 ancestors above C that was 3
        // SELECTs plus per-ancestor UPDATEs. The batched read brings
        // the SELECT count to a small constant (load ancestors once,
        // load nodes-under-topmost-ancestor once).
        DB::flushQueryLog();
        DB::enableQueryLog();

        $c->delete();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $selectCount = 0;
        foreach ($queries as $entry) {
            if (stripos($entry['query'], 'select') === 0) {
                $selectCount++;
            }
        }

        // The batched recompute issues exactly two SELECTs regardless
        // of ancestor depth: one to load the ancestor models and one
        // to load all in-scope nodes under the topmost ancestor's
        // bounding box. The pre-batched code did one descendant
        // SELECT per (ancestor × Min/Max definition) — with 3
        // ancestors above C that would be 4+ SELECTs and grows with
        // depth.
        $this->assertLessThanOrEqual(
            2,
            $selectCount,
            "Listener Min recompute should batch subtree reads, but {$selectCount} SELECT statements ran.",
        );

        $root->refresh();
        // After deleting C (level=4), root's weakest_level = min(10, 8, 6) = 6.
        $this->assertSame(6, $this->asInt($root->weakest_level));
    }
}
