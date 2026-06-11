<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Relations;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function ancestors_does_not_return_rows_from_a_different_scope(): void
    {
        // 1C's ancestors: 1A, 1B (menu 1 only).
        // Without scope filter, would also pull 2A, 2B because both
        // menus restart bounds at lft=1.
        $names = $this->find(13)->ancestors()->orderBy('lft')->pluck('name')->all();

        $this->assertSame(['1A', '1B'], $names);
    }

    #[Test]
    public function descendants_does_not_return_rows_from_a_different_scope(): void
    {
        $names = $this->find(11)->descendants()->orderBy('lft')->pluck('name')->all();

        $this->assertSame(['1B', '1C'], $names);
    }

    // ----------------------------------------------------------------
    // ancestors() / descendants() — eager load
    // ----------------------------------------------------------------

    #[Test]
    public function eager_load_ancestors_keeps_each_models_ancestors_in_its_own_scope(): void
    {
        $rows = MenuItem::query()->with('ancestors')->orderBy('id')->get()->keyBy('id');

        $cMenu1 = $rows->get(13);
        $cMenu2 = $rows->get(23);

        $this->assertInstanceOf(MenuItem::class, $cMenu1);
        $this->assertInstanceOf(MenuItem::class, $cMenu2);

        $this->assertSame(['1A', '1B'], $cMenu1->ancestors->sortBy('lft')->pluck('name')->all());
        $this->assertSame(['2A', '2B'], $cMenu2->ancestors->sortBy('lft')->pluck('name')->all());
    }

    #[Test]
    public function eager_load_descendants_keeps_each_models_descendants_in_its_own_scope(): void
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

    #[Test]
    public function where_has_descendants_does_not_count_cross_scope_overlaps(): void
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

    #[Test]
    public function with_count_descendants_counts_only_in_scope_rows(): void
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

    #[Test]
    public function with_count_ancestors_counts_only_in_scope_rows(): void
    {
        $rows = MenuItem::query()->withCount('ancestors')->get()->keyBy('id');

        /** @var MenuItem $oneC */
        $oneC = $rows->get(13);
        /** @var MenuItem $twoC */
        $twoC = $rows->get(23);

        $this->assertSame(2, (int) $oneC->ancestors_count);
        $this->assertSame(2, (int) $twoC->ancestors_count);
    }

    // ----------------------------------------------------------------
    // children() — must work under eager load / withCount / whereHas on
    // scoped models. The relation keys on parent_id (a globally-unique
    // PK reference), so no scope predicate is needed and none may be
    // baked in from $this's attributes (that broke the prototype-built
    // eager-load paths).
    // ----------------------------------------------------------------

    #[Test]
    public function children_lazy_load_returns_only_direct_children(): void
    {
        $this->assertSame(['1B'], $this->find(11)->children()->orderBy('lft')->pluck('name')->all());
    }

    #[Test]
    public function children_eager_load_populates_on_scoped_model(): void
    {
        $rows = MenuItem::query()->with('children')->orderBy('id')->get()->keyBy('id');

        $oneA = $rows->get(11);
        $twoA = $rows->get(21);

        $this->assertInstanceOf(MenuItem::class, $oneA);
        $this->assertInstanceOf(MenuItem::class, $twoA);

        $this->assertSame(['1B'], $oneA->children->sortBy('lft')->pluck('name')->all());
        $this->assertSame(['2B'], $twoA->children->sortBy('lft')->pluck('name')->all());
    }

    #[Test]
    public function with_count_children_counts_on_scoped_model(): void
    {
        $rows = MenuItem::query()->withCount('children')->get()->keyBy('id');

        /** @var MenuItem $rootA */
        $rootA = $rows->get(11);
        /** @var MenuItem $childB */
        $childB = $rows->get(12);
        /** @var MenuItem $leafC */
        $leafC = $rows->get(13);

        $this->assertSame(1, (int) $rootA->children_count);
        $this->assertSame(1, (int) $childB->children_count);
        $this->assertSame(0, (int) $leafC->children_count);
    }

    #[Test]
    public function where_has_children_matches_on_scoped_model(): void
    {
        $names = MenuItem::query()
            ->whereHas('children')
            ->orderBy('menu_id')
            ->orderBy('lft')
            ->pluck('name')
            ->all();

        $this->assertSame(['1A', '1B', '2A', '2B'], $names);
    }

    #[Test]
    public function depth_bounded_descendants_eager_load_respects_scope(): void
    {
        // Production use case from docs/querying/relations.md:
        //
        //   $root->load([
        //       'descendants' => fn ($q) => $q->where('depth', '<=', $root->depth + 1),
        //   ]);
        //
        // Pin that the depth predicate composes with the relation's
        // own bounds + scope filter, instead of either:
        //   (a) bleeding cross-scope rows whose depth happens to match, or
        //   (b) bypassing the depth bound and returning everything.
        //
        // Each menu has 1A/2A at depth 0, 1B/2B at depth 1, 1C/2C at depth 2.
        // Loading menu 1's root descendants with depth <= 1 should yield
        // only 1B (depth=1), never 2B (cross-scope, depth=1).
        $menu1Root = $this->find(11);

        $menu1Root->load([
            'descendants' => fn ($q) => $q->where('depth', '<=', $menu1Root->depth + 1),
        ]);

        $names = $menu1Root->descendants->sortBy('lft')->pluck('name')->all();
        $this->assertSame(['1B'], array_values($names),
            'depth <= 1 + scope: only 1B (menu 1, depth 1) — 1C dropped by depth, 2B dropped by scope',
        );
    }
}
