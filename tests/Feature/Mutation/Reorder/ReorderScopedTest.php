<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Mutation\Reorder;

use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\Fixtures\Models\MultiScopedBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The reorder UPDATE has to honour the parent's scope predicate so it
 * doesn't leak across trees. Covered with a single-column scope
 * (MenuItem) and a two-column scope (MultiScopedBranch) — the second
 * fixture exists because every other scoped fixture has exactly one
 * scope column, and a `$sql .= ...` → `$sql = ...` mutation in the
 * scope-loop SQL builder would still pass with one column.
 */
final class ReorderScopedTest extends TestCase
{
    public function test_single_scope_reorder_does_not_touch_other_scope(): void
    {
        $menu1 = Menu::create(['name' => 'Menu 1']);
        $menu2 = Menu::create(['name' => 'Menu 2']);

        [$root1, $a1, $b1, $c1] = $this->buildChainOfFour($menu1->id, 'm1');
        $this->buildChainOfFour($menu2->id, 'm2');

        // Snapshot menu 2's lft/rgt — should be untouched by menu 1's reorder.
        $menu2BoundsBefore = MenuItem::query()
            ->where('menu_id', $menu2->id)
            ->orderBy('id')
            ->get(['id', 'lft', 'rgt'])
            ->map(fn (MenuItem $n): array => [$n->id, $n->lft, $n->rgt])
            ->all();

        $root1->reorderChildren([$c1->id, $a1->id, $b1->id]);

        $menu2BoundsAfter = MenuItem::query()
            ->where('menu_id', $menu2->id)
            ->orderBy('id')
            ->get(['id', 'lft', 'rgt'])
            ->map(fn (MenuItem $n): array => [$n->id, $n->lft, $n->rgt])
            ->all();

        $this->assertSame($menu2BoundsBefore, $menu2BoundsAfter);

        $this->assertSame(
            ['m1-c', 'm1-a', 'm1-b'],
            MenuItem::query()
                ->where('menu_id', $menu1->id)
                ->where('parent_id', $root1->id)
                ->orderBy('lft')
                ->pluck('name')
                ->all(),
        );
    }

    public function test_multi_scope_reorder_does_not_leak_into_other_partitions(): void
    {
        // Same shape in four partitions: (t=1,s=1), (t=1,s=2), (t=2,s=1), (t=2,s=2).
        $partitions = [[1, 1], [1, 2], [2, 1], [2, 2]];
        $target = $this->buildMultiScopedThree(1, 1);
        $others = [];
        foreach ($partitions as [$tenant, $site]) {
            if ($tenant === 1 && $site === 1) {
                continue;
            }
            $others["{$tenant}-{$site}"] = $this->buildMultiScopedThree($tenant, $site);
        }

        // Snapshot every other partition before the reorder.
        $beforeSnapshots = [];
        foreach (array_keys($others) as $key) {
            [$t, $s] = explode('-', $key);
            $beforeSnapshots[$key] = $this->snapshotMultiScoped((int) $t, (int) $s);
        }

        $target['root']->reorderChildren([$target['c']->id, $target['a']->id, $target['b']->id]);

        foreach ($beforeSnapshots as $key => $before) {
            [$t, $s] = explode('-', $key);
            $after = $this->snapshotMultiScoped((int) $t, (int) $s);
            $this->assertSame(
                $before,
                $after,
                "Partition {$key} was modified by an unrelated reorder.",
            );
        }

        // Target partition reflects the new order.
        $names = MultiScopedBranch::query()
            ->where('tenant_id', 1)
            ->where('site_id', 1)
            ->where('parent_id', $target['root']->id)
            ->orderBy('lft')
            ->pluck('name')
            ->all();
        $this->assertSame(['t1-s1-c', 't1-s1-a', 't1-s1-b'], $names);
    }

    /**
     * @return array{MenuItem, MenuItem, MenuItem, MenuItem}
     */
    private function buildChainOfFour(int $menuId, string $prefix): array
    {
        $root = new MenuItem(['name' => "{$prefix}-root", 'menu_id' => $menuId]);
        $root->saveAsRoot();
        $a = new MenuItem(['name' => "{$prefix}-a", 'menu_id' => $menuId]);
        $a->appendToNode($root)->save();
        $b = new MenuItem(['name' => "{$prefix}-b", 'menu_id' => $menuId]);
        $b->appendToNode($root->refresh())->save();
        $c = new MenuItem(['name' => "{$prefix}-c", 'menu_id' => $menuId]);
        $c->appendToNode($root->refresh())->save();

        return [$root->refresh(), $a, $b, $c];
    }

    /**
     * @return array{root: MultiScopedBranch, a: MultiScopedBranch, b: MultiScopedBranch, c: MultiScopedBranch}
     */
    private function buildMultiScopedThree(int $tenantId, int $siteId): array
    {
        $attrs = static fn (string $name): array => [
            'name' => "t{$tenantId}-s{$siteId}-{$name}",
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'tickets' => 0,
        ];

        $root = new MultiScopedBranch($attrs('root'));
        $root->saveAsRoot();
        $a = new MultiScopedBranch($attrs('a'));
        $a->appendToNode($root)->save();
        $b = new MultiScopedBranch($attrs('b'));
        $b->appendToNode($root->refresh())->save();
        $c = new MultiScopedBranch($attrs('c'));
        $c->appendToNode($root->refresh())->save();

        return ['root' => $root->refresh(), 'a' => $a, 'b' => $b, 'c' => $c];
    }

    /**
     * @return list<array{int, int, int}>
     */
    private function snapshotMultiScoped(int $tenant, int $site): array
    {
        /** @var list<array{int, int, int}> $rows */
        $rows = MultiScopedBranch::query()
            ->where('tenant_id', $tenant)
            ->where('site_id', $site)
            ->orderBy('id')
            ->get(['id', 'lft', 'rgt'])
            ->map(fn (MultiScopedBranch $n): array => [$n->id, $n->lft, $n->rgt])
            ->values()
            ->all();

        return $rows;
    }
}
