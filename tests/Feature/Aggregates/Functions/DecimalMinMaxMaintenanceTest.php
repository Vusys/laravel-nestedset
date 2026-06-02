<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Functions;

use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\PricedArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Pins the type-preserving read of MIN/MAX extremum columns on the
 * cheap-skip filter path. Earlier code cast the stored extremum
 * through `Numeric::asIntOrZero`, truncating a `decimal(10,2)` value
 * like 9.99 to 9 before composing the recompute WHERE — the equality
 * filter then matched no ancestor row and the recompute silently
 * no-op'd, leaving the ancestor's MIN/MAX stuck at the now-deleted
 * holder's value.
 */
final class DecimalMinMaxMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    public function test_delete_holder_with_decimal_price_recomputes_ancestor_max(): void
    {
        $root = new PricedArea(['name' => 'Root', 'price' => '1.00']);
        $root->saveAsRoot();

        $holder = new PricedArea(['name' => 'Holder', 'price' => '9.99']);
        $holder->appendToNode($root)->save();

        $other = new PricedArea(['name' => 'Other', 'price' => '5.50']);
        $other->appendToNode($root->refresh())->save();

        $this->assertSame('9.99', $root->refresh()->price_max);

        $holder->refresh();
        $holder->forceDelete();

        // Without the type-preserving read, the cheap-skip WHERE compared
        // ancestor `price_max = 9` (truncated) against the stored 9.99 —
        // no match, no recompute, root.price_max stays stuck at 9.99 even
        // though the holder is gone.
        $this->assertSame('5.50', $root->refresh()->price_max);
    }

    public function test_delete_holder_with_decimal_price_recomputes_ancestor_min(): void
    {
        $root = new PricedArea(['name' => 'Root', 'price' => '10.00']);
        $root->saveAsRoot();

        $holder = new PricedArea(['name' => 'Holder', 'price' => '0.01']);
        $holder->appendToNode($root)->save();

        $other = new PricedArea(['name' => 'Other', 'price' => '5.50']);
        $other->appendToNode($root->refresh())->save();

        $this->assertSame('0.01', $root->refresh()->price_min);

        $holder->refresh();
        $holder->forceDelete();

        // Same truncation hazard at the low end: 0.01 → 0 collapses the
        // filter to a zero-equality that matches no ancestor.
        $this->assertSame('5.50', $root->refresh()->price_min);
    }

    public function test_move_decimal_min_holder_out_of_subtree_recomputes_old_ancestor(): void
    {
        $root = new PricedArea(['name' => 'Root', 'price' => '10.00']);
        $root->saveAsRoot();

        $oldParent = new PricedArea(['name' => 'OldParent', 'price' => '7.50']);
        $oldParent->appendToNode($root)->save();

        $holder = new PricedArea(['name' => 'Holder', 'price' => '0.99']);
        $holder->appendToNode($oldParent->refresh())->save();

        $sibling = new PricedArea(['name' => 'Sibling', 'price' => '4.25']);
        $sibling->appendToNode($oldParent->refresh())->save();

        $newParent = new PricedArea(['name' => 'NewParent', 'price' => '8.00']);
        $newParent->appendToNode($root->refresh())->save();

        $this->assertSame('0.99', $oldParent->refresh()->price_min);

        $holder->refresh();
        $holder->moveTo($newParent->refresh())->save();

        // Move path mirrors the delete path's cheap-skip read. Without the
        // type-preserving fix the old chain (oldParent → root) would not
        // see a matching ancestor and would keep 0.99 even though the
        // holder is now under newParent.
        $this->assertSame('4.25', $oldParent->refresh()->price_min);
        $this->assertSame('0.99', $newParent->refresh()->price_min);
    }
}
