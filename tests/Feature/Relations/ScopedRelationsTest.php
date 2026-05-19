<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Relations;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Each scoped tree restarts its `lft` sequence at 1 — so two menus
 * with similar shapes have heavily-overlapping bounds. The
 * `ancestors` / `descendants` relations must apply the scope filter
 * or they leak cross-tree rows whenever bounds happen to span the
 * declaring model.
 *
 * Tree shape used throughout (both menus identical so every bound on
 * one side has a same-bounded twin on the other):
 *
 *   Menu 1 (menu_id=1)            Menu 2 (menu_id=2)
 *     1A   lft=1 rgt=6 root         2A   lft=1 rgt=6 root
 *       1B lft=2 rgt=5                2B lft=2 rgt=5
 *         1C lft=3 rgt=4                2C lft=3 rgt=4
 */
final class ScopedRelationsTest extends TestCase
{
    private Menu $m1;

    private Menu $m2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->m1 = Menu::create(['name' => 'Menu 1']);
        $this->m2 = Menu::create(['name' => 'Menu 2']);

        DB::table('menu_items')->insert([
            ['id' => 11, 'menu_id' => $this->m1->id, 'name' => '1A', 'lft' => 1, 'rgt' => 6, 'depth' => 0, 'parent_id' => null],
            ['id' => 12, 'menu_id' => $this->m1->id, 'name' => '1B', 'lft' => 2, 'rgt' => 5, 'depth' => 1, 'parent_id' => 11],
            ['id' => 13, 'menu_id' => $this->m1->id, 'name' => '1C', 'lft' => 3, 'rgt' => 4, 'depth' => 2, 'parent_id' => 12],
            ['id' => 21, 'menu_id' => $this->m2->id, 'name' => '2A', 'lft' => 1, 'rgt' => 6, 'depth' => 0, 'parent_id' => null],
            ['id' => 22, 'menu_id' => $this->m2->id, 'name' => '2B', 'lft' => 2, 'rgt' => 5, 'depth' => 1, 'parent_id' => 21],
            ['id' => 23, 'menu_id' => $this->m2->id, 'name' => '2C', 'lft' => 3, 'rgt' => 4, 'depth' => 2, 'parent_id' => 22],
        ]);

        $this->syncSequence('menu_items');
    }

    private function find(int $id): MenuItem
    {
        $row = MenuItem::query()->find($id);
        if ($row === null) {
            $this->fail("MenuItem {$id} not found");
        }

        return $row;
    }

    // ----------------------------------------------------------------
    // ancestors() — single load
    // ----------------------------------------------------------------

    public function test_ancestors_does_not_return_rows_from_a_different_scope(): void
    {
        // 1C's ancestors: 1A, 1B (menu 1 only).
        // Without scope filter, would also pull 2A, 2B because both
        // menus restart bounds at lft=1.
        $names = $this->find(13)->ancestors()->orderBy('lft')->pluck('name')->all();

        $this->assertSame(['1A', '1B'], $names);
    }

    public function test_descendants_does_not_return_rows_from_a_different_scope(): void
    {
        $names = $this->find(11)->descendants()->orderBy('lft')->pluck('name')->all();

        $this->assertSame(['1B', '1C'], $names);
    }

    // ----------------------------------------------------------------
    // ancestors() / descendants() — eager load
    // ----------------------------------------------------------------

    public function test_eager_load_ancestors_keeps_each_models_ancestors_in_its_own_scope(): void
    {
        $rows = MenuItem::query()->with('ancestors')->orderBy('id')->get()->keyBy('id');

        $cMenu1 = $rows->get(13);
        $cMenu2 = $rows->get(23);

        $this->assertInstanceOf(MenuItem::class, $cMenu1);
        $this->assertInstanceOf(MenuItem::class, $cMenu2);

        $this->assertSame(['1A', '1B'], $cMenu1->ancestors->sortBy('lft')->pluck('name')->all());
        $this->assertSame(['2A', '2B'], $cMenu2->ancestors->sortBy('lft')->pluck('name')->all());
    }

    public function test_eager_load_descendants_keeps_each_models_descendants_in_its_own_scope(): void
    {
        $rows = MenuItem::query()->with('descendants')->orderBy('id')->get()->keyBy('id');

        $rootMenu1 = $rows->get(11);
        $rootMenu2 = $rows->get(21);

        $this->assertInstanceOf(MenuItem::class, $rootMenu1);
        $this->assertInstanceOf(MenuItem::class, $rootMenu2);

        $this->assertSame(['1B', '1C'], $rootMenu1->descendants->sortBy('lft')->pluck('name')->all());
        $this->assertSame(['2B', '2C'], $rootMenu2->descendants->sortBy('lft')->pluck('name')->all());
    }

    // ----------------------------------------------------------------
    // whereHas / withCount — subquery uses scope-joined existence check
    // ----------------------------------------------------------------

    public function test_where_has_descendants_does_not_count_cross_scope_overlaps(): void
    {
        // Every leaf has rgt = lft + 1; only non-leaves should match.
        $names = MenuItem::query()
            ->whereHas('descendants')
            ->orderBy('menu_id')
            ->orderBy('lft')
            ->pluck('name')
            ->all();

        // Without the scope join in relationExistenceCondition, a leaf
        // would appear to have descendants in the OTHER menu's tree.
        $this->assertSame(['1A', '1B', '2A', '2B'], $names);
    }

    public function test_with_count_descendants_counts_only_in_scope_rows(): void
    {
        // 1A has 2 descendants in its own scope (1B, 1C); without the
        // scope join it would have 5 (would also count 2A, 2B, 2C).
        $rows = MenuItem::query()->withCount('descendants')->get()->keyBy('id');

        /** @var MenuItem $oneA */
        $oneA = $rows->get(11);
        /** @var MenuItem $oneB */
        $oneB = $rows->get(12);

        $this->assertSame(2, (int) $oneA->descendants_count);
        $this->assertSame(1, (int) $oneB->descendants_count);
    }

    public function test_with_count_ancestors_counts_only_in_scope_rows(): void
    {
        $rows = MenuItem::query()->withCount('ancestors')->get()->keyBy('id');

        /** @var MenuItem $oneC */
        $oneC = $rows->get(13);
        /** @var MenuItem $twoC */
        $twoC = $rows->get(23);

        $this->assertSame(2, (int) $oneC->ancestors_count);
        $this->assertSame(2, (int) $twoC->ancestors_count);
    }
}
