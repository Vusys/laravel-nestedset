<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Scoping;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The positional read methods that take NodeBounds must not leak across
 * scopes. Scoped forests all restart lft at 1, so an unscoped predicate
 * matches every partition. getBounds() now carries the node's scope and
 * the query methods apply it.
 */
final class ScopedPositionalReadTest extends TestCase
{
    #[Test]
    public function where_descendant_of_stays_in_scope(): void
    {
        [$menu1Root] = $this->seedMenu('Menu 1');
        [$menu2Root] = $this->seedMenu('Menu 2');

        $descendants = MenuItem::query()
            ->whereDescendantOf($menu1Root->getBounds())
            ->get();

        $this->assertCount(2, $descendants, 'only menu 1 descendants should match');
        foreach ($descendants as $node) {
            $this->assertSame($menu1Root->menu_id, $node->menu_id);
        }

        // Sanity: the other menu has its own descendants, untouched.
        $other = MenuItem::query()->whereDescendantOf($menu2Root->getBounds())->get();
        $this->assertCount(2, $other);
    }

    #[Test]
    public function where_ancestor_of_stays_in_scope(): void
    {
        [, , $menu1Leaf] = $this->seedMenu('Menu 1');
        $this->seedMenu('Menu 2');

        $ancestors = MenuItem::query()
            ->whereAncestorOf($menu1Leaf->getBounds())
            ->get();

        // root + mid, all in menu 1.
        $this->assertCount(2, $ancestors);
        foreach ($ancestors as $node) {
            $this->assertSame($menu1Leaf->menu_id, $node->menu_id);
        }
    }

    /**
     * Plants root → mid → leaf in a fresh menu.
     *
     * @return array{0: MenuItem, 1: MenuItem, 2: MenuItem}
     */
    private function seedMenu(string $name): array
    {
        $menu = new Menu(['name' => $name]);
        $menu->save();

        $root = new MenuItem(['menu_id' => $menu->id, 'name' => 'Root']);
        $root->makeRoot()->save();

        $mid = new MenuItem(['menu_id' => $menu->id, 'name' => 'Mid']);
        $mid->appendToNode($root->refresh())->save();

        $leaf = new MenuItem(['menu_id' => $menu->id, 'name' => 'Leaf']);
        $leaf->appendToNode($mid->refresh())->save();

        return [$root->refresh(), $mid->refresh(), $leaf->refresh()];
    }
}
