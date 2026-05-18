<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

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
}
