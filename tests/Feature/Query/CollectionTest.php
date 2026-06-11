<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function get_returns_node_collection(): void
    {
        $this->assertInstanceOf(NodeCollection::class, $this->all());
    }

    // ----------------------------------------------------------------
    // linkNodes
    // ----------------------------------------------------------------

    #[Test]
    public function link_nodes_sets_parent_relation(): void
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

    #[Test]
    public function link_nodes_sets_children_relation(): void
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

    #[Test]
    public function link_nodes_on_empty_collection_returns_self(): void
    {
        $empty = new NodeCollection;
        $this->assertSame($empty, $empty->linkNodes());
    }

    // ----------------------------------------------------------------
    // toTree
    // ----------------------------------------------------------------

    #[Test]
    public function to_tree_returns_single_root_for_complete_tree(): void
    {
        $tree = $this->all()->toTree();

        $this->assertCount(1, $tree);
        $top = $tree->first();
        $this->assertInstanceOf(Category::class, $top);
        $this->assertSame('Root', $top->name);
    }

    #[Test]
    public function to_tree_links_children_recursively(): void
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

    #[Test]
    public function to_tree_with_subtree_uses_supplied_root(): void
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

    #[Test]
    public function to_tree_on_empty_collection_returns_empty_collection(): void
    {
        $tree = (new NodeCollection)->toTree();
        $this->assertCount(0, $tree);
    }

    #[Test]
    public function to_tree_promotes_a_parent_absent_node_to_the_top_level(): void
    {
        // Descendants-only collection: Child A, AA, AB (the real root,
        // Root, isn't fetched). Child A's parent (Root) is absent from
        // the collection, so Child A surfaces as the single top-level
        // node with AA/AB nested under it. No explicit root, no
        // lowest-lft inference — just the forest rule.
        $sub = Category::query()
            ->whereIn('id', [2, 3, 4])
            ->orderBy('lft')
            ->get();

        $tree = $sub->toTree();

        $this->assertCount(1, $tree, 'Child A is the only node whose parent is absent');
        $top = $tree->first();
        $this->assertInstanceOf(Category::class, $top);
        $this->assertSame('Child A', $top->name);

        $children = $top->getRelation('children');
        $this->assertInstanceOf(Collection::class, $children);
        $this->assertSame(
            ['AA', 'AB'],
            $children->sortBy('lft')->pluck('name')->all(),
        );
    }

    #[Test]
    public function to_tree_returns_a_forest_and_drops_no_disconnected_nodes(): void
    {
        // Partial fetch whose members belong to two different absent
        // parents: AA/AB under Child A (absent), Child B under Root
        // (absent). The old "single inferred root" rule kept only
        // AA/AB and silently dropped Child B. The forest rule promotes
        // every parent-absent node, so all three maximal subtrees show.
        $sub = Category::query()
            ->whereIn('id', [3, 4, 5])
            ->orderBy('lft')
            ->get();

        $tree = $sub->toTree();

        $this->assertSame(
            ['AA', 'AB', 'Child B'],
            $tree->sortBy('lft')->pluck('name')->all(),
            'no node may vanish — disconnected subtrees become their own roots',
        );
    }

    #[Test]
    public function link_nodes_leaves_an_absent_parent_unloaded_for_lazy_access(): void
    {
        // AA's parent (Child A) isn't in this collection. linkNodes()
        // must NOT mark the `parent` relation loaded-null, or lazy
        // `$aa->parent` would wrongly return null instead of querying.
        $sub = Category::query()->whereIn('id', [3, 4])->orderBy('lft')->get();
        $sub->linkNodes();

        /** @var Category $aa */
        $aa = $sub->firstOrFail();
        $this->assertFalse($aa->relationLoaded('parent'), 'absent parent must stay unloaded');
        $this->assertSame('Child A', $aa->parent?->name, 'lazy parent still resolves from the DB');
    }

    // ----------------------------------------------------------------
    // toFlatTree
    // ----------------------------------------------------------------

    #[Test]
    public function to_flat_tree_preserves_depth_first_order(): void
    {
        $flat = $this->all()->toFlatTree();

        $this->assertSame(
            ['Root', 'Child A', 'AA', 'AB', 'Child B'],
            $flat->pluck('name')->all(),
        );
    }

    #[Test]
    public function to_flat_tree_with_subtree(): void
    {
        $childA = Category::query()->findOrFail(2);
        $sub = Category::query()
            ->whereDescendantOf($childA->getBounds())
            ->orderBy('lft')
            ->get();

        $flat = $sub->toFlatTree($childA);

        $this->assertSame(['AA', 'AB'], $flat->pluck('name')->all());
    }

    #[Test]
    public function to_flat_tree_on_empty_collection_returns_empty(): void
    {
        $flat = (new NodeCollection)->toFlatTree();
        $this->assertCount(0, $flat);
    }

    #[Test]
    public function to_flat_tree_includes_disconnected_nodes_in_dfs_order(): void
    {
        // Same forest as the toTree disconnected case: AA, AB (under
        // absent Child A) and Child B (under absent Root). Each
        // parent-absent node is emitted as a DFS root; none is dropped.
        $sub = Category::query()
            ->whereIn('id', [3, 4, 5])
            ->orderBy('lft')
            ->get();

        $flat = $sub->toFlatTree();

        $this->assertSame(['AA', 'AB', 'Child B'], $flat->pluck('name')->all());
    }

    #[Test]
    public function to_flat_tree_with_root_outside_collection_returns_its_children_only(): void
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

    #[Test]
    public function to_flat_tree_with_unknown_root_key_returns_empty(): void
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

    #[Test]
    public function to_tree_issues_no_queries_after_initial_fetch(): void
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
