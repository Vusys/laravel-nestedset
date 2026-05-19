<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Columns;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Query\TreeMutationBuilder;
use Vusys\NestedSet\Query\TreeRepairBuilder;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Two menus share one menu_items table. Each menu has its own tree shape;
 * mutating one must never shift rows from the other.
 *
 * Menu 1:                    Menu 2:
 *   Root1  lft=1 rgt=6         Root2  lft=1 rgt=4
 *     A    lft=2 rgt=3           X    lft=2 rgt=3
 *     B    lft=4 rgt=5
 */
final class ScopingTest extends TestCase
{
    private Menu $menu1;

    private Menu $menu2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->menu1 = Menu::create(['name' => 'Menu 1']);
        $this->menu2 = Menu::create(['name' => 'Menu 2']);

        DB::table('menu_items')->insert([
            ['id' => 1, 'menu_id' => $this->menu1->id, 'name' => 'Root1', 'lft' => 1, 'rgt' => 6, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'menu_id' => $this->menu1->id, 'name' => 'A',     'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'menu_id' => $this->menu1->id, 'name' => 'B',     'lft' => 4, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 4, 'menu_id' => $this->menu2->id, 'name' => 'Root2', 'lft' => 1, 'rgt' => 4, 'depth' => 0, 'parent_id' => null],
            ['id' => 5, 'menu_id' => $this->menu2->id, 'name' => 'X',     'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 4],
        ]);
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function mutator(array $scope): TreeMutationBuilder
    {
        return new TreeMutationBuilder(
            connection: DB::connection(),
            table: 'menu_items',
            lft: Columns::LFT,
            rgt: Columns::RGT,
            parentId: Columns::PARENT_ID,
            depth: Columns::DEPTH,
            scope: $scope,
        );
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function repair(array $scope): TreeRepairBuilder
    {
        return new TreeRepairBuilder(
            connection: DB::connection(),
            table: 'menu_items',
            lft: Columns::LFT,
            rgt: Columns::RGT,
            parentId: Columns::PARENT_ID,
            depth: Columns::DEPTH,
            scope: $scope,
        );
    }

    // ----------------------------------------------------------------
    // Resolver
    // ----------------------------------------------------------------

    public function test_resolver_reads_columns_from_attribute(): void
    {
        $this->assertSame(['menu_id'], NestedSetScopeResolver::columns(MenuItem::class));
    }

    public function test_resolver_returns_empty_for_unscoped_model(): void
    {
        $this->assertSame(
            [],
            NestedSetScopeResolver::columns(Category::class),
        );
    }

    public function test_resolver_reads_values_from_node(): void
    {
        $item = MenuItem::query()->findOrFail(2);

        $this->assertSame(['menu_id' => $this->menu1->id], NestedSetScopeResolver::valuesFor($item));
    }

    // ----------------------------------------------------------------
    // Scope isolation — mutations
    // ----------------------------------------------------------------

    public function test_make_gap_in_menu1_does_not_shift_menu2_rows(): void
    {
        $this->mutator(['menu_id' => $this->menu1->id])->makeGap(at: 1, size: 2);

        $menu2Root = $this->row(4);
        $menu2X = $this->row(5);

        // Menu 2 rows must be completely untouched
        $this->assertSame(1, (int) $menu2Root->lft);
        $this->assertSame(4, (int) $menu2Root->rgt);
        $this->assertSame(2, (int) $menu2X->lft);
        $this->assertSame(3, (int) $menu2X->rgt);

        // Menu 1 root must have shifted
        $menu1Root = $this->row(1);
        $this->assertSame(3, (int) $menu1Root->lft);
        $this->assertSame(8, (int) $menu1Root->rgt);
    }

    public function test_move_node_in_menu1_does_not_affect_menu2(): void
    {
        // Swap A (lft 2-3) and B (lft 4-5) within Menu 1.
        // position = B.rgt + 1 = 6 in original coordinates.
        $this->mutator(['menu_id' => $this->menu1->id])
            ->moveNode(
                from: new NodeBounds(lft: 2, rgt: 3, depth: 1),
                position: 6,
                depthDelta: 0,
            );

        $menu2Root = $this->row(4);
        $menu2X = $this->row(5);

        $this->assertSame(1, (int) $menu2Root->lft);
        $this->assertSame(4, (int) $menu2Root->rgt);
        $this->assertSame(2, (int) $menu2X->lft);
        $this->assertSame(3, (int) $menu2X->rgt);
    }

    // ----------------------------------------------------------------
    // Scope isolation — repair
    // ----------------------------------------------------------------

    public function test_rebuild_tree_scoped_to_menu1_does_not_touch_menu2(): void
    {
        // Break menu 1 only; expect repair to fix it while leaving menu 2 alone.
        DB::table('menu_items')->where('menu_id', $this->menu1->id)->update([
            'lft' => 0, 'rgt' => 0, 'depth' => 0,
        ]);

        $this->repair(['menu_id' => $this->menu1->id])->rebuildTree();

        // Menu 1 healed
        $this->assertFalse($this->repair(['menu_id' => $this->menu1->id])->isBroken());

        // Menu 2 unchanged
        $menu2X = $this->row(5);
        $this->assertSame(2, (int) $menu2X->lft);
        $this->assertSame(3, (int) $menu2X->rgt);
    }

    public function test_count_errors_scoped_to_one_menu_only(): void
    {
        // Break menu 1; menu 2 stays valid.
        $this->allowBrokenTreeAtTearDown = true;
        DB::table('menu_items')->where('menu_id', $this->menu1->id)->update([
            'lft' => 0, 'rgt' => 0,
        ]);

        $menu1Errors = $this->repair(['menu_id' => $this->menu1->id])->countErrors();
        $menu2Errors = $this->repair(['menu_id' => $this->menu2->id])->countErrors();

        $this->assertGreaterThan(0, $menu1Errors['invalid_bounds']);
        $this->assertSame(0, $menu2Errors['invalid_bounds']);
    }

    // ----------------------------------------------------------------
    // Scoped repair API requires an anchor — calling isBroken /
    // countErrors / fixTree on a scoped model without one walks the
    // whole table, which is rarely what the caller intends. The
    // shared `repairBuilder` guard rejects the call up front; below
    // tests cover that the three Model-facing entries all funnel
    // through it.
    // ----------------------------------------------------------------

    public function test_is_broken_on_scoped_model_without_anchor_throws(): void
    {
        $this->expectException(ScopeViolationException::class);
        $this->expectExceptionMessageMatches('/menu_id/');

        MenuItem::isBroken();
    }

    public function test_count_errors_on_scoped_model_without_anchor_throws(): void
    {
        $this->expectException(ScopeViolationException::class);

        MenuItem::countErrors();
    }

    public function test_fix_tree_on_scoped_model_without_anchor_throws(): void
    {
        $this->expectException(ScopeViolationException::class);

        MenuItem::fixTree();
    }

    // ----------------------------------------------------------------
    // assertSameScope
    // ----------------------------------------------------------------

    public function test_assert_same_scope_passes_for_same_menu(): void
    {
        $a = MenuItem::query()->findOrFail(2);
        $b = MenuItem::query()->findOrFail(3);

        NestedSetScopeResolver::assertSameScope($a, $b);

        $this->addToAssertionCount(1);
    }

    public function test_assert_same_scope_throws_for_different_menus(): void
    {
        $a = MenuItem::query()->findOrFail(2); // menu 1
        $b = MenuItem::query()->findOrFail(5); // menu 2

        $this->expectException(ScopeViolationException::class);
        $this->expectExceptionMessageMatches('/menu_id differs/');

        NestedSetScopeResolver::assertSameScope($a, $b);
    }

    public function test_assert_same_scope_throws_for_different_models(): void
    {
        $a = MenuItem::query()->findOrFail(2);
        $b = Category::query()->forceCreate([
            'name' => 'X', 'lft' => 1, 'rgt' => 2, 'depth' => 0, 'parent_id' => null,
        ]);

        $this->expectException(ScopeViolationException::class);

        NestedSetScopeResolver::assertSameScope($a, $b);
    }

    public function test_assert_same_scope_treats_numeric_string_and_int_as_equal(): void
    {
        // Eloquent casts usually normalise menu_id to int, but a model
        // hydrated via setRawAttributes without casts (or a raw DB
        // result) can carry the value as a numeric string. The
        // comparator must not throw for `int 5` vs `'5'` — they're
        // the same scope.
        $a = MenuItem::query()->findOrFail(2);
        $b = MenuItem::query()->findOrFail(3);
        $b->setRawAttributes(array_merge($b->getAttributes(), ['menu_id' => (string) $this->menu1->id]));

        NestedSetScopeResolver::assertSameScope($a, $b);

        $this->addToAssertionCount(1);
    }

    public function test_assert_same_scope_treats_distinct_numeric_values_as_different(): void
    {
        $a = MenuItem::query()->findOrFail(2);
        $b = MenuItem::query()->findOrFail(2);
        $b->setRawAttributes(array_merge($b->getAttributes(), ['menu_id' => '999']));

        $this->expectException(ScopeViolationException::class);

        NestedSetScopeResolver::assertSameScope($a, $b);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function row(int $id): \stdClass
    {
        $row = DB::table('menu_items')->where('id', $id)->first();
        if ($row === null) {
            $this->fail("Row {$id} not found");
        }

        return $row;
    }
}
