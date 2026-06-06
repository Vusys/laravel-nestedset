<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Maintenance;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\BitwiseArea;
use Vusys\NestedSet\Tests\Fixtures\Models\Branch;
use Vusys\NestedSet\Tests\Fixtures\Models\UuidTag;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The non-soft-delete branch of `applyAggregateOnRestore()` routes each
 * declared aggregate by kind — exclusive and raw-filter defs to chain
 * recompute, bitwise to chain recompute, inclusive SUM/COUNT to a delta,
 * MIN/MAX to the extreme path, and listener defs through their own
 * classification. The existing direct-call test only exercises Area
 * (inclusive SUM/COUNT/AVG/MIN/MAX), leaving the exclusive / raw-filter /
 * bitwise / listener arms uncovered.
 *
 * Calling delete-then-restore directly (the row is never actually
 * removed) composes to identity: delta defs subtract then re-add;
 * chain-recompute defs recompute the live tree both times. So every
 * aggregate must read back exactly as before, and the tree stays
 * consistent — while the routing branches all execute.
 */
final class RestoreHandlerMaintenanceTest extends TestCase
{
    #[Test]
    public function branch_restore_handler_routes_exclusive_and_raw_filter_defs(): void
    {
        $root = new Branch(['name' => 'root', 'tickets' => 10, 'active' => 1]);
        $root->saveAsRoot();

        $a = new Branch(['name' => 'a', 'tickets' => 5, 'active' => 1]);
        $a->appendToNode($root->refresh())->save();

        $a1 = new Branch(['name' => 'a1', 'tickets' => 3, 'active' => 0]);
        $a1->appendToNode($a->refresh())->save();

        $columns = [
            'tickets_total', 'descendants_total', 'descendants_count', 'descendants_max',
            'active_tickets_total', 'active_count', 'active_min_tickets', 'active_max_tickets',
        ];
        $before = $root->refresh()->only($columns);

        $a->refresh()->applyAggregateOnDelete();
        $a->refresh()->applyAggregateOnRestore();

        $this->assertEquals($before, $root->refresh()->only($columns), 'all Branch aggregates round-trip');
        $this->assertFalse(Branch::aggregatesAreBroken(), 'tree stays consistent after delete+restore');
    }

    #[Test]
    public function bitwise_restore_handler_routes_through_chain_recompute(): void
    {
        $root = new BitwiseArea(['name' => 'root', 'feature_bits' => 0b0001]);
        $root->saveAsRoot();

        $a = new BitwiseArea(['name' => 'a', 'feature_bits' => 0b0010]);
        $a->appendToNode($root->refresh())->save();

        $columns = ['features_or', 'features_and', 'features_xor'];
        $before = $root->refresh()->only($columns);

        $a->refresh()->applyAggregateOnDelete();
        $a->refresh()->applyAggregateOnRestore();

        $this->assertEquals($before, $root->refresh()->only($columns), 'bitwise aggregates round-trip');
        $this->assertFalse(BitwiseArea::aggregatesAreBroken());
    }

    #[Test]
    public function listener_restore_handler_routes_inclusive_sum_delta(): void
    {
        $root = new UuidTag(['name' => 'root', 'tickets' => 10]);
        $root->saveAsRoot();

        $a = new UuidTag(['name' => 'child-a', 'tickets' => 5]);
        $a->appendToNode($root->refresh())->save();

        // tickets_total (SQL Sum) + name_length_total (listener Sum).
        $columns = ['tickets_total', 'name_length_total'];
        $before = $root->refresh()->only($columns);

        $a->refresh()->applyAggregateOnDelete();
        $a->refresh()->applyAggregateOnRestore();

        $this->assertEquals($before, $root->refresh()->only($columns), 'SQL + listener aggregates round-trip');
        $this->assertFalse(UuidTag::aggregatesAreBroken());
    }
}
