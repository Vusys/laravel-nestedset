<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Diff;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Diff\TreeDiffApplier;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Regression test for a TreeDiff::apply() bug surfaced by
 * DiffAddRemoveJourney.
 *
 * When a diff's removed set contains an ancestor AND its descendant on a
 * HARD-DELETE model, {@see TreeDiffApplier::doRemoves()} used to load
 * every removed row up front and delete() each with stale in-memory
 * bounds. Deleting the ancestor cascade-removed the descendant and
 * closed its gap; the loop then re-deleted the already-gone descendant
 * with stale bounds, double-closing an ancestor's gap and corrupting the
 * tree. A minimal trigger is a nested pair (x -> y) plus a sibling (z)
 * under a retained node. Soft-delete models renumber differently and
 * were unaffected.
 *
 * doRemoves now reloads each row against the live tree before deleting,
 * so a row a prior cascade already removed is skipped and survivors use
 * current bounds.
 */
final class TreeDiffNestedRemoveReproTest extends TestCase
{
    #[Test]
    public function inverse_diff_removing_a_nested_subtree_keeps_the_tree_intact(): void
    {
        // before: root -> keep  (two nodes, frozen snapshot)
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();

        $keep = new Area(['name' => 'keep', 'tickets' => 0]);
        $keep->appendToNode($root->refresh())->save();

        $before = Area::query()->defaultOrder()->get();

        // grow: root -> keep -> { x -> y, z }
        $x = new Area(['name' => 'x', 'tickets' => 0]);
        $x->appendToNode($keep->refresh())->save();

        $y = new Area(['name' => 'y', 'tickets' => 0]);
        $y->appendToNode($x->refresh())->save();

        $z = new Area(['name' => 'z', 'tickets' => 0]);
        $z->appendToNode($keep->refresh())->save();

        $after = Area::query()->defaultOrder()->get();

        // Inverse: remove x, y, z to restore root -> keep.
        $diff = TreeDiff::between($after, $before, 'name');
        $this->assertCount(3, $diff->removed, 'Expected x, y, z in the removed set.');

        $diff->apply(Area::class);

        $this->assertSame(
            [],
            array_filter(Area::countErrors()),
            'Tree corrupt after inverse-diff nested remove: '.json_encode(Area::countErrors()),
        );
    }
}
