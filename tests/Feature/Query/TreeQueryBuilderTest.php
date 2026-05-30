<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Query\TreeQueryBuilder;
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
final class TreeQueryBuilderTest extends TestCase
{
    private NodeBounds $root;

    private NodeBounds $childA;

    private NodeBounds $childB;

    private NodeBounds $aa;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root',    'lft' => 1,  'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'Child A', 'lft' => 2,  'rgt' => 7,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA',      'lft' => 3,  'rgt' => 4,  'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'AB',      'lft' => 5,  'rgt' => 6,  'depth' => 2, 'parent_id' => 2],
            ['id' => 5, 'name' => 'Child B', 'lft' => 8,  'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
        ]);

        $this->root = new NodeBounds(lft: 1, rgt: 10, depth: 0);
        $this->childA = new NodeBounds(lft: 2, rgt: 7, depth: 1);
        $this->childB = new NodeBounds(lft: 8, rgt: 9, depth: 1);
        $this->aa = new NodeBounds(lft: 3, rgt: 4, depth: 2);
    }

    /** @return TreeQueryBuilder<Category> */
    private function q(): TreeQueryBuilder
    {
        /** @var TreeQueryBuilder<Category> $builder */
        $builder = new TreeQueryBuilder(Category::query()->getQuery());
        $builder->setModel(new Category);

        return $builder;
    }

    // ----------------------------------------------------------------
    // whereDescendantOf
    // ----------------------------------------------------------------

    public function test_where_descendant_of_returns_strict_descendants(): void
    {
        $names = $this->q()->whereDescendantOf($this->root)->pluck('name')->sort()->values()->all();

        $this->assertSame(['AA', 'AB', 'Child A', 'Child B'], $names);
    }

    public function test_where_descendant_of_excludes_self(): void
    {
        $names = $this->q()->whereDescendantOf($this->root)->pluck('name')->all();

        $this->assertNotContains('Root', $names);
    }

    public function test_where_descendant_of_subtree(): void
    {
        $names = $this->q()->whereDescendantOf($this->childA)->pluck('name')->sort()->values()->all();

        $this->assertSame(['AA', 'AB'], $names);
    }

    public function test_where_descendant_of_leaf_returns_empty(): void
    {
        $result = $this->q()->whereDescendantOf($this->aa)->get();

        $this->assertCount(0, $result);
    }

    // ----------------------------------------------------------------
    // whereDescendantOrSelf
    // ----------------------------------------------------------------

    public function test_where_descendant_or_self_includes_self(): void
    {
        $names = $this->q()->whereDescendantOrSelf($this->childA)->pluck('name')->sort()->values()->all();

        $this->assertSame(['AA', 'AB', 'Child A'], $names);
    }

    // ----------------------------------------------------------------
    // whereAncestorOf
    // ----------------------------------------------------------------

    public function test_where_ancestor_of_returns_strict_ancestors(): void
    {
        $names = $this->q()->whereAncestorOf($this->aa)->pluck('name')->sort()->values()->all();

        $this->assertSame(['Child A', 'Root'], $names);
    }

    public function test_where_ancestor_of_excludes_self(): void
    {
        $names = $this->q()->whereAncestorOf($this->aa)->pluck('name')->all();

        $this->assertNotContains('AA', $names);
    }

    // ----------------------------------------------------------------
    // whereAncestorOrSelf
    // ----------------------------------------------------------------

    public function test_where_ancestor_or_self_includes_self(): void
    {
        $names = $this->q()->whereAncestorOrSelf($this->aa)->pluck('name')->sort()->values()->all();

        $this->assertSame(['AA', 'Child A', 'Root'], $names);
    }

    // ----------------------------------------------------------------
    // whereIsRoot / whereIsLeaf
    // ----------------------------------------------------------------

    public function test_where_is_root_returns_only_root(): void
    {
        $names = $this->q()->whereIsRoot()->pluck('name')->all();

        $this->assertSame(['Root'], $names);
    }

    public function test_where_is_leaf_returns_leaves_only(): void
    {
        $names = $this->q()->whereIsLeaf()->pluck('name')->sort()->values()->all();

        $this->assertSame(['AA', 'AB', 'Child B'], $names);
    }

    // ----------------------------------------------------------------
    // whereIsAfter / whereIsBefore
    // ----------------------------------------------------------------

    public function test_where_is_after_child_a_returns_child_b(): void
    {
        $names = $this->q()->whereIsAfter($this->childA)->pluck('name')->all();

        $this->assertSame(['Child B'], $names);
    }

    public function test_where_is_before_child_a_returns_root_only(): void
    {
        // Only Root comes entirely before Child A's lft
        $names = $this->q()->whereIsBefore($this->childA)->pluck('name')->all();

        $this->assertCount(0, $names); // nothing has rgt < 2
    }

    public function test_where_is_before_child_b(): void
    {
        // Nodes whose rgt < 8: Root has rgt=10 (no), Child A rgt=7 (yes), AA rgt=4, AB rgt=6
        $names = $this->q()->whereIsBefore($this->childB)->pluck('name')->sort()->values()->all();

        $this->assertSame(['AA', 'AB', 'Child A'], $names);
    }

    // ----------------------------------------------------------------
    // withDepth
    // ----------------------------------------------------------------

    public function test_with_depth_default_alias_selects_depth_column(): void
    {
        $row = $this->q()->withDepth()->whereIsRoot()->first();

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->depth);
    }

    public function test_with_depth_custom_alias(): void
    {
        $row = $this->q()->withDepth('level')->whereIsRoot()->first();

        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row->level);
    }

    // ----------------------------------------------------------------
    // defaultOrder / reversed
    // ----------------------------------------------------------------

    public function test_default_order_walks_tree_in_pre_order_traversal(): void
    {
        $names = $this->q()->defaultOrder()->pluck('name')->all();

        $this->assertSame(['Root', 'Child A', 'AA', 'AB', 'Child B'], $names);
    }

    public function test_reversed_walks_tree_bottom_up_in_reverse_pre_order(): void
    {
        $names = $this->q()->reversed()->pluck('name')->all();

        $this->assertSame(['Child B', 'AB', 'AA', 'Child A', 'Root'], $names);
    }

    // ----------------------------------------------------------------
    // withoutRoot
    // ----------------------------------------------------------------

    public function test_without_root_excludes_root_nodes(): void
    {
        $names = $this->q()->withoutRoot()->pluck('name')->all();

        $this->assertNotContains('Root', $names);
        $this->assertCount(4, $names);
    }

    // ----------------------------------------------------------------
    // leaves / root
    // ----------------------------------------------------------------

    public function test_leaves_is_an_alias_for_where_is_leaf(): void
    {
        $via_leaves = $this->q()->leaves()->pluck('name')->sort()->values()->all();
        $via_where = $this->q()->whereIsLeaf()->pluck('name')->sort()->values()->all();

        $this->assertSame($via_where, $via_leaves);
    }

    public function test_root_returns_root_model(): void
    {
        $root = $this->q()->root();

        $this->assertNotNull($root);
        $this->assertSame('Root', $root->name);
    }

    // ----------------------------------------------------------------
    // ancestorsOf / descendantsOf (aliases)
    // ----------------------------------------------------------------

    public function test_ancestors_of_is_alias_for_where_ancestor_of(): void
    {
        $viaAlias = $this->q()->ancestorsOf($this->aa)->pluck('name')->sort()->values()->all();
        $viaDirect = $this->q()->whereAncestorOf($this->aa)->pluck('name')->sort()->values()->all();

        $this->assertSame($viaDirect, $viaAlias);
    }

    public function test_descendants_of_is_alias_for_where_descendant_of(): void
    {
        $viaAlias = $this->q()->descendantsOf($this->root)->pluck('name')->sort()->values()->all();
        $viaDirect = $this->q()->whereDescendantOf($this->root)->pluck('name')->sort()->values()->all();

        $this->assertSame($viaDirect, $viaAlias);
    }

    // ----------------------------------------------------------------
    // Query count assertions — no silent N+1s
    // ----------------------------------------------------------------

    public function test_where_descendant_of_issues_one_query(): void
    {
        $count = 0;
        DB::listen(static function () use (&$count): void {
            $count++;
        });

        $this->q()->whereDescendantOf($this->root)->get();

        $this->assertSame(1, $count);
    }

    public function test_where_ancestor_of_issues_one_query(): void
    {
        $count = 0;
        DB::listen(static function () use (&$count): void {
            $count++;
        });

        $this->q()->whereAncestorOf($this->aa)->get();

        $this->assertSame(1, $count);
    }

    public function test_root_method_issues_one_query(): void
    {
        $count = 0;
        DB::listen(static function () use (&$count): void {
            $count++;
        });

        $this->q()->root();

        $this->assertSame(1, $count);
    }
}
