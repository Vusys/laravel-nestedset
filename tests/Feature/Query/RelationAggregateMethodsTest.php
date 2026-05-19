<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Pins Eloquent's relation-aggregate methods (`withSum`, `withMax`,
 * `withMin`, `withAvg`) against the custom `descendants` /
 * `ancestors` relations. These methods share
 * `getRelationExistenceQuery` with `withCount` — the contract is
 * "the relation's existence query is the join condition; the
 * function is wrapped around the chosen column".
 *
 * Audit reference: build/CORRECTNESS_AUDIT.md → T5.
 */
final class RelationAggregateMethodsTest extends TestCase
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

    public function test_with_sum_descendants_sums_a_descendant_column(): void
    {
        $rows = Category::query()->withSum('descendants', 'lft')->orderBy('id')->get()->keyBy('id');

        /** @var Category $root */
        $root = $rows->get(1);

        // Descendants of Root: A(lft=2), AA(lft=3), AB(lft=5), B(lft=8) → sum 18.
        $sum = $root->getAttribute('descendants_sum_lft');
        $this->assertTrue(is_numeric($sum));
        $this->assertSame(18, (int) $sum);
    }

    public function test_with_max_descendants_returns_max_descendant_column(): void
    {
        $rows = Category::query()->withMax('descendants', 'lft')->orderBy('id')->get()->keyBy('id');

        /** @var Category $root */
        $root = $rows->get(1);

        // Max descendant.lft under Root = 8 (B).
        $max = $root->getAttribute('descendants_max_lft');
        $this->assertTrue(is_numeric($max));
        $this->assertSame(8, (int) $max);
    }

    public function test_with_min_descendants_returns_min_descendant_column(): void
    {
        $rows = Category::query()->withMin('descendants', 'lft')->orderBy('id')->get()->keyBy('id');

        /** @var Category $root */
        $root = $rows->get(1);

        // Min descendant.lft under Root = 2 (A).
        $min = $root->getAttribute('descendants_min_lft');
        $this->assertTrue(is_numeric($min));
        $this->assertSame(2, (int) $min);
    }

    public function test_with_avg_ancestors_averages_ancestor_column(): void
    {
        $rows = Category::query()->withAvg('ancestors', 'depth')->orderBy('id')->get()->keyBy('id');

        /** @var Category $aa */
        $aa = $rows->get(3);

        // AA's ancestors: Root(depth=0), A(depth=1). Average = 0.5.
        $avg = $aa->getAttribute('ancestors_avg_depth');
        $this->assertTrue(is_numeric($avg));
        $this->assertEqualsWithDelta(0.5, (float) $avg, 1e-9);
    }
}
