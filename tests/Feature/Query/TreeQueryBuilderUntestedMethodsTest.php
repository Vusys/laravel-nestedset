<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Covers `TreeQueryBuilder` methods that the existing test suites
 * exercise only indirectly:
 *
 *  - `whereIsBefore` / `whereIsAfter` (no test cited)
 *  - `freshAggregate('unknown_column')` error path
 *  - depth-bounded descendants composition (documented in
 *    docs/querying/relations.md, no test cited)
 *  - `Model::query()->withCount('descendants')` and
 *    `withCount('ancestors')` (documented, no test cited)
 *
 * The `withSum` / `withMax` / `withMin` / `withAvg` variants over the
 * same relations are covered in `RelationAggregateMethodsTest`.
 */
final class TreeQueryBuilderUntestedMethodsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tree:
        //   Root      lft=1  rgt=10  depth=0
        //   ├── A     lft=2  rgt=7   depth=1
        //   │   ├── AA  lft=3  rgt=4 depth=2
        //   │   └── AB  lft=5  rgt=6 depth=2
        //   └── B     lft=8  rgt=9   depth=1
        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A', 'lft' => 2, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'AA', 'lft' => 3, 'rgt' => 4, 'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'AB', 'lft' => 5, 'rgt' => 6, 'depth' => 2, 'parent_id' => 2],
            ['id' => 5, 'name' => 'B', 'lft' => 8, 'rgt' => 9, 'depth' => 1, 'parent_id' => 1],
        ]);
        $this->syncSequence('categories');
    }

    // ----------------------------------------------------------------
    // whereIsBefore / whereIsAfter
    // ----------------------------------------------------------------

    public function test_where_is_before_returns_nodes_whose_rgt_is_less_than_bounds_lft(): void
    {
        // Bounds = Child B (lft=8, rgt=9). "Before B" = nodes whose rgt < 8.
        $boundsB = new NodeBounds(lft: 8, rgt: 9, depth: 1);

        $names = Category::query()->whereIsBefore($boundsB)->orderBy('lft')->pluck('name')->all();

        // A (rgt=7), AA (rgt=4), AB (rgt=6) all have rgt < 8.
        // Root (rgt=10) doesn't qualify. B doesn't qualify (equality
        // excluded by `<`).
        $this->assertSame(['A', 'AA', 'AB'], $names);
    }

    public function test_where_is_after_returns_nodes_whose_lft_is_greater_than_bounds_rgt(): void
    {
        // Bounds = A (lft=2, rgt=7). "After A" = nodes whose lft > 7.
        $boundsA = new NodeBounds(lft: 2, rgt: 7, depth: 1);

        $names = Category::query()->whereIsAfter($boundsA)->orderBy('lft')->pluck('name')->all();

        // Only B (lft=8) qualifies.
        $this->assertSame(['B'], $names);
    }

    // ----------------------------------------------------------------
    // freshAggregate on an undeclared column
    // ----------------------------------------------------------------

    public function test_fresh_aggregate_on_unknown_column_throws(): void
    {
        // Category has no aggregate columns. Asking for one must throw,
        // not silently return null.
        $root = Category::query()->findOrFail(1);

        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('no aggregate column');

        $root->freshAggregate('nonexistent_column');
    }

    // ----------------------------------------------------------------
    // Depth-bounded eager-loaded descendants (docs claim)
    // ----------------------------------------------------------------

    public function test_descendants_relation_can_be_bounded_by_depth(): void
    {
        // The docs say:
        //   $root->load([
        //       'descendants' => fn ($q) => $q->where('depth', '<=', $root->depth + 2),
        //   ]);
        // Verify the composition: only depth-1 and depth-2 nodes load.
        $root = Category::query()->findOrFail(1);

        $root->load([
            'descendants' => fn ($q) => $q->where('depth', '<=', $root->depth + 1),
        ]);

        // depth <= 1 below the root = A and B (both depth=1).
        // AA and AB (depth=2) excluded by the bound.
        $names = $root->descendants->sortBy('lft')->pluck('name')->all();
        $this->assertSame(['A', 'B'], array_values($names));
    }

    // ----------------------------------------------------------------
    // withCount('descendants') / withCount('ancestors')
    // ----------------------------------------------------------------

    public function test_with_count_descendants_counts_strict_descendants(): void
    {
        $rows = Category::query()->withCount('descendants')->orderBy('id')->get()->keyBy('id');

        /** @var Category $root */
        $root = $rows->get(1);
        /** @var Category $a */
        $a = $rows->get(2);
        /** @var Category $aa */
        $aa = $rows->get(3);

        $this->assertSame(4, (int) $root->descendants_count);
        $this->assertSame(2, (int) $a->descendants_count);
        $this->assertSame(0, (int) $aa->descendants_count);
    }

    public function test_with_count_ancestors_counts_strict_ancestors(): void
    {
        $rows = Category::query()->withCount('ancestors')->orderBy('id')->get()->keyBy('id');

        /** @var Category $root */
        $root = $rows->get(1);
        /** @var Category $aa */
        $aa = $rows->get(3);

        $this->assertSame(0, (int) $root->ancestors_count);
        $this->assertSame(2, (int) $aa->ancestors_count);
    }
}
