<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Methods that compose over the `ancestors` / `descendants` relations:
 *
 *  - `freshAggregate()` error path on a model with no aggregate columns.
 *  - Depth-bounded eager-loaded descendants (documented in
 *    docs/querying/relations.md as the standard composition pattern).
 *  - `withCount('descendants')` / `withCount('ancestors')` (documented in
 *    docs/querying/relations.md; the `withSum` / `withMax` / `withMin` /
 *    `withAvg` variants live in `RelationAggregateMethodsTest`).
 */
final class RelationMethodsTest extends TestCase
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

    public function test_fresh_aggregate_on_unknown_column_throws(): void
    {
        // Category has no aggregate columns. Asking for one must throw,
        // not silently return null.
        $root = Category::query()->findOrFail(1);

        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('no aggregate column');

        $root->freshAggregate('nonexistent_column');
    }

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

    public function test_where_has_descendants_with_predicate_filters_to_matching_ancestors(): void
    {
        // Doc shape: Category::whereHas('descendants', fn ($q) => $q->where(...)).
        // Here: "find every ancestor of AB by name". Only AB exists with
        // name='AB' (lft=5, rgt=6, depth=2), and the rows that have AB as
        // a descendant are Root and A.
        $names = Category::query()
            ->whereHas('descendants', fn ($q) => $q->where('name', 'AB'))
            ->orderBy('id')
            ->pluck('name')
            ->all();

        $this->assertSame(['Root', 'A'], $names);
    }

    public function test_where_has_descendants_excludes_rows_with_no_matching_descendants(): void
    {
        // Leaves (AA, AB, B) cannot satisfy whereHas('descendants', anything)
        // because they have no descendants at all — the relation predicate
        // doesn't even fire.
        $names = Category::query()
            ->whereHas('descendants')
            ->orderBy('id')
            ->pluck('name')
            ->all();

        // Only the internal nodes (Root, A) have any descendants.
        $this->assertSame(['Root', 'A'], $names);
    }

    public function test_where_has_ancestors_filters_to_rows_with_matching_ancestor(): void
    {
        // "Find every node whose ancestor chain includes 'A'" = AA + AB.
        $names = Category::query()
            ->whereHas('ancestors', fn ($q) => $q->where('name', 'A'))
            ->orderBy('id')
            ->pluck('name')
            ->all();

        $this->assertSame(['AA', 'AB'], $names);
    }
}
