<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Scoping;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * defaultOrder()/reversed() on a scoped model must group rows by scope
 * first — every tree restarts lft at 1, so without it the rows of
 * different trees interleave non-deterministically across backends.
 */
final class ScopedDefaultOrderTest extends TestCase
{
    #[Test]
    public function default_order_groups_each_tree_as_a_contiguous_block(): void
    {
        $this->seedMenu(1, ['A1', 'A2']);
        $this->seedMenu(2, ['B1', 'B2']);

        // Read the menu_id in defaultOrder() row order. Each menu's nodes
        // must form one contiguous run, not an arbitrary cross-tree
        // interleave.
        $scopeSequence = [];
        foreach (MenuItem::query()->defaultOrder()->get(['menu_id']) as $row) {
            $scopeSequence[] = (int) $row->menu_id;
        }

        // The scope sequence must be non-decreasing (all of menu 1, then 2).
        $sorted = $scopeSequence;
        sort($sorted);
        $this->assertSame($sorted, $scopeSequence, 'trees must not interleave under defaultOrder()');
    }

    /**
     * @param  list<string>  $childNames
     */
    private function seedMenu(int $menuId, array $childNames): void
    {
        $menu = new Menu(['name' => "menu {$menuId}"]);
        $menu->forceFill(['id' => $menuId])->save();

        $root = new MenuItem(['menu_id' => $menuId, 'name' => "root{$menuId}"]);
        $root->makeRoot()->save();

        foreach ($childNames as $name) {
            $child = new MenuItem(['menu_id' => $menuId, 'name' => $name]);
            $child->appendToNode($root->refresh())->save();
        }
    }
}
