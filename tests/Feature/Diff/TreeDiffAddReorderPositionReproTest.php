<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Diff;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Regression test for a second TreeDiff::apply() bug surfaced by
 * DiffAddRemoveJourney.
 *
 * apply() runs adds before moves. When a parent's final children come
 * from a MIX of added and moved-in nodes, an added node's recorded
 * sibling position (its rank in the final tree) can exceed the child
 * count that exists during the adds phase — the lower-ranked siblings
 * that arrive via later moves aren't present yet. The applier's add-time
 * reorder then asked moveToSiblingPosition() for an out-of-range slot and
 * threw "position must be in [0, N]".
 *
 * The applier now leaves an added node at the tail when its target
 * position is beyond the current child count; the moves phase settles
 * the final order.
 */
final class TreeDiffAddReorderPositionReproTest extends TestCase
{
    #[Test]
    public function adding_a_high_position_sibling_filled_by_a_later_move_applies_cleanly(): void
    {
        // before: root -> [m, b, c, a]  (four direct children)
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        foreach (['m', 'b', 'c', 'a'] as $name) {
            (new Area(['name' => $name, 'tickets' => 0]))->appendToNode($root->refresh())->save();
        }

        $before = Area::query()->defaultOrder()->get();

        // churn -> after: move m under b, delete a.
        // after: root -> [b -> [m], c]
        $b = Area::query()->where('name', 'b')->firstOrFail();
        $m = Area::query()->where('name', 'm')->firstOrFail();
        $m->appendToNode($b->refresh())->save();
        Area::query()->where('name', 'a')->firstOrFail()->delete();

        $after = Area::query()->defaultOrder()->get();

        // Inverse diff: adds `a` back at position 3 under root, while `m`
        // returns to root position 0 via a move that runs after the add.
        $diff = TreeDiff::between($after, $before, 'name');
        $this->assertCount(1, $diff->added, 'Expected `a` to be re-added.');

        $diff->apply(Area::class);

        $this->assertSame(
            [],
            array_filter(Area::countErrors()),
            'Tree corrupt after add+move round-trip: '.json_encode(Area::countErrors()),
        );

        $order = Area::query()->whereNotNull('parent_id')
            ->where('parent_id', Area::query()->where('name', 'root')->value('id'))
            ->defaultOrder()->pluck('name')->all();
        $this->assertSame(['m', 'b', 'c', 'a'], $order, 'Root children not restored to before order.');
    }
}
