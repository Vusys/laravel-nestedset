<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\TypedArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Phase 4 write-path tests for filtered aggregate maintenance.
 *
 * Tree shape used throughout: parent (id=1) with one child (id=2).
 *
 *  Parent  lft=1 rgt=4 depth=0 parent_id=null
 *  └── Child  lft=2 rgt=3 depth=1 parent_id=1
 *
 * All stored aggregate columns start at 0 on the parent.
 * The child row is inserted or manipulated by each test.
 */
final class FilteredDeltaMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();

        // Insert parent row with explicit placement and all aggregates at 0.
        DB::table('typed_areas')->insert([
            'id' => 1,
            'name' => 'Parent',
            'tickets' => 0,
            'type' => null,
            'lft' => 1,
            'rgt' => 4,
            'depth' => 0,
            'parent_id' => null,
            'fire_tickets' => 0,
            'fire_count' => 0,
            'water_max' => null,
            'has_tickets' => 0,
        ]);

        $this->syncSequence('typed_areas');
    }

    private function asInt(mixed $value): int
    {
        if ($value === null) {
            $this->fail('Expected numeric, got null.');
        }
        if (! is_numeric($value)) {
            $this->fail('Expected numeric, got '.get_debug_type($value));
        }

        return (int) $value;
    }

    private function insertChild(int $tickets, ?string $type): TypedArea
    {
        $child = new TypedArea(['name' => 'Child', 'tickets' => $tickets, 'type' => $type]);
        $parent = TypedArea::find(1);
        $this->assertNotNull($parent);
        $child->appendToNode($parent)->save();

        return $child;
    }

    // ----------------------------------------------------------------
    // Create: filter guards on applyAggregateOnCreate
    // ----------------------------------------------------------------

    public function test_create_fire_node_increments_parent_fire_sum(): void
    {
        $this->insertChild(20, 'fire');

        $parent = TypedArea::find(1);
        $this->assertNotNull($parent);
        $this->assertSame(20, $this->asInt($parent->fire_tickets));
    }

    public function test_create_water_node_does_not_increment_parent_fire_sum(): void
    {
        $this->insertChild(20, 'water');

        $parent = TypedArea::find(1);
        $this->assertNotNull($parent);
        $this->assertSame(0, $this->asInt($parent->fire_tickets));
    }

    public function test_create_fire_node_increments_fire_count(): void
    {
        $this->insertChild(10, 'fire');

        $parent = TypedArea::find(1);
        $this->assertNotNull($parent);
        $this->assertSame(1, $this->asInt($parent->fire_count));
    }

    public function test_not_null_count_increments_on_create(): void
    {
        // has_tickets uses filterNotNull: 'tickets'; tickets=5 (non-null) should increment.
        $this->insertChild(5, null);

        $parent = TypedArea::find(1);
        $this->assertNotNull($parent);
        $this->assertSame(1, $this->asInt($parent->has_tickets));
    }

    // ----------------------------------------------------------------
    // Update: delta maintenance with filter awareness
    // ----------------------------------------------------------------

    public function test_update_source_increments_fire_sum_for_fire_node(): void
    {
        $child = $this->insertChild(10, 'fire');
        $child->refresh();

        $child->tickets = 30;
        $child->save();

        $parent = TypedArea::find(1);
        $this->assertNotNull($parent);
        $this->assertSame(30, $this->asInt($parent->fire_tickets));
    }

    public function test_reclassify_to_fire_adds_contribution(): void
    {
        $child = $this->insertChild(20, 'water');

        // Parent's fire_tickets should be 0 at this point.
        $parentBefore = TypedArea::find(1);
        $this->assertNotNull($parentBefore);
        $this->assertSame(0, $this->asInt($parentBefore->fire_tickets));

        $child->refresh();
        $child->type = 'fire';
        $child->save();

        $parent = TypedArea::find(1);
        $this->assertNotNull($parent);
        $this->assertSame(20, $this->asInt($parent->fire_tickets));
    }

    public function test_reclassify_away_from_fire_removes_contribution(): void
    {
        $child = $this->insertChild(20, 'fire');

        // Verify initial state.
        $parentBefore = TypedArea::find(1);
        $this->assertNotNull($parentBefore);
        $this->assertSame(20, $this->asInt($parentBefore->fire_tickets));

        $child->refresh();
        $child->type = 'water';
        $child->save();

        $parent = TypedArea::find(1);
        $this->assertNotNull($parent);
        $this->assertSame(0, $this->asInt($parent->fire_tickets));
    }

    /**
     * Regression: moving a non-matching subtree into a node whose
     * filtered MIN/MAX is NULL must leave the destination's stored
     * value at NULL — not 0.
     *
     * The pre-fix bug: `collectMoveSubtreeContribution` ran
     * `self::numeric($node->getAttribute('water_max'))` on the moving
     * node. When the moving node's `water_max` was NULL (no matching
     * descendants in the moved subtree), `numeric()` returned 0,
     * propagated as a fake candidate extreme into the new chain's
     * cheap-delta MAX, which then overwrote the destination's NULL
     * with 0. Caught by the multi-mutation random walk's seed=1
     * step 33.
     */
    public function test_move_subtree_with_null_filtered_max_leaves_destination_null(): void
    {
        // A is type=null (so own type='water' filter does not match);
        // A starts as a leaf with no water descendants → water_max = NULL.
        $root = new TypedArea(['name' => 'r', 'tickets' => 10, 'type' => 'fire']);
        $root->saveAsRoot();
        $a = new TypedArea(['name' => 'A', 'tickets' => 1, 'type' => null]);
        $a->appendToNode($root->refresh())->save();

        $aBefore = TypedArea::find($a->id);
        $this->assertNotNull($aBefore);
        $this->assertNull($aBefore->water_max, 'precondition: A.water_max starts NULL');

        // Add a fire sibling at the root.
        $b = new TypedArea(['name' => 'B', 'tickets' => 1, 'type' => 'fire']);
        $b->appendToNode($root->refresh())->save();

        // Move B (a non-water node with no water descendants) under A.
        // A's water_max must stay NULL.
        $b->refresh();
        $b->appendToNode($a->refresh())->save();

        $aAfter = TypedArea::find($a->id);
        $this->assertNotNull($aAfter);
        $this->assertNull(
            $aAfter->water_max,
            'A.water_max should stay NULL after moving a non-water leaf under it',
        );
    }
}
