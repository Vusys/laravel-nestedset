<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * `whereIsBefore` / `whereIsAfter` — strict-order predicates against a
 * `NodeBounds` value object. Other tests rely on these predicates only
 * indirectly via `prevSibling` / `nextSibling`; this file pins their
 * direct behaviour.
 */
final class BoundsPredicatesTest extends TestCase
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
}
