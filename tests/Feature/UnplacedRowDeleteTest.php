<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Branch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * An unplaced row (lft = rgt = 0 — e.g. a raw insert, or a recovered
 * corruption) has no slot in the lft/rgt sequence. Hard-deleting it must
 * not run closeGap(0, 1), which would shift every placed row in the scope
 * down by one and corrupt the whole tree.
 */
final class UnplacedRowDeleteTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function hard_deleting_an_unplaced_row_leaves_the_tree_intact(): void
    {
        $root = new Branch(['name' => 'Root', 'tickets' => 0, 'active' => true]);
        $root->saveAsRoot();
        $child = new Branch(['name' => 'Child', 'tickets' => 0, 'active' => true]);
        $child->appendToNode($root->refresh())->save();

        $before = DB::table('branches')->orderBy('id')->get(['id', 'lft', 'rgt', 'depth'])->toArray();

        // Raw-insert an unplaced row (bypassing the placement guard).
        $unplacedId = DB::table('branches')->insertGetId([
            'name' => 'Unplaced',
            'tickets' => 0,
            'active' => 1,
            'lft' => 0,
            'rgt' => 0,
            'depth' => 0,
            'parent_id' => null,
        ]);
        $this->syncSequence('branches');

        $unplaced = Branch::query()->findOrFail($unplacedId);
        $this->assertFalse($unplaced->isPlacedInTree());

        $unplaced->delete();

        $after = DB::table('branches')->orderBy('id')->get(['id', 'lft', 'rgt', 'depth'])->toArray();
        $this->assertEquals($before, $after, 'deleting an unplaced row must not shift placed rows');
        $this->assertNull(Branch::query()->find($unplacedId), 'the unplaced row is gone');
        $this->assertFalse(Branch::isBroken());
    }
}
