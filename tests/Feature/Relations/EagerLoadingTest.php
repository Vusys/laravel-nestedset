<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Relations;

use Illuminate\Support\Facades\DB;
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
final class EagerLoadingTest extends TestCase
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

    // ----------------------------------------------------------------
    // ancestors — single load
    // ----------------------------------------------------------------

    public function test_ancestors_returns_strict_ancestors_in_order(): void
    {
        $names = $this->find(3)->ancestors()->orderBy('lft')->pluck('name')->all();

        $this->assertSame(['Root', 'Child A'], $names);
    }

    public function test_ancestors_of_root_is_empty(): void
    {
        $this->assertCount(0, $this->find(1)->ancestors()->get());
    }

    // ----------------------------------------------------------------
    // descendants — single load
    // ----------------------------------------------------------------

    public function test_descendants_returns_strict_descendants(): void
    {
        $names = $this->find(2)->descendants()->orderBy('lft')->pluck('name')->all();

        $this->assertSame(['AA', 'AB'], $names);
    }

    public function test_descendants_of_leaf_is_empty(): void
    {
        $this->assertCount(0, $this->find(3)->descendants()->get());
    }

    // ----------------------------------------------------------------
    // Eager loading
    // ----------------------------------------------------------------

    public function test_with_ancestors_uses_exactly_two_queries(): void
    {
        $count = 0;
        DB::listen(static function () use (&$count): void {
            $count++;
        });

        Category::query()->with('ancestors')->get();

        $this->assertSame(2, $count);
    }

    public function test_with_descendants_uses_exactly_two_queries(): void
    {
        $count = 0;
        DB::listen(static function () use (&$count): void {
            $count++;
        });

        Category::query()->with('descendants')->get();

        $this->assertSame(2, $count);
    }

    public function test_with_ancestors_attaches_correct_results_per_model(): void
    {
        $rows = Category::query()->with('ancestors')->orderBy('id')->get();
        $byId = $rows->keyBy('id');

        $aa = $byId->get(3);
        $childA = $byId->get(2);
        $root = $byId->get(1);

        $this->assertInstanceOf(Category::class, $aa);
        $this->assertInstanceOf(Category::class, $childA);
        $this->assertInstanceOf(Category::class, $root);

        $this->assertSame(
            ['Root', 'Child A'],
            $aa->ancestors->sortBy('lft')->pluck('name')->all(),
        );

        $this->assertSame(
            ['Root'],
            $childA->ancestors->pluck('name')->all(),
        );

        $this->assertCount(0, $root->ancestors);
    }

    public function test_with_descendants_attaches_correct_results_per_model(): void
    {
        $rows = Category::query()->with('descendants')->orderBy('id')->get();
        $byId = $rows->keyBy('id');

        $root = $byId->get(1);
        $childA = $byId->get(2);
        $aa = $byId->get(3);

        $this->assertInstanceOf(Category::class, $root);
        $this->assertInstanceOf(Category::class, $childA);
        $this->assertInstanceOf(Category::class, $aa);

        $this->assertSame(
            ['Child A', 'AA', 'AB', 'Child B'],
            $root->descendants->sortBy('lft')->pluck('name')->all(),
        );

        $this->assertSame(
            ['AA', 'AB'],
            $childA->descendants->sortBy('lft')->pluck('name')->all(),
        );

        $this->assertCount(0, $aa->descendants);
    }

    public function test_eager_loaded_ancestors_does_not_include_self(): void
    {
        $rows = Category::query()->with('ancestors')->get();

        foreach ($rows as $row) {
            foreach ($row->ancestors as $ancestor) {
                $this->assertNotSame(
                    $row->id,
                    $ancestor->id,
                    "{$row->name} should not be its own ancestor",
                );
            }
        }
    }

    // ----------------------------------------------------------------
    // whereHas — subquery support
    // ----------------------------------------------------------------

    public function test_where_has_descendants_returns_only_internal_nodes(): void
    {
        $names = Category::query()
            ->whereHas('descendants')
            ->orderBy('lft')
            ->pluck('name')
            ->all();

        $this->assertSame(['Root', 'Child A'], $names);
    }

    public function test_where_has_ancestors_excludes_root(): void
    {
        $names = Category::query()
            ->whereHas('ancestors')
            ->orderBy('lft')
            ->pluck('name')
            ->all();

        $this->assertSame(['Child A', 'AA', 'AB', 'Child B'], $names);
    }
}
