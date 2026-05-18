<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Multi-tenant fuzzing for scoped trees.
 *
 * MenuItem declares `#[NestedSetScope('menu_id')]` — every tree
 * operation must restrict its lft/rgt arithmetic to rows in the same
 * scope. Cross-scope leakage is the worst failure mode here: a write
 * in tenant A shifts rows in tenant B, corrupting both.
 *
 * Strategy:
 *   1. Plant N independent menus, each with its own random tree.
 *   2. Take a snapshot per menu (id → lft/rgt/parent/depth).
 *   3. Randomly mutate ONE menu per step.
 *   4. After every step: that menu is still valid, AND every OTHER
 *      menu's snapshot is byte-identical to before.
 *
 * If a mutation in menu 1 changes a single byte of menu 2's snapshot,
 * the test fails and reports exactly which row drifted.
 */
final class ScopeIsolationFuzzerTest extends TestCase
{
    /**
     * @return iterable<string, array{seed: int, menuCount: int, steps: int}>
     */
    public static function seedProvider(): iterable
    {
        yield 'seed 1, 3 menus, 30 steps' => [
            'seed' => 1, 'menuCount' => 3, 'steps' => 30,
        ];

        yield 'seed 42, 4 menus, 30 steps' => [
            'seed' => 42, 'menuCount' => 4, 'steps' => 30,
        ];

        yield 'seed 1337, 5 menus, 40 steps' => [
            'seed' => 1337, 'menuCount' => 5, 'steps' => 40,
        ];

        yield 'seed 9999, 3 menus, 50 steps' => [
            'seed' => 9999, 'menuCount' => 3, 'steps' => 50,
        ];
    }

    #[DataProvider('seedProvider')]
    public function test_mutations_in_one_scope_dont_touch_others(
        int $seed,
        int $menuCount,
        int $steps,
    ): void {
        mt_srand($seed);

        // Plant `menuCount` menus, each with 3-7 random nodes.
        /** @var list<int> $menuIds */
        $menuIds = [];
        for ($m = 0; $m < $menuCount; $m++) {
            $menu = Menu::create(['name' => "Menu {$m}"]);
            $menuIds[] = (int) $menu->id;

            $root = new MenuItem(['name' => "m{$m}_root", 'menu_id' => $menu->id]);
            $root->saveAsRoot();

            $plant = mt_rand(3, 7);
            for ($i = 0; $i < $plant; $i++) {
                $candidates = MenuItem::query()
                    ->where('menu_id', $menu->id)
                    ->orderBy('lft')
                    ->get()
                    ->all();
                $parent = $candidates[mt_rand(0, count($candidates) - 1)];

                $node = new MenuItem(['name' => "m{$m}_n{$i}", 'menu_id' => $menu->id]);
                $node->appendToNode($parent->refresh())->save();
            }
        }

        // Validate the initial state of every menu.
        foreach ($menuIds as $menuId) {
            $this->assertMenuValid($menuId, "[seed={$seed}] initial menu={$menuId}");
        }

        // Random mutation loop — each step picks ONE menu, snapshots
        // every OTHER menu, mutates, then asserts the snapshots are
        // byte-identical.
        for ($step = 1; $step <= $steps; $step++) {
            $targetMenuId = $menuIds[mt_rand(0, count($menuIds) - 1)];
            $otherMenuIds = array_filter($menuIds, fn (int $m): bool => $m !== $targetMenuId);

            $snapshots = [];
            foreach ($otherMenuIds as $otherId) {
                $snapshots[$otherId] = $this->snapshot($otherId);
            }

            $action = $this->pickAction(mt_rand(0, 99));
            $this->doStep($targetMenuId, $action, $step);

            $this->assertMenuValid($targetMenuId, "[seed={$seed}] step {$step} ({$action}) target={$targetMenuId}");

            foreach ($otherMenuIds as $otherId) {
                $now = $this->snapshot($otherId);
                $this->assertSame(
                    $snapshots[$otherId],
                    $now,
                    "[seed={$seed}] step {$step} ({$action} on menu {$targetMenuId}): cross-scope leak into menu {$otherId}",
                );
            }
        }
    }

    // ================================================================
    // Mutation picker + per-action handlers (scoped)
    // ================================================================

    private function pickAction(int $roll): string
    {
        if ($roll < 25) {
            return 'append';
        }
        if ($roll < 40) {
            return 'prepend';
        }
        if ($roll < 55) {
            return 'insertAfter';
        }
        if ($roll < 70) {
            return 'move';
        }
        if ($roll < 85) {
            return 'delete';
        }
        if ($roll < 95) {
            return 'update';
        }

        return 'noop';
    }

