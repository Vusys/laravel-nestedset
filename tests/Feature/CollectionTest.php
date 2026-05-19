<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\NodeCollection;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Tree shape used throughout this test:
 *
 *  Root        lft=1  rgt=10  depth=0
 *    Child A   lft=2  rgt=7   depth=1
 *      AA      lft=3  rgt=4   depth=2
 *      AB      lft=5  rgt=6   depth=2
 *    Child B   lft=8  rgt=9   depth=1
 */
final class CollectionTest extends TestCase
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

    /** @return NodeCollection<int, Category> */
    private function all(): NodeCollection
    {
        /** @var NodeCollection<int, Category> $rows */
        $rows = Category::query()->orderBy('lft')->get();

        return $rows;
    }

    // ----------------------------------------------------------------
    // newCollection wiring
    // ----------------------------------------------------------------

    public function test_get_returns_node_collection(): void
    {
        $this->assertInstanceOf(NodeCollection::class, $this->all());
    }

    // ----------------------------------------------------------------
    // linkNodes
    // ----------------------------------------------------------------

    public function test_link_nodes_sets_parent_relation(): void
    {
        $rows = $this->all()->linkNodes();
        $byId = $rows->keyBy('id');

        $aa = $byId->get(3);
        $childA = $byId->get(2);
        $root = $byId->get(1);

        $this->assertInstanceOf(Category::class, $aa);
        $this->assertInstanceOf(Category::class, $childA);
        $this->assertInstanceOf(Category::class, $root);

        $this->assertSame($childA, $aa->getRelation('parent'));
        $this->assertSame($root, $childA->getRelation('parent'));
        $this->assertNull($root->getRelation('parent'));
    }

    public function test_link_nodes_sets_children_relation(): void
    {
        $rows = $this->all()->linkNodes();
        $byId = $rows->keyBy('id');

        $root = $byId->get(1);
        $childA = $byId->get(2);
        $aa = $byId->get(3);

        $this->assertInstanceOf(Category::class, $root);
        $this->assertInstanceOf(Category::class, $childA);
        $this->assertInstanceOf(Category::class, $aa);

        $rootChildren = $root->getRelation('children');
        $this->assertInstanceOf(Collection::class, $rootChildren);
        $this->assertSame(
            ['Child A', 'Child B'],
            $rootChildren->sortBy('lft')->pluck('name')->all(),
        );

        $childAChildren = $childA->getRelation('children');
        $this->assertInstanceOf(Collection::class, $childAChildren);
        $this->assertCount(2, $childAChildren);

        $aaChildren = $aa->getRelation('children');
        $this->assertInstanceOf(Collection::class, $aaChildren);
        $this->assertCount(0, $aaChildren);
    }

    public function test_link_nodes_on_empty_collection_returns_self(): void
    {
        $empty = new NodeCollection;
        $this->assertSame($empty, $empty->linkNodes());
    }

    // ----------------------------------------------------------------
    // toTree
    // ----------------------------------------------------------------

    public function test_to_tree_returns_single_root_for_complete_tree(): void
    {
        $tree = $this->all()->toTree();

        $this->assertCount(1, $tree);
        $top = $tree->first();
        $this->assertInstanceOf(Category::class, $top);
        $this->assertSame('Root', $top->name);
    }

    public function test_to_tree_links_children_recursively(): void
    {
        $tree = $this->all()->toTree();
        $root = $tree->first();
        $this->assertInstanceOf(Category::class, $root);

        $rootChildren = $root->getRelation('children');
        $this->assertInstanceOf(Collection::class, $rootChildren);
        $this->assertSame(
            ['Child A', 'Child B'],
            $rootChildren->sortBy('lft')->pluck('name')->all(),
        );

        $childA = $rootChildren->sortBy('lft')->first();
        $this->assertInstanceOf(Category::class, $childA);
        $aGrandChildren = $childA->getRelation('children');
        $this->assertInstanceOf(Collection::class, $aGrandChildren);
        $this->assertSame(
            ['AA', 'AB'],
            $aGrandChildren->sortBy('lft')->pluck('name')->all(),
        );
    }

    public function test_to_tree_with_subtree_uses_supplied_root(): void
    {
        $childA = Category::query()->findOrFail(2);

        // Build a collection containing only the descendants of Child A.
        $sub = Category::query()
            ->whereDescendantOf($childA->getBounds())
            ->orderBy('lft')
            ->get();

        $tree = $sub->toTree($childA);

        $this->assertSame(
            ['AA', 'AB'],
            $tree->sortBy('lft')->pluck('name')->all(),
        );
    }

    public function test_to_tree_on_empty_collection_returns_empty_collection(): void
    {
        $tree = (new NodeCollection)->toTree();
        $this->assertCount(0, $tree);
    }

    // ----------------------------------------------------------------
    // toFlatTree
    // ----------------------------------------------------------------

    public function test_to_flat_tree_preserves_depth_first_order(): void
    {
        $flat = $this->all()->toFlatTree();

        $this->assertSame(
            ['Root', 'Child A', 'AA', 'AB', 'Child B'],
            $flat->pluck('name')->all(),
        );
    }

    public function test_to_flat_tree_with_subtree(): void
    {
        $childA = Category::query()->findOrFail(2);
        $sub = Category::query()
            ->whereDescendantOf($childA->getBounds())
            ->orderBy('lft')
            ->get();

        $flat = $sub->toFlatTree($childA);

        $this->assertSame(['AA', 'AB'], $flat->pluck('name')->all());
    }

    public function test_to_flat_tree_on_empty_collection_returns_empty(): void
    {
        $flat = (new NodeCollection)->toFlatTree();
        $this->assertCount(0, $flat);
    }

    public function test_to_flat_tree_with_root_outside_collection_returns_its_children_only(): void
    {
        // The root needn't be in the collection — only its descendants do.
        $externalRoot = Category::query()->findOrFail(1);

        $sub = Category::query()
            ->whereDescendantOf($externalRoot->getBounds())
            ->orderBy('lft')
            ->get();

        $flat = $sub->toFlatTree($externalRoot);

        $this->assertSame(
            ['Child A', 'AA', 'AB', 'Child B'],
            $flat->pluck('name')->all(),
        );
    }

    public function test_to_flat_tree_with_unknown_root_key_returns_empty(): void
    {
        // Unknown root key yields an empty result — no fallback to inference, no throw.
        $orphan = new Category(['name' => 'orphan']);
        $orphan->id = 999;

        $flat = $this->all()->toFlatTree($orphan);

        $this->assertCount(0, $flat);
    }

    // ----------------------------------------------------------------
    // Query count — collection methods must not hit the database
    // ----------------------------------------------------------------

    public function test_to_tree_issues_no_queries_after_initial_fetch(): void
    {
        $rows = $this->all();

        $count = 0;
        DB::listen(static function () use (&$count): void {
            $count++;
        });

        $rows->toTree();
        $rows->toFlatTree();
        $rows->linkNodes();

        $this->assertSame(0, $count);
    }
}
