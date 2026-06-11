<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\UnplacedNodeException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

final class FixTreeSubtreeTest extends TestCase
{
    #[Test]
    public function anchored_fix_tree_reports_only_the_subtree_it_walked(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();
        $a1 = new Category(['name' => 'A1']);
        $a1->appendToNode($a->refresh())->save();
        $a2 = new Category(['name' => 'A2']);
        $a2->appendToNode($a->refresh())->save();
        $b = new Category(['name' => 'B']);
        $b->appendToNode($root->refresh())->save();

        // 5 nodes in the table; the A-subtree the anchor walks is 3.
        $result = Category::fixTree($a->refresh());

        $this->assertSame(3, $result->nodesUpdated, 'nodesUpdated must be the subtree walked, not the whole scope');
    }

    #[Test]
    public function fix_tree_rejects_an_unplaced_anchor(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $unplacedId = DB::table('categories')->insertGetId([
            'name' => 'Unplaced',
            'lft' => 0,
            'rgt' => 0,
            'depth' => 0,
            'parent_id' => null,
        ]);
        $this->syncSequence('categories');

        $unplaced = Category::query()->findOrFail($unplacedId);

        // Rebuilding a subtree anchored at an unplaced node would write
        // bounds starting at 0, colliding with the real root.
        $this->expectException(UnplacedNodeException::class);
        try {
            Category::fixTree($unplaced);
        } finally {
            // Leave the table clean for tearDown's integrity check.
            DB::table('categories')->where('id', $unplacedId)->delete();
        }
    }
}
