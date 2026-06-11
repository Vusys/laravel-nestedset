<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Delta maintenance updates aggregate columns via raw SQL and does not
 * sync the in-memory model. A move or delete of the SAME held instance
 * therefore used to transfer/subtract stale aggregate values, producing
 * permanent silent drift. The delete hook (deleting) and the move path
 * now re-read the maintained aggregate columns from the DB before
 * consuming them — these pin each scenario.
 *
 * Every assertion deliberately avoids refresh() on the mutated instance:
 * the whole point is that a held, un-refreshed instance stays correct.
 */
final class StaleInstanceAggregateTest extends TestCase
{
    use InteractsWithTrees;

    /**
     * @return array{root: Area, a: Area, b: Area}
     */
    private function seedTree(): array
    {
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 10]);
        $a->appendToNode($root->refresh())->save();

        $b = new Area(['name' => 'B', 'tickets' => 20]);
        $b->appendToNode($root->refresh())->save();

        return ['root' => $root->refresh(), 'a' => $a->refresh(), 'b' => $b->refresh()];
    }

    #[Test]
    public function updating_a_source_column_then_deleting_the_same_instance_does_not_drift(): void
    {
        $tree = $this->seedTree();
        $a = $tree['a'];

        // Update the source column on the held instance and save. Delta
        // maintenance rewrites A.tickets_total in the DB but not in
        // memory — the held instance still reports the old total.
        $a->tickets = 50;
        $a->save();

        // Delete the SAME instance (no refresh). The deleted hook
        // subtracts A's stored total from the root; a stale read would
        // subtract 10 and leave the root at 50 instead of 20.
        $a->delete();

        $root = $tree['root']->refresh();
        $this->assertSame(20, $root->tickets_total, 'root should hold only B(20) after A is deleted');
        $this->assertAggregatesAreIntact(Area::class);
    }

    #[Test]
    public function appending_a_child_then_moving_the_parent_without_refresh_does_not_drift(): void
    {
        $tree = $this->seedTree();
        $a = $tree['a'];
        $b = $tree['b'];

        // Append a child under A. A.tickets_total grows in the DB
        // (10 + 7 = 17) but the held $a is stale (still 10).
        $child = new Area(['name' => 'A-child', 'tickets' => 7]);
        $child->appendToNode($a)->save();

        // Move A (with its subtree) under B using the stale instance.
        // The before-move hook subtracts A's subtree contribution from
        // the old chain and the after-move hook adds it to the new
        // chain; a stale 10 instead of 17 drifts both.
        $a->appendToNode($b->refresh())->save();

        $this->assertAggregatesAreIntact(Area::class);

        $b->refresh();
        $this->assertSame(37, $b->tickets_total, 'B(20) + A(10) + A-child(7) = 37');
    }

    #[Test]
    public function creating_a_leaf_then_immediately_moving_the_same_instance_does_not_drift(): void
    {
        $tree = $this->seedTree();
        $a = $tree['a'];
        $b = $tree['b'];

        // Create then immediately move the same instance. After the
        // create hook the in-memory tickets_total is still the unsynced
        // default (0); the move would transfer 0 instead of 5.
        $leaf = new Area(['name' => 'leaf', 'tickets' => 5]);
        $leaf->appendToNode($a)->save();
        $leaf->appendToNode($b->refresh())->save();

        $this->assertAggregatesAreIntact(Area::class);

        $b->refresh();
        $this->assertSame(25, $b->tickets_total, 'B(20) + leaf(5) = 25');
    }
}
