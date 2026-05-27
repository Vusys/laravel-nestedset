<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Corruption;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Executable companion to docs/maintenance/corruption.md §5.4 — the
 * scoped-only corruption modes that the unscoped `CorruptionRecoveryTest`
 * can't exercise.
 *
 * Tree shape (both menus identical):
 *   Root  lft=1 rgt=4 depth=0  parent_id=null
 *     A   lft=2 rgt=3 depth=1  parent_id=Root.id
 */
final class ScopedCorruptionRecoveryTest extends TestCase
{
    /** Every test in this class deliberately corrupts the tree. */
    protected bool $allowBrokenTreeAtTearDown = true;

    private Menu $menu1;

    private Menu $menu2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->menu1 = Menu::create(['name' => 'Menu 1']);
        $this->menu2 = Menu::create(['name' => 'Menu 2']);

        DB::table('menu_items')->insert([
            ['id' => 1, 'menu_id' => $this->menu1->id, 'name' => 'Root1', 'lft' => 1, 'rgt' => 4, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'menu_id' => $this->menu1->id, 'name' => 'A1',    'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'menu_id' => $this->menu2->id, 'name' => 'Root2', 'lft' => 1, 'rgt' => 4, 'depth' => 0, 'parent_id' => null],
            ['id' => 4, 'menu_id' => $this->menu2->id, 'name' => 'A2',    'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 3],
        ]);
        $this->syncSequence('menu_items');
    }

    /**
     * Snapshot bounds keyed by id as int arrays — same shape as the
     * unscoped CorruptionRecoveryTest helper, kept inline here so this
     * file stays standalone.
     *
     * @return array<int, array{lft: int, rgt: int, depth: int}>
     */
    private function snapshotBounds(): array
    {
        $snapshot = [];
        foreach (DB::table('menu_items')->orderBy('id')->get(['id', 'lft', 'rgt', 'depth']) as $row) {
            $snapshot[(int) $row->id] = [
                'lft' => (int) $row->lft,
                'rgt' => (int) $row->rgt,
                'depth' => (int) $row->depth,
            ];
        }

        return $snapshot;
    }

    public function test_cross_scope_parent_id_is_treated_as_orphan_by_same_scope_check(): void
    {
        // Real-world cause: a raw UPDATE moved A1 between menus by
        // pointing parent_id at Root2 (id=3) — a row that exists in the
        // table but lives in menu_id=2. The package's public API would
        // have rejected this with ScopeViolationException; raw SQL
        // bypasses that guard.
        DB::table('menu_items')->where('id', 2)->update(['parent_id' => 3]);

        // Menu 1's orphan check: A1's parent_id=3 is a real row, but
        // the JOIN equates menu_id across child/parent, so the parent
        // join misses and A1 reads as orphan. This is the scoped
        // orphan-detection contract per corruption.md §5.4.
        $menu1Anchor = MenuItem::query()->findOrFail(1);
        $menu1Errors = MenuItem::countErrors($menu1Anchor);
        $this->assertSame(
            1,
            $menu1Errors['orphans'],
            'menu 1 should report A1 as orphan — parent_id points across scopes',
        );

        // Menu 2's orphan check: A1 lives in menu_id=1 and is not
        // visible from the menu 2 scope at all, so menu 2 reports
        // zero orphans. Cross-scope corruption surfaces only on the
        // scope the corrupt row lives in.
        $menu2Anchor = MenuItem::query()->findOrFail(3);
        $menu2Errors = MenuItem::countErrors($menu2Anchor);
        $this->assertSame(
            0,
            $menu2Errors['orphans'],
            'menu 2 should not see A1 — it is not in this scope',
        );
    }

    public function test_cross_scope_parent_id_is_not_auto_recovered_by_fix_tree(): void
    {
        $boundsBefore = $this->snapshotBounds();

        // Same corruption: A1 (menu 1) points at Root2 (menu 2).
        DB::table('menu_items')->where('id', 2)->update(['parent_id' => 3]);

        // fixTree($anchor) on menu 1: rebuilds menu 1's tree by walking
        // null-parent roots within menu_id=1. Root1 is the only null
        // root in scope, and A1 is now reachable only via parent_id=3
        // (which is filtered out by the scope JOIN), so A1 is not
        // visited by the rebuild — its bounds stay stale and the
        // orphan condition persists.
        $menu1Anchor = MenuItem::query()->findOrFail(1);
        MenuItem::fixTree($menu1Anchor);

        $boundsAfter = $this->snapshotBounds();

        // A1's bounds: untouched. The rebuild walked Root1 only.
        $this->assertSame(
            $boundsBefore[2]['lft'],
            $boundsAfter[2]['lft'],
            'A1 lft mutated despite being unreachable from menu 1 root',
        );
        $this->assertSame(
            $boundsBefore[2]['rgt'],
            $boundsAfter[2]['rgt'],
            'A1 rgt mutated despite being unreachable from menu 1 root',
        );

        // Root1's bounds: collapsed to a leaf (rgt=2) because A1 was
        // skipped during the rebuild and Root1 ended up with no
        // descendants in the visited graph.
        $this->assertLessThan(
            $boundsBefore[1]['rgt'],
            $boundsAfter[1]['rgt'],
            'Root1 rgt should collapse — A1 was not walked as a descendant',
        );

        // Menu 2 untouched (the anchor scoped the rebuild).
        foreach ([3, 4] as $menu2Id) {
            $this->assertSame(
                $boundsBefore[$menu2Id]['lft'],
                $boundsAfter[$menu2Id]['lft'],
                "menu 2 row #{$menu2Id} mutated despite anchor on menu 1",
            );
        }

        // Detection still reports the orphan after the rebuild — the
        // doc-prescribed recovery is one of {re-parent within scope,
        // promote to root, delete}, none of which the package can guess.
        $menu1ErrorsAfter = MenuItem::countErrors($menu1Anchor);
        $this->assertSame(
            1,
            $menu1ErrorsAfter['orphans'],
            'orphan still detected after fixTree — recovery requires a domain decision',
        );
    }
}
