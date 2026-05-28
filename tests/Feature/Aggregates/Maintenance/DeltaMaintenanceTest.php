<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Maintenance;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Strategy\DeltaMaintenance;
use Vusys\NestedSet\Exceptions\UnplacedNodeException;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Phase D maintenance tests: SUM and COUNT aggregates stay in sync as
 * nodes are created, source columns updated, and nodes deleted. The
 * delta-based maintenance machinery in
 * {@see DeltaMaintenance} issues
 * exactly one extra `UPDATE` per Eloquent mutation — verified by query
 * counting in the dedicated tests below.
 *
 * MIN/MAX/AVG remain at their migration defaults across these tests;
 * those land in Phases E (AVG) and F (MIN/MAX).
 */
final class DeltaMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    private function asInt(mixed $value): int
    {
        if ($value === null || ! is_numeric($value)) {
            $this->fail('Expected numeric, got '.get_debug_type($value));
        }

        return (int) $value;
    }

    // ----------------------------------------------------------------
    // Insertion: stored aggregates initialise correctly
    // ----------------------------------------------------------------

    public function test_save_as_root_initialises_self_aggregates(): void
    {
        $root = (new Area(['name' => 'Root', 'tickets' => 100]));
        $root->saveAsRoot();
        $root->refresh();

        $this->assertSame(100, $this->asInt($root->tickets_total));
        $this->assertSame(1, $this->asInt($root->tickets_count_all));
    }

    public function test_append_propagates_sum_and_count_to_parent(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $root->refresh();
        $a->refresh();

        $this->assertSame(150, $this->asInt($root->tickets_total), 'root sum = self + child');
        $this->assertSame(2, $this->asInt($root->tickets_count_all), 'root count = self + child');

        $this->assertSame(50, $this->asInt($a->tickets_total), 'leaf sum = self');
        $this->assertSame(1, $this->asInt($a->tickets_count_all), 'leaf count = self');
    }

    public function test_motivating_example_tree_aggregates_correctly(): void
    {
        // Matches AGGREGATES.md §1: Root(100) > A(50) > A1(50); Root > B(25).
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 50]);
        $a1->appendToNode($a->refresh())->save();

        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();

        $root->refresh();
        $a->refresh();
        $a1->refresh();
        $b->refresh();

        $this->assertSame(225, $this->asInt($root->tickets_total));
        $this->assertSame(4, $this->asInt($root->tickets_count_all));

        $this->assertSame(100, $this->asInt($a->tickets_total));
        $this->assertSame(2, $this->asInt($a->tickets_count_all));

        $this->assertSame(50, $this->asInt($a1->tickets_total));
        $this->assertSame(1, $this->asInt($a1->tickets_count_all));

        $this->assertSame(25, $this->asInt($b->tickets_total));
        $this->assertSame(1, $this->asInt($b->tickets_count_all));
    }

    public function test_create_without_placement_throws_and_does_not_touch_other_rows(): void
    {
        // Build a tree first so we have rows that could be wrongly updated.
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $rootBefore = $this->asInt($root->refresh()->tickets_total);

        // Bare Area::create() without appendToNode/makeRoot would
        // otherwise write an invalid_bounds row (lft=rgt=0). The
        // saving guard rejects it.
        $this->expectException(UnplacedNodeException::class);

        try {
            Area::query()->create(['name' => 'Drifter', 'tickets' => 999]);
        } finally {
            // No drifter row landed, and ancestors stayed untouched.
            $this->assertNull(Area::query()->where('name', 'Drifter')->first());
            $this->assertSame(
                $rootBefore,
                $this->asInt($root->refresh()->tickets_total),
                'rejected save must not modify other rows',
            );
        }
    }

    // ----------------------------------------------------------------
    // Source-column update: delta propagates to self + ancestors
    // ----------------------------------------------------------------

    public function test_source_update_propagates_positive_delta_up_the_chain(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a->refresh();
        $a->tickets = 80; // +30
        $a->save();

        $root->refresh();
        $a->refresh();

        $this->assertSame(80, $this->asInt($a->tickets_total));
        $this->assertSame(180, $this->asInt($root->tickets_total)); // 100 + 80
    }

    public function test_source_update_propagates_negative_delta(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a->refresh();
        $a->tickets = 10; // -40
        $a->save();

        $root->refresh();
        $a->refresh();

        $this->assertSame(10, $this->asInt($a->tickets_total));
        $this->assertSame(110, $this->asInt($root->tickets_total)); // 100 + 10
    }

    public function test_source_update_does_not_change_count(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $rootCountBefore = $this->asInt($root->refresh()->tickets_count_all);

        $a->refresh();
        $a->tickets = 999;
        $a->save();

        $this->assertSame(
            $rootCountBefore,
            $this->asInt($root->refresh()->tickets_count_all),
            'count must not change when only the source value changes',
        );
    }

    public function test_save_with_no_source_change_does_not_touch_aggregates(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $rootBefore = $this->asInt($root->refresh()->tickets_total);

        // Update an unrelated attribute.
        $root->refresh();
        $root->name = 'Renamed';
        $root->save();

        $this->assertSame(
            $rootBefore,
            $this->asInt($root->refresh()->tickets_total),
            'changing a non-source attribute must not move aggregates',
        );
    }

    // ----------------------------------------------------------------
    // Deletion: ancestors lose the deleted subtree's contribution
    // ----------------------------------------------------------------

    public function test_force_delete_leaf_subtracts_from_ancestors(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $this->assertSame(150, $this->asInt($root->refresh()->tickets_total));
        $this->assertSame(2, $this->asInt($root->refresh()->tickets_count_all));

        $a->refresh();
        $a->forceDelete();

        $root->refresh();
        $this->assertSame(100, $this->asInt($root->tickets_total));
        $this->assertSame(1, $this->asInt($root->tickets_count_all));
    }

    // ----------------------------------------------------------------
    // Stored vs fresh: drift check across a batch of mixed operations
    // ----------------------------------------------------------------

    public function test_stored_aggregates_match_fresh_after_mixed_batch(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 30]);
        $a1->appendToNode($a->refresh())->save();

        // Mutate a few times.
        $a1->refresh();
        $a1->tickets = 40;
        $a1->save();

        $b->refresh();
        $b->forceDelete();

        // Walk every remaining node and verify stored == fresh for SUM and COUNT.
        foreach (Area::query()->orderBy('lft')->get() as $node) {
            $this->assertSame(
                $this->asInt($node->freshAggregate('tickets_total')),
                $this->asInt($node->tickets_total),
                "tickets_total drift on node {$node->id} ({$node->name})",
            );
            $this->assertSame(
                $this->asInt($node->freshAggregate('tickets_count_all')),
                $this->asInt($node->tickets_count_all),
                "tickets_count_all drift on node {$node->id} ({$node->name})",
            );
        }
    }

    // ----------------------------------------------------------------
    // Query-count assertions: exactly one extra UPDATE per mutation
    // ----------------------------------------------------------------

    public function test_appending_a_leaf_fires_exactly_one_extra_aggregate_update(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $extraUpdates = $this->countAggregateUpdates(function () use ($root): void {
            $a = new Area(['name' => 'A', 'tickets' => 50]);
            $a->appendToNode($root)->save();
        });

        $this->assertSame(1, $extraUpdates, 'one extra UPDATE expected for the aggregate cascade');
    }

    public function test_source_column_update_fires_exactly_one_extra_aggregate_update(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a->refresh();
        $extraUpdates = $this->countAggregateUpdates(function () use ($a): void {
            $a->tickets = 99;
            $a->save();
        });

        $this->assertSame(1, $extraUpdates);
    }

    public function test_force_delete_fires_exactly_one_extra_aggregate_update(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();
        $a->refresh();

        $extraUpdates = $this->countAggregateUpdates(function () use ($a): void {
            $a->forceDelete();
        });

        $this->assertSame(1, $extraUpdates);
    }

    public function test_save_with_no_source_change_fires_no_extra_aggregate_update(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();
        $root->refresh();

        $extraUpdates = $this->countAggregateUpdates(function () use ($root): void {
            $root->name = 'Renamed';
            $root->save();
        });

        $this->assertSame(0, $extraUpdates, 'no source change should mean no aggregate cascade');
    }

    /**
     * Counts UPDATE statements issued against the `areas` table that
     * touch an aggregate column, excluding the tree-structural and
     * source-column UPDATEs the package would issue anyway.
     */
    private function countAggregateUpdates(callable $operation): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $operation();
        } finally {
            DB::disableQueryLog();
        }

        $count = 0;

        foreach (DB::getQueryLog() as $entry) {
            $lower = strtolower((string) $entry['query']);
            if (! str_starts_with($lower, 'update')) {
                continue;
            }
            if (! str_contains($lower, 'areas')) {
                continue;
            }

            // Heuristic: aggregate-maintenance UPDATEs reference one of the
            // declared aggregate columns. The tree-structural and
            // source-column UPDATEs do not.
            if (str_contains($lower, 'tickets_total') || str_contains($lower, 'tickets_count_all')) {
                $count++;
            }
        }

        return $count;
    }
}
