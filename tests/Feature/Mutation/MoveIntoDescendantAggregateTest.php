<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Mutation;

use Illuminate\Support\Facades\Config;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Moving a node into its own descendant must be rejected BEFORE the
 * before-move aggregate hook runs. The structural backstop in
 * moveNode() throws, but only after the hook has already subtracted the
 * subtree's contribution from the old ancestor chain — and with
 * auto_transaction disabled (no wrapping transaction) that subtraction
 * is not rolled back, permanently drifting aggregates on a move that
 * never structurally happened.
 */
final class MoveIntoDescendantAggregateTest extends TestCase
{
    #[Test]
    public function rejected_cycle_move_leaves_aggregates_intact_without_auto_transaction(): void
    {
        Config::set('nestedset.auto_transaction', false);

        // root > A > AA, with tickets so the ancestor rollups are non-zero.
        $root = new Area(['name' => 'root', 'tickets' => 1]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 10]);
        $a->appendToNode($root->refresh())->save();

        $aa = new Area(['name' => 'AA', 'tickets' => 100]);
        $aa->appendToNode($a->refresh())->save();

        $a = $a->refresh();

        // Attempt the cycle: nest A inside its descendant AA.
        try {
            $a->appendToNode($aa->refresh())->save();
            $this->fail('expected a LogicException for the cycle move');
        } catch (LogicException $e) {
            $this->assertMatchesRegularExpression('/Cannot move node into itself/', $e->getMessage());
        }

        // The structure never changed and — crucially — no aggregate
        // contribution was subtracted and left dangling.
        $this->assertFalse(Area::aggregatesAreBroken());
        $this->assertSame(0, array_sum(Area::countErrors()));
        $this->assertSame(111, (int) $root->refresh()->tickets_total, 'root = 1 + 10 + 100');
    }
}
