<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Scoping;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\Support\FuzzerConfig;
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
#[Group('fuzzer')]
final class ScopeIsolationFuzzerTest extends TestCase
{
    /**
     * @return iterable<string, array{seed: int, menuCount: int, steps: int}>
     */
    public static function seedProvider(): iterable
    {
        $seeds = FuzzerConfig::seeds([1, 42, 1337, 9999]);
        $steps = FuzzerConfig::steps(30);
        $menuCount = FuzzerConfig::runs(3);

        foreach ($seeds as $seed) {
            yield "seed {$seed}, {$menuCount} menus, {$steps} steps" => [
                'seed' => $seed, 'menuCount' => $menuCount, 'steps' => $steps,
            ];
        }
    }

    #[DataProvider('seedProvider')]
    #[Test]
    public function mutations_in_one_scope_dont_touch_others(
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
        return match (true) {
            $roll < 15 => 'append',
            $roll < 25 => 'prepend',
            $roll < 35 => 'insertBefore',
            $roll < 45 => 'insertAfter',
            $roll < 55 => 'move',
            $roll < 62 => 'makeRoot',
            $roll < 69 => 'siblingUp',
            $roll < 76 => 'siblingDown',
            $roll < 82 => 'bulkInsert',
            $roll < 92 => 'delete',
            $roll < 98 => 'update',
            default => 'noop',
        };
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

            case 'insertBefore':
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
                $node->insertBeforeNode($sibling->refresh())->save();

                return;

            case 'makeRoot':
                $candidates = array_values(array_filter(
                    $all,
                    fn (MenuItem $n): bool => $n->parent_id !== null,
                ));
                if ($candidates === []) {
                    return;
                }
                $candidates[mt_rand(0, count($candidates) - 1)]->makeRoot()->save();

                return;

            case 'siblingUp':
                $candidates = array_values(array_filter(
                    $all,
                    fn (MenuItem $n): bool => $n->parent_id !== null,
                ));
                if ($candidates === []) {
                    return;
                }
                $candidates[mt_rand(0, count($candidates) - 1)]->up();

                return;

            case 'siblingDown':
                $candidates = array_values(array_filter(
                    $all,
                    fn (MenuItem $n): bool => $n->parent_id !== null,
                ));
                if ($candidates === []) {
                    return;
                }
                $candidates[mt_rand(0, count($candidates) - 1)]->down();

                return;

            case 'bulkInsert':
                $anchor = $all[mt_rand(0, count($all) - 1)]->refresh();
                $spec = $this->randomBulkInsertSpec($menuId, $step, depth: mt_rand(1, 2), siblings: mt_rand(1, 3));
                MenuItem::bulkInsertTree($spec, appendTo: $anchor);

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
     * Small random tree spec for bulkInsertTree. The anchor's
     * scope-column values are copied onto every inserted row by
     * bulkInsertTree, so the spec only carries display attributes.
     *
     * @return list<array<string, mixed>>
     */
    private function randomBulkInsertSpec(int $menuId, int $step, int $depth, int $siblings): array
    {
        static $tag = 0;
        $out = [];
        for ($i = 0; $i < $siblings; $i++) {
            $tag++;
            $node = ['name' => "m{$menuId}_bk{$step}_{$tag}"];
            if ($depth > 1 && mt_rand(0, 1) === 1) {
                $node['children'] = $this->randomBulkInsertSpec($menuId, $step, $depth - 1, mt_rand(1, 2));
            }
            $out[] = $node;
        }

        return $out;
    }

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
        $this->assertFalse(
            MenuItem::isBroken(new MenuItem(['menu_id' => $menuId])),
            "{$tag}: tree broken: ".json_encode(MenuItem::countErrors(new MenuItem(['menu_id' => $menuId]))),
        );

        // Leaf hard-delete compacts via closeGap (HasTreeMutation::
        // applyStructuralCleanupOnDelete) so the bounds sequence stays
        // contiguous. Per-root: {lft, rgt} must be a permutation of
        // rootLft..rootRgt.
        /** @var list<object{id: int, lft: int, rgt: int, parent_id: int|null}> $rows */
        $rows = DB::table('menu_items')
            ->where('menu_id', $menuId)
            ->get(['id', 'lft', 'rgt', 'parent_id'])
            ->all();

        if ($rows === []) {
            return;
        }

        $rootRanges = [];
        foreach ($rows as $r) {
            if ($r->parent_id === null) {
                $rootRanges[(int) $r->id] = [(int) $r->lft, (int) $r->rgt];
            }
        }

        foreach ($rootRanges as $rootId => [$rootLft, $rootRgt]) {
            $bounds = [];
            foreach ($rows as $r) {
                if ((int) $r->lft >= $rootLft && (int) $r->rgt <= $rootRgt) {
                    $bounds[] = (int) $r->lft;
                    $bounds[] = (int) $r->rgt;
                }
            }
            sort($bounds);
            $expected = range($rootLft, $rootRgt);
            $this->assertSame(
                $expected,
                $bounds,
                "{$tag}: root #{$rootId} bounds aren't a perm of {$rootLft}..{$rootRgt}",
            );
        }
    }
}
