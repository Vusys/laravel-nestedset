<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Diff;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Branch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * `TreeDiff::apply()` removing a subtree on a hard-delete model. Every
 * `Removed` entry carried a per-node delete; on a hard-delete model the
 * top-most delete cascades and closes the gap, so a subsequent delete()
 * on an already-cascade-deleted child ran against stale bounds and
 * corrupted the tree. Only the top-most removed node must be deleted.
 */
final class TreeDiffApplyHardDeleteTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function removing_a_subtree_on_a_hard_delete_model_keeps_the_tree_intact(): void
    {
        $root = new Branch(['name' => 'Root', 'tickets' => 1]);
        $root->makeRoot()->save();

        $a = new Branch(['name' => 'A', 'tickets' => 1]);
        $a->appendToNode($root->refresh())->save();

        $b = new Branch(['name' => 'B', 'tickets' => 1]);
        $b->appendToNode($a->refresh())->save();

        $c = new Branch(['name' => 'C', 'tickets' => 1]);
        $c->appendToNode($a->refresh())->save();

        $before = [
            ['id' => $root->id, 'name' => 'Root', 'parent_id' => null],
            ['id' => $a->id, 'name' => 'A', 'parent_id' => $root->id],
            ['id' => $b->id, 'name' => 'B', 'parent_id' => $a->id],
            ['id' => $c->id, 'name' => 'C', 'parent_id' => $a->id],
        ];
        $after = [
            ['id' => $root->id, 'name' => 'Root', 'parent_id' => null],
        ];

        $diff = TreeDiff::between($before, $after);
        $this->assertSame(3, $diff->summary()['removed']);

        $diff->apply(Branch::class);

        $this->assertSame(1, Branch::query()->count(), 'only the root should survive');
        $this->assertNull(Branch::query()->find($a->id));
        $this->assertNull(Branch::query()->find($b->id));
        $this->assertNull(Branch::query()->find($c->id));
        $this->assertFalse(Branch::query()->findOrFail($root->id)->isBroken());
        $this->assertTreeIsIntact(Branch::class);
    }
}
