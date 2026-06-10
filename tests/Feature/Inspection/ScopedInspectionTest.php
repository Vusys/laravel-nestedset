<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Inspection;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Each scope restarts lft at 1, so nodes in different trees routinely
 * share overlapping bounds. isDescendantOf() / isAncestorOf() must not
 * report a cross-scope relationship from bounds alone — the same guard
 * isSiblingOf() already applies.
 */
final class ScopedInspectionTest extends TestCase
{
    #[Test]
    public function descendant_and_ancestor_checks_respect_scope(): void
    {
        $r1 = new MenuItem(['name' => 'R1', 'menu_id' => 1]);
        $r1->saveAsRoot();
        $c1 = new MenuItem(['name' => 'C1', 'menu_id' => 1]);
        $c1->appendToNode($r1->refresh())->save();

        $r2 = new MenuItem(['name' => 'R2', 'menu_id' => 2]);
        $r2->saveAsRoot();
        $c2 = new MenuItem(['name' => 'C2', 'menu_id' => 2]);
        $c2->appendToNode($r2->refresh())->save();

        $r1->refresh();
        $c2->refresh();

        // Bounds overlap across menus (both start lft at 1), but the
        // nodes live in different trees.
        $this->assertFalse($c2->isDescendantOf($r1), 'cross-scope node is not a descendant');
        $this->assertFalse($r1->isAncestorOf($c2), 'cross-scope node is not an ancestor');

        // Within the same scope the relationship still holds.
        $this->assertTrue($c1->refresh()->isDescendantOf($r1));
        $this->assertTrue($r1->isAncestorOf($c1->refresh()));
    }
}