    private function doStep(int $menuId, string $action, int $step): void
    {
        $all = MenuItem::query()
            ->where('menu_id', $menuId)
            ->orderBy('lft')
            ->get()
            ->all();
        if ($all === []) {
            return;
        }

        switch ($action) {
            case 'append':
                $parent = $all[mt_rand(0, count($all) - 1)];
                $node = new MenuItem(['name' => "s{$step}", 'menu_id' => $menuId]);
                $node->appendToNode($parent->refresh())->save();

                return;

            case 'prepend':
                $parent = $all[mt_rand(0, count($all) - 1)];
                $node = new MenuItem(['name' => "s{$step}", 'menu_id' => $menuId]);
                $node->prependToNode($parent->refresh())->save();

                return;

            case 'insertAfter':
                $candidates = array_values(array_filter(
                    $all,
                    fn (MenuItem $n): bool => $n->parent_id !== null,
                ));
                if ($candidates === []) {
                    $parent = $all[mt_rand(0, count($all) - 1)];
                    $node = new MenuItem(['name' => "s{$step}", 'menu_id' => $menuId]);
                    $node->appendToNode($parent->refresh())->save();

                    return;
                }
                $sibling = $candidates[mt_rand(0, count($candidates) - 1)];
                $node = new MenuItem(['name' => "s{$step}", 'menu_id' => $menuId]);
                $node->insertAfterNode($sibling->refresh())->save();

                return;

            case 'move':
                $movables = array_values(array_filter(
                    $all,
                    fn (MenuItem $n): bool => $n->parent_id !== null && ($n->rgt - $n->lft) === 1,
                ));
                if ($movables === []) {
                    return;
                }
                $node = $movables[mt_rand(0, count($movables) - 1)];
                $targets = array_values(array_filter(
                    $all,
                    fn (MenuItem $t): bool => $t->getKey() !== $node->getKey()
                        && $t->getKey() !== $node->parent_id
                        && ! $node->isAncestorOf($t),
                ));
                if ($targets === []) {
                    return;
                }
                $target = $targets[mt_rand(0, count($targets) - 1)];
                $node->appendToNode($target->refresh())->save();

                return;

            case 'delete':
                $leaves = array_values(array_filter(
                    $all,
                    fn (MenuItem $n): bool => ($n->rgt - $n->lft) === 1 && $n->parent_id !== null,
                ));
                if ($leaves === []) {
                    return;
                }
                $leaves[mt_rand(0, count($leaves) - 1)]->delete();

                return;

            case 'update':
                $t = $all[mt_rand(0, count($all) - 1)];
                $t->name = "u{$step}";
                $t->save();

                return;
        }
    }

    // ================================================================
    // Helpers
    // ================================================================

    /**
     * Returns a sorted-by-id snapshot of every row in the scope.
     * `assertSame` on two snapshots will diff at the first divergent
     * row — exactly what we want for cross-scope leak detection.
     *
     * @return array<int, array{lft: int, rgt: int, depth: int, parent_id: int|null}>
     */
    private function snapshot(int $menuId): array
    {
        $rows = DB::table('menu_items')
            ->where('menu_id', $menuId)
            ->orderBy('id')
            ->get(['id', 'lft', 'rgt', 'depth', 'parent_id'])
            ->all();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->id] = [
                'lft' => (int) $row->lft,
                'rgt' => (int) $row->rgt,
                'depth' => (int) $row->depth,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
            ];
        }

        return $out;
    }

    private function assertMenuValid(int $menuId, string $tag): void
    {
        // The package's contract: countErrors reports zero for the
        // scope. Gaps in lft/rgt are *not* errors — `delete()` on a
        // non-soft-delete model intentionally leaves gaps rather than
        // compacting (the cost of rewriting every row in the scope
        // is rarely worth it). Verified by the existing DeletionTest's
        // force-delete behaviour.
        $this->assertFalse(
            MenuItem::isBroken(new MenuItem(['menu_id' => $menuId])),
            "{$tag}: tree broken: ".json_encode(MenuItem::countErrors(new MenuItem(['menu_id' => $menuId]))),
        );

        // bounds must be unique within the scope (any duplicate is a
        // hard correctness break; isBroken does check this but we
        // assert directly so the failure message names the scope).
        /** @var list<object{lft: int, rgt: int}> $rows */
        $rows = DB::table('menu_items')
            ->where('menu_id', $menuId)
            ->get(['lft', 'rgt'])
            ->all();

        if ($rows === []) {
            return;
        }

        $bounds = [];
        foreach ($rows as $r) {
            $bounds[] = (int) $r->lft;
            $bounds[] = (int) $r->rgt;
        }
        $this->assertSame(
            count($bounds),
            count(array_unique($bounds)),
            "{$tag}: duplicate lft/rgt in scope",
        );
    }
}
