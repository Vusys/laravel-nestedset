<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Direct tests for the inspection methods on `HasNodeInspection` — the
 * `isRoot` / `isLeaf` / `isChild` / `isDescendantOf` / `isAncestorOf` /
 * `getSubtreeSize` / `getDescendantCount` / `hasMoved` API the README
 * features. These methods were exercised only indirectly via mutation
 * and relation tests; the bodies are simple, but they're public
 * surface so worth a direct check.
 *
 * Tree shape used throughout (matches `EagerLoadingTest`):
 *
 *   Root         lft=1  rgt=10  depth=0
 *   ├── Child A  lft=2  rgt=7   depth=1
 *   │   ├── AA   lft=3  rgt=4   depth=2
 *   │   └── AB   lft=5  rgt=6   depth=2
 *   └── Child B  lft=8  rgt=9   depth=1
 */
final class NodeInspectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root',    'lft' => 1, 'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'Child A', 'lft' => 2, 'rgt' => 7,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',      'lft' => 3, 'rgt' => 4,  'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'AB',      'lft' => 5, 'rgt' => 6,  'depth' => 2, 'parent_id' => 2],
            ['id' => 5, 'name' => 'Child B', 'lft' => 8, 'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
        ]);
    }

    private function find(int $id): Category
    {
        $row = Category::query()->find($id);
        if ($row === null) {
            $this->fail("Category {$id} not found");
        }

        return $row;
    }

    #[Test]
    public function is_root_returns_true_only_for_nodes_with_null_parent(): void
    {
        $this->assertTrue($this->find(1)->isRoot());      // Root
        $this->assertFalse($this->find(2)->isRoot());     // Child A
        $this->assertFalse($this->find(3)->isRoot());     // AA
    }

    #[Test]
    public function is_leaf_returns_true_only_for_nodes_with_no_descendants(): void
    {
        $this->assertFalse($this->find(1)->isLeaf());     // Root
        $this->assertFalse($this->find(2)->isLeaf());     // Child A (has AA, AB)
        $this->assertTrue($this->find(3)->isLeaf());      // AA
        $this->assertTrue($this->find(5)->isLeaf());      // Child B
    }

    #[Test]
    public function is_child_is_the_inverse_of_is_root(): void
    {
        $this->assertFalse($this->find(1)->isChild());    // Root is not a child
        $this->assertTrue($this->find(2)->isChild());     // Child A is
        $this->assertTrue($this->find(3)->isChild());     // AA is
    }

    #[Test]
    public function is_descendant_of_uses_strict_bounds_containment(): void
    {
        $root = $this->find(1);
        $childA = $this->find(2);
        $aa = $this->find(3);
        $childB = $this->find(5);

        // Strict containment: AA ⊂ Child A ⊂ Root.
        $this->assertTrue($aa->isDescendantOf($childA));
        $this->assertTrue($aa->isDescendantOf($root));
        $this->assertTrue($childA->isDescendantOf($root));

        // Not symmetric.
        $this->assertFalse($root->isDescendantOf($aa));
        $this->assertFalse($childA->isDescendantOf($aa));

        // Siblings are not descendants of each other.
        $this->assertFalse($childA->isDescendantOf($childB));
        $this->assertFalse($childB->isDescendantOf($childA));

        // A node is not a strict descendant of itself.
        $this->assertFalse($aa->isDescendantOf($aa));
    }

    #[Test]
    public function is_ancestor_of_is_the_inverse_of_is_descendant_of(): void
    {
        $root = $this->find(1);
        $childA = $this->find(2);
        $aa = $this->find(3);

        $this->assertTrue($root->isAncestorOf($childA));
        $this->assertTrue($root->isAncestorOf($aa));
        $this->assertTrue($childA->isAncestorOf($aa));

        $this->assertFalse($aa->isAncestorOf($childA));
        $this->assertFalse($aa->isAncestorOf($root));

        // A node is not a strict ancestor of itself.
        $this->assertFalse($aa->isAncestorOf($aa));
    }

    #[Test]
    public function get_subtree_size_returns_rgt_minus_lft_plus_one(): void
    {
        $this->assertSame(10, $this->find(1)->getSubtreeSize());   // Root: 10-1+1
        $this->assertSame(6, $this->find(2)->getSubtreeSize());    // Child A: 7-2+1
        $this->assertSame(2, $this->find(3)->getSubtreeSize());    // AA leaf: 4-3+1
        $this->assertSame(2, $this->find(5)->getSubtreeSize());    // Child B leaf: 9-8+1
    }

    #[Test]
    public function get_descendant_count_derives_count_from_bounds(): void
    {
        // (rgt - lft - 1) / 2
        $this->assertSame(4, $this->find(1)->getDescendantCount()); // Root: (10-1-1)/2=4
        $this->assertSame(2, $this->find(2)->getDescendantCount()); // Child A: (7-2-1)/2=2
        $this->assertSame(0, $this->find(3)->getDescendantCount()); // leaf AA
        $this->assertSame(0, $this->find(5)->getDescendantCount()); // leaf Child B
    }

    #[Test]
    public function has_moved_flips_after_a_structural_mutation(): void
    {
        $childA = $this->find(2);
        $this->assertFalse($childA->hasMoved());

        $childB = $this->find(5);
        $childA->appendToNode($childB)->save();

        $this->assertTrue($childA->hasMoved());
    }

    #[Test]
    public function subtree_size_and_descendant_count_are_bounds_consistent(): void
    {
        // Invariant tying the two derivations together: every descendant
        // contributes one lft and one rgt, and the node itself adds the +2,
        // so subtreeSize == 2 * descendantCount + 2 for any node.
        foreach ([1, 2, 3, 4, 5] as $id) {
            $node = $this->find($id);
            $this->assertSame(
                $node->getSubtreeSize(),
                2 * $node->getDescendantCount() + 2,
                "subtree-size/descendant-count mismatch on node {$id}",
            );
        }
    }
}
