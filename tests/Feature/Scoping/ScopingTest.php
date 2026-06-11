<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Scoping;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function resolver_reads_columns_from_attribute(): void
    {
        $this->assertSame(['menu_id'], NestedSetScopeResolver::columns(MenuItem::class));
    }

    #[Test]
    public function resolver_returns_empty_for_unscoped_model(): void
    {
        $this->assertSame(
            [],
            NestedSetScopeResolver::columns(Category::class),
        );
    }

    #[Test]
    public function resolver_reads_values_from_node(): void
    {
        $item = MenuItem::query()->findOrFail(2);

        $this->assertSame(['menu_id' => $this->menu1->id], NestedSetScopeResolver::valuesFor($item));
    }

    // ----------------------------------------------------------------
    // Scope isolation — mutations
    // ----------------------------------------------------------------

    #[Test]
    public function make_gap_in_menu1_does_not_shift_menu2_rows(): void
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

    #[Test]
    public function move_node_in_menu1_does_not_affect_menu2(): void
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

    #[Test]
    public function rebuild_tree_scoped_to_menu1_does_not_touch_menu2(): void
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

    #[Test]
    public function count_errors_scoped_to_one_menu_only(): void
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

    #[Test]
    public function orphan_detection_joins_parent_within_scope(): void
    {
        // Cross-scope parent pretender: menu_items id=2 is a child in
        // menu 1 with parent_id=1, but suppose its parent_id were 4
        // (menu 2's root). Without scope on the parent side of the
        // orphan join, the LEFT JOIN would resolve to row 4 and the
        // orphan would silently NOT be reported. With the scoped
        // JOIN, the parent-side match is constrained to menu 1, so
        // row 4 doesn't qualify and the orphan is flagged.
        $this->allowBrokenTreeAtTearDown = true;
        DB::table('menu_items')->where('id', 2)->update(['parent_id' => 4]);

        $menu1Errors = $this->repair(['menu_id' => $this->menu1->id])->countErrors();

        $this->assertSame(
            1,
            $menu1Errors['orphans'],
            'menu_items id=2 points at row 4 in a different scope — must count as an orphan',
        );
    }

    // ----------------------------------------------------------------
    // Scoped repair API requires an anchor — calling isBroken /
    // countErrors / fixTree on a scoped model without one walks the
    // whole table, which is rarely what the caller intends. The
    // shared `repairBuilder` guard rejects the call up front; below
    // tests cover that the three Model-facing entries all funnel
    // through it.
    // ----------------------------------------------------------------

    #[Test]
    public function is_broken_on_scoped_model_without_anchor_throws(): void
    {
        $this->expectException(ScopeViolationException::class);
        $this->expectExceptionMessageMatches('/menu_id/');

        MenuItem::isBroken();
    }

    #[Test]
    public function count_errors_on_scoped_model_without_anchor_throws(): void
    {
        $this->expectException(ScopeViolationException::class);

        MenuItem::countErrors();
    }

    #[Test]
    public function fix_tree_on_scoped_model_without_anchor_throws(): void
    {
        $this->expectException(ScopeViolationException::class);

        MenuItem::fixTree();
    }

    // ----------------------------------------------------------------
    // assertSameScope
    // ----------------------------------------------------------------

    #[Test]
    public function assert_same_scope_passes_for_same_menu(): void
    {
        $a = MenuItem::query()->findOrFail(2);
        $b = MenuItem::query()->findOrFail(3);

        NestedSetScopeResolver::assertSameScope($a, $b);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function assert_same_scope_throws_for_different_menus(): void
    {
        $a = MenuItem::query()->findOrFail(2); // menu 1
        $b = MenuItem::query()->findOrFail(5); // menu 2

        $this->expectException(ScopeViolationException::class);
        $this->expectExceptionMessageMatches('/menu_id differs/');

        NestedSetScopeResolver::assertSameScope($a, $b);
    }

    #[Test]
    public function assert_same_scope_throws_for_different_models(): void
    {
        $a = MenuItem::query()->findOrFail(2);
        $b = Category::query()->forceCreate([
            'name' => 'X', 'lft' => 1, 'rgt' => 2, 'depth' => 0, 'parent_id' => null,
        ]);

        $this->expectException(ScopeViolationException::class);

        NestedSetScopeResolver::assertSameScope($a, $b);
    }

    #[Test]
    public function assert_same_scope_treats_numeric_string_and_int_as_equal(): void
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

    #[Test]
    public function assert_same_scope_treats_distinct_numeric_values_as_different(): void
    {
        $a = MenuItem::query()->findOrFail(2);
        $b = MenuItem::query()->findOrFail(2);
        $b->setRawAttributes(array_merge($b->getAttributes(), ['menu_id' => '999']));

        $this->expectException(ScopeViolationException::class);

        NestedSetScopeResolver::assertSameScope($a, $b);
    }

    #[Test]
    public function assert_same_scope_treats_null_and_zero_as_different(): void
    {
        // PHP's loose-equality `null == 0` is true. The comparator's
        // early-exit on either side being null is therefore load-bearing,
        // not just an optimisation — without it, a node with `menu_id`
        // set to null would silently appear to belong to the same scope
        // as a node with `menu_id` set to 0.
        $a = MenuItem::query()->findOrFail(2);
        $b = MenuItem::query()->findOrFail(2);
        $a->setRawAttributes(array_merge($a->getAttributes(), ['menu_id' => null]));
        $b->setRawAttributes(array_merge($b->getAttributes(), ['menu_id' => 0]));

        $this->expectException(ScopeViolationException::class);

        NestedSetScopeResolver::assertSameScope($a, $b);
    }

    #[Test]
    public function assert_same_scope_treats_zero_and_null_as_different(): void
    {
        // Reverse argument order to also exercise the right-hand
        // `$b === null` branch of the null guard.
        $a = MenuItem::query()->findOrFail(2);
        $b = MenuItem::query()->findOrFail(2);
        $a->setRawAttributes(array_merge($a->getAttributes(), ['menu_id' => 0]));
        $b->setRawAttributes(array_merge($b->getAttributes(), ['menu_id' => null]));

        $this->expectException(ScopeViolationException::class);

        NestedSetScopeResolver::assertSameScope($a, $b);
    }

    #[Test]
    public function force_delete_cascade_uses_persisted_scope_not_dirty_in_memory_value(): void
    {
        // A user could mutate the scope attribute in memory without
        // saving and then call forceDelete(). The cascade query must
        // hit the *persisted* scope (the one the row actually lives
        // in), not the in-memory dirty value — otherwise it would
        // delete descendants from the wrong tree.
        //
        // Tree: menu1 has Root1 > A, B. menu2 has Root2 > X. We
        // forceDelete Root1 after dirty-rewriting its in-memory
        // menu_id to menu2's id; menu2's tree must stay intact and
        // menu1's subtree must be the one that's cleared.
        $root1 = MenuItem::query()->findOrFail(1);
        $root1->menu_id = $this->menu2->id;

        $root1->forceDelete();

        $this->assertNull(MenuItem::query()->find(1));
        $this->assertNull(MenuItem::query()->find(2), 'A (menu1) cascade-removed');
        $this->assertNull(MenuItem::query()->find(3), 'B (menu1) cascade-removed');

        $this->assertNotNull(MenuItem::query()->find(4), 'Root2 (menu2) survives');
        $this->assertNotNull(MenuItem::query()->find(5), 'X (menu2) survives');
    }

    #[Test]
    public function changing_the_scope_column_on_a_plain_save_is_rejected(): void
    {
        // Bare scope edit with no pending operation: the scoped mutation
        // path has no cross-scope check of its own, so this must be
        // caught in the saving listener or it silently shifts the wrong
        // tree on the next structural mutation.
        $a = MenuItem::query()->findOrFail(2);
        $a->menu_id = $this->menu2->id;

        $this->expectException(ScopeViolationException::class);
        $a->save();
    }

    #[Test]
    public function moving_between_trees_by_editing_scope_then_appending_is_rejected(): void
    {
        // The subtle case: setting menu_id to menu2 then appendToNode a
        // menu2 parent passes assertSameScope (both in-memory scopes are
        // menu2) but the row on disk is still in menu1 — the scoped
        // UPDATE would shift menu2 bystanders. Must be rejected.
        $a = MenuItem::query()->findOrFail(2);
        $root2 = MenuItem::query()->findOrFail(4);
        $a->menu_id = $this->menu2->id;

        $this->expectException(ScopeViolationException::class);
        $a->appendToNode($root2)->save();
    }

    #[Test]
    public function both_trees_stay_intact_after_a_rejected_cross_scope_save(): void
    {
        $a = MenuItem::query()->findOrFail(2);
        $a->menu_id = $this->menu2->id;

        try {
            $a->save();
        } catch (ScopeViolationException) {
            // expected
        }

        $this->assertSame(0, array_sum(MenuItem::countErrors(MenuItem::query()->findOrFail(1))));
        $this->assertSame(0, array_sum(MenuItem::countErrors(MenuItem::query()->findOrFail(4))));
    }

    // ----------------------------------------------------------------
    // prevSibling / nextSibling / up / down — root nodes are linked
    // only by `parent_id IS NULL`, so the lookup must also be
    // scope-filtered. Two scopes can independently produce roots whose
    // lft/rgt values collide, and a cross-scope sibling would let
    // up()/down() either throw a ScopeViolationException out of
    // nowhere or (worse) read from the wrong partition.
    // ----------------------------------------------------------------

    #[Test]
    public function prev_sibling_on_root_does_not_cross_scope(): void
    {
        $this->seedSiblingForestAcrossScopes();

        // Menu 1's trailing root (id=4, lft=3 rgt=4). Looking for prev
        // sibling: rgt = lft - 1 = 2. Menu 2 has a matching row (id=1,
        // rgt=2) and a lower id, so an unscoped first() returns it on
        // backends that fall back to physical order.
        $menu1Trailing = MenuItem::query()->findOrFail(4);

        $prev = $menu1Trailing->prevSibling();

        $this->assertNotNull($prev);
        $this->assertSame($this->menu1->id, $prev->menu_id);
        $this->assertSame(3, $prev->id, 'prevSibling() must return menu 1 leading root, not menu 2');
    }

    #[Test]
    public function next_sibling_on_root_does_not_cross_scope(): void
    {
        $this->seedSiblingForestAcrossScopes();

        // Menu 1's leading root (id=3, lft=1 rgt=2). nextSibling looks
        // for lft = rgt + 1 = 3. Menu 2 has a matching row (id=2, lft=3)
        // with a lower id, so unscoped first() returns it on physical-
        // order backends.
        $menu1Leading = MenuItem::query()->findOrFail(3);

        $next = $menu1Leading->nextSibling();

        $this->assertNotNull($next);
        $this->assertSame($this->menu1->id, $next->menu_id);
        $this->assertSame(4, $next->id, 'nextSibling() must return menu 1 trailing root, not menu 2');
    }

    #[Test]
    public function up_on_root_swaps_only_within_same_scope(): void
    {
        $this->seedSiblingForestAcrossScopes();

        $menu1Trailing = MenuItem::query()->findOrFail(4);

        $this->assertTrue($menu1Trailing->up());

        $menu1Trailing = $menu1Trailing->refresh();
        $menu1Leading = MenuItem::query()->findOrFail(3);
        $menu2Leading = MenuItem::query()->findOrFail(1);
        $menu2Trailing = MenuItem::query()->findOrFail(2);

        $this->assertLessThan($menu1Leading->lft, $menu1Trailing->lft);

        $this->assertSame(1, $menu2Leading->lft);
        $this->assertSame(2, $menu2Leading->rgt);
        $this->assertSame(3, $menu2Trailing->lft);
        $this->assertSame(4, $menu2Trailing->rgt);
    }

    private function seedSiblingForestAcrossScopes(): void
    {
        // Replace setUp's seed: each menu gets two NULL-parent roots
        // with matching lft/rgt ranges. Menu 2 takes the LOW ids so an
        // unscoped lookup — which falls back to physical order on
        // SQLite — returns the wrong-scope row when scope filtering is
        // missing.
        DB::table('menu_items')->delete();

        DB::table('menu_items')->insert([
            ['id' => 1, 'menu_id' => $this->menu2->id, 'name' => 'M2 Leading',  'lft' => 1, 'rgt' => 2, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'menu_id' => $this->menu2->id, 'name' => 'M2 Trailing', 'lft' => 3, 'rgt' => 4, 'depth' => 0, 'parent_id' => null],
            ['id' => 3, 'menu_id' => $this->menu1->id, 'name' => 'M1 Leading',  'lft' => 1, 'rgt' => 2, 'depth' => 0, 'parent_id' => null],
            ['id' => 4, 'menu_id' => $this->menu1->id, 'name' => 'M1 Trailing', 'lft' => 3, 'rgt' => 4, 'depth' => 0, 'parent_id' => null],
        ]);
        $this->syncSequence('menu_items');
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
