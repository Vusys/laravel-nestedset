<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\MaterialisedPath;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\MultiScopedPathItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Subtree path rewrites on a two-column-scoped model. The scope
 * predicates must bind in declared-column order; reversing them bound
 * tenant_id to menu_id and vice versa, so a rename either missed every
 * descendant or rewrote another partition's rows.
 */
final class MaterialisedPathMultiScopeTest extends TestCase
{
    #[Test]
    public function renaming_rewrites_only_the_owning_partition(): void
    {
        // Two partitions with swapped scope values so a reversed binding
        // would point each tree's rewrite at the other tree.
        $aRoot = new MultiScopedPathItem(['tenant_id' => 1, 'menu_id' => 2, 'name' => 'Electronics']);
        $aRoot->makeRoot()->save();
        $aChild = new MultiScopedPathItem(['tenant_id' => 1, 'menu_id' => 2, 'name' => 'Laptops']);
        $aChild->appendToNode($aRoot->refresh())->save();
        $aGrand = new MultiScopedPathItem(['tenant_id' => 1, 'menu_id' => 2, 'name' => 'Ultrabooks']);
        $aGrand->appendToNode($aChild->refresh())->save();

        $bRoot = new MultiScopedPathItem(['tenant_id' => 2, 'menu_id' => 1, 'name' => 'Electronics']);
        $bRoot->makeRoot()->save();
        $bChild = new MultiScopedPathItem(['tenant_id' => 2, 'menu_id' => 1, 'name' => 'Laptops']);
        $bChild->appendToNode($bRoot->refresh())->save();

        // Rename an internal node in partition A.
        $aChild->name = 'Computers';
        $aChild->save();

        // Partition A descendants rewritten through the renamed prefix.
        $this->assertSame('/electronics/computers/', $aChild->refresh()->url_path);
        $this->assertSame('/electronics/computers/ultrabooks/', $aGrand->refresh()->url_path);

        // Partition B (swapped scope values) is untouched.
        $this->assertSame('/electronics/', $bRoot->refresh()->url_path);
        $this->assertSame('/electronics/laptops/', $bChild->refresh()->url_path);
    }
}
