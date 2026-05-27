<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Phase R: `Model::withDeferredAggregateMaintenance(Closure $work, ?HasNestedSet $anchor = null)`.
 *
 * Suspends the trait's per-row aggregate-maintenance hooks for the
 * duration of `$work`, then fires one `fixAggregates($anchor)` at the
 * outermost exit. Eloquent events still fire per row — only the
 * aggregate-column side effects defer.
 */
final class DeferredAggregateMaintenanceTest extends TestCase
{
    public function test_aggregates_repaired_at_end_of_closure(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Area::withDeferredAggregateMaintenance(function () use ($root): void {
            foreach ([3, 5, 7] as $i => $tickets) {
                $node = new Area(['name' => "n{$i}", 'tickets' => $tickets]);
                $node->appendToNode($root)->save();
            }
        });

        $root = $root->refresh();
        $this->assertSame(15, (int) $root->tickets_total, 'subtree totals 3+5+7=15 are present after the closing fix');
        $this->assertSame(4, (int) $root->tickets_count_all, 'root + 3 children');
        $this->assertFalse(Area::aggregatesAreBroken());
    }

    public function test_eloquent_events_still_fire_per_row(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $created = [];
        Event::listen('eloquent.created: '.Area::class, function (Area $node) use (&$created): void {
            $created[] = $node->name;
        });

        Area::withDeferredAggregateMaintenance(function () use ($root): void {
            foreach (['a', 'b', 'c'] as $name) {
                $node = new Area(['name' => $name, 'tickets' => 1]);
                $node->appendToNode($root)->save();
            }
        });

        $this->assertSame(['a', 'b', 'c'], $created, 'created event fired for every save inside the closure');
    }

    public function test_inside_closure_no_per_row_aggregate_updates_happen(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        // Re-read root inside the closure to confirm its stored
        // tickets_total stays at 0 — proving the per-row delta path
        // did *not* run during the saves.
        $totalsObservedInside = [];
        Area::withDeferredAggregateMaintenance(function () use ($root, &$totalsObservedInside): void {
            foreach ([10, 20] as $tickets) {
                $node = new Area(['name' => 'x', 'tickets' => $tickets]);
                $node->appendToNode($root)->save();

                $raw = DB::table('areas')
                    ->where('id', $root->id)
                    ->value('tickets_total');
                $totalsObservedInside[] = is_numeric($raw) ? (int) $raw : 0;
            }
        });

        $this->assertSame([0, 0], $totalsObservedInside,
            "root's tickets_total stays 0 throughout the closure — repair runs only after");

        $this->assertSame(30, (int) $root->refresh()->tickets_total,
            'post-closure fix totals 10+20 onto root');
    }

    public function test_nested_calls_only_repair_at_outermost_exit(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        // Snapshot stored aggregate at outer-mid points.
        $observedBetween = null;
        Area::withDeferredAggregateMaintenance(function () use ($root, &$observedBetween): void {
            (new Area(['name' => 'a', 'tickets' => 1]))->appendToNode($root)->save();

            // Inner closure exits — but we're still inside the outer.
            Area::withDeferredAggregateMaintenance(function () use ($root): void {
                (new Area(['name' => 'b', 'tickets' => 2]))->appendToNode($root)->save();
            });

            // Inner ended; outer is still deferred. No repair should
            // have fired yet — root's stored total is still 0.
            $rawBetween = DB::table('areas')
                ->where('id', $root->id)
                ->value('tickets_total');
            $observedBetween = is_numeric($rawBetween) ? (int) $rawBetween : -1;
        });

        $this->assertSame(0, $observedBetween, 'inner exit did not trigger repair — outer still defers');
        $this->assertSame(3, (int) $root->refresh()->tickets_total, 'outer exit repaired everything (1+2=3)');
    }

    public function test_exception_inside_closure_still_runs_repair_and_propagates(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $caught = null;
        // Built via a factory method so PHPStan doesn't infer the
        // closure as statically `never` (which would make the
        // surrounding try/catch and post-block assertions look like
        // dead code). The exception still propagates.
        try {
            Area::withDeferredAggregateMaintenance($this->makeBoomClosure($root));
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'original exception propagates');
        $this->assertSame('boom', $caught->getMessage());

        $root = $root->refresh();
        $this->assertSame(7, (int) $root->tickets_total,
            'the committed save inside the closure had its aggregate repaired by the finally-fire');
        $this->assertFalse(Area::aggregatesAreBroken(), 'repair ran on exception path');
    }

    public function test_closure_return_value_is_returned(): void
    {
        $value = Area::withDeferredAggregateMaintenance(fn (): string => 'hello');

        $this->assertSame('hello', $value);
    }

    public function test_anchor_scopes_the_repair(): void
    {
        $a = new Area(['name' => 'tree-a', 'tickets' => 0]);
        $a->saveAsRoot();
        $a = $a->refresh();

        $b = new Area(['name' => 'tree-b', 'tickets' => 0]);
        $b->saveAsRoot();
        $b = $b->refresh();

        // Pre-drift tree-b's aggregate so we can prove the anchored
        // repair leaves it alone.
        DB::table('areas')->where('id', $b->id)->update(['tickets_total' => 999]);

        Area::withDeferredAggregateMaintenance(function () use ($a): void {
            (new Area(['name' => 'child', 'tickets' => 5]))->appendToNode($a)->save();
        }, $a);

        $this->assertSame(5, (int) $a->refresh()->tickets_total, 'tree-a repaired');
        $this->assertSame(999, (int) $b->refresh()->tickets_total,
            'tree-b unchanged — anchor scoped the post-closure fixAggregates');
    }

    public function test_rollback_of_outer_transaction_also_rolls_back_closing_fix(): void
    {
        // Both the per-row saves and the closing fixAggregates run
        // inside the outer DB::transaction. A throw past the deferred
        // closure unwinds the outer transaction, so the saves and
        // the repair UPDATE both vanish — the table ends at its
        // pre-transaction state, not a half-repaired one.
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $rootIdBefore = $root->id;
        $totalBefore = (int) $root->tickets_total;

        try {
            DB::transaction(function () use ($root): never {
                Area::withDeferredAggregateMaintenance(function () use ($root): void {
                    foreach ([3, 5, 7] as $i => $tickets) {
                        (new Area(['name' => "n{$i}", 'tickets' => $tickets]))
                            ->appendToNode($root)
                            ->save();
                    }
                });

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $caught) {
            $this->assertSame('rollback', $caught->getMessage());
        }

        $root = $root->refresh();
        $this->assertSame($rootIdBefore, (int) $root->id, 'precondition: root still exists');
        $this->assertSame($totalBefore, (int) $root->tickets_total,
            'outer rollback reverted both the saves and the closing fix — tree is back at pre-transaction state',
        );
        $this->assertSame(1, Area::query()->count(),
            'all saves committed inside the rolled-back transaction were unwound',
        );
        $this->assertFalse(Area::aggregatesAreBroken(),
            'pre-existing state was consistent and stays consistent post-rollback',
        );
    }

    public function test_fix_aggregates_is_idempotent_after_partial_commit_then_rollback(): void
    {
        // Variant: the deferred closure commits some saves to the
        // DB conceptually, but the outer rollback unwinds both the
        // saves and the closing fix. Re-running fixAggregates after
        // the rollback must leave the tree in a correct, non-drifted
        // state regardless of how the writes were unwound — a sanity
        // check that the package's repair is genuinely idempotent on
        // arbitrary post-rollback states.
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        (new Area(['name' => 'pre', 'tickets' => 4]))->appendToNode($root)->save();
        $root = $root->refresh();
        $totalAfterSeed = (int) $root->tickets_total;

        try {
            DB::transaction(function () use ($root): never {
                Area::withDeferredAggregateMaintenance(function () use ($root): void {
                    (new Area(['name' => 'in_tx', 'tickets' => 9]))->appendToNode($root)->save();
                });
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // swallow
        }

        Area::fixAggregates();

        $this->assertSame($totalAfterSeed, (int) $root->refresh()->tickets_total,
            'post-rollback fix is idempotent — total reflects the pre-transaction subtree',
        );
        $this->assertFalse(Area::aggregatesAreBroken());
    }

    public function test_static_counter_is_not_leaked_after_closure(): void
    {
        // Run two unrelated closures in sequence; the second must see
        // a fresh non-deferred state (per-row maintenance fires).
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Area::withDeferredAggregateMaintenance(function () use ($root): void {
            (new Area(['name' => 'a', 'tickets' => 1]))->appendToNode($root)->save();
        });

        // After the closure: a normal save should immediately update
        // root's aggregate without needing fixAggregates.
        (new Area(['name' => 'b', 'tickets' => 100]))->appendToNode($root->refresh())->save();

        $this->assertSame(101, (int) $root->refresh()->tickets_total,
            'second save (outside any closure) used the per-row delta path — counter reset cleanly',
        );
    }

    public function test_deferred_block_inside_db_transaction_recovers_after_outer_rollback(): void
    {
        // The wrapping fixAggregates fires at the end of the deferred
        // closure but is *still inside* the surrounding DB::transaction.
        // If the outer transaction rolls back, both the per-row saves
        // and the post-closure repair are reverted together. A fresh
        // fixAggregates call afterwards must be a clean no-op (the
        // stored aggregates are unchanged), and the static depth
        // counter must have reset across the rollback so subsequent
        // saves take the per-row path.
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $storedBefore = (int) $root->tickets_total;

        try {
            DB::transaction(static function () use ($root): never {
                Area::withDeferredAggregateMaintenance(function () use ($root): void {
                    foreach ([10, 20, 30] as $tickets) {
                        (new Area(['name' => "x{$tickets}", 'tickets' => $tickets]))
                            ->appendToNode($root)->save();
                    }
                }, $root);

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        // Rolled back — no rows added, no aggregate write committed.
        $this->assertSame(1, Area::query()->count(), 'outer rollback dropped the deferred-block inserts');
        $this->assertSame($storedBefore, (int) $root->refresh()->tickets_total,
            'root aggregate restored by the rollback',
        );

        // Idempotency: running fixAggregates now must be a clean repair
        // that converges immediately (nothing to fix).
        $this->assertFalse(Area::aggregatesAreBroken());

        // Counter reset: subsequent save uses the per-row path.
        (new Area(['name' => 'after', 'tickets' => 7]))->appendToNode($root->refresh())->save();
        $this->assertSame(7, (int) $root->refresh()->tickets_total,
            'post-rollback save uses per-row delta path — deferred-depth counter reset',
        );
    }

    public function test_bulk_insert_tree_inside_outer_deferred_defers_to_outer_frame(): void
    {
        // bulkInsertTree wraps its own work in withDeferredAggregateMaintenance
        // internally. When the user nests it inside their own outer
        // deferred block, the inner frame must be a no-op — the closing
        // fixAggregates fires only at the outer exit. Observable signal:
        // mid-closure (after bulkInsertTree returns), the root's stored
        // tickets_total should still be 0 (pre-closure value) because
        // no per-row maintenance ran and the inner-frame fixAggregates
        // was skipped.
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $totalAfterBulkInsideOuter = null;
        Area::withDeferredAggregateMaintenance(function () use ($root, &$totalAfterBulkInsideOuter): void {
            Area::bulkInsertTree([
                ['name' => 'a', 'tickets' => 3, 'children' => [
                    ['name' => 'a1', 'tickets' => 5],
                    ['name' => 'a2', 'tickets' => 7],
                ]],
                ['name' => 'b', 'tickets' => 11],
            ], appendTo: $root);

            $rawTotal = DB::table('areas')
                ->where('id', $root->id)
                ->value('tickets_total');
            $totalAfterBulkInsideOuter = is_numeric($rawTotal) ? (int) $rawTotal : -1;
        });

        // bulkInsertTree's internal fixAggregates was skipped (outer
        // counter non-zero on entry to its inner frame); only the
        // outer-frame fixAggregates wrote the final total. So at the
        // post-bulk observation point, the stored total is still the
        // pre-closure value.
        $this->assertSame(
            0,
            $totalAfterBulkInsideOuter,
            'inner bulkInsertTree fixAggregates fired despite outer defer — re-entrant counter is leaking',
        );

        // Outer exit ran one fixAggregates that captured every inserted
        // row's tickets contribution: 3 + 5 + 7 + 11 = 26.
        $this->assertSame(
            26,
            (int) $root->refresh()->tickets_total,
            'post-closure totals all bulkInsertTree contributions',
        );
        $this->assertFalse(Area::aggregatesAreBroken(), 'final state is clean');
    }

    public function test_bulk_insert_tree_outside_outer_deferred_repairs_eagerly(): void
    {
        // Inverse of the test above — when bulkInsertTree is called
        // OUTSIDE any outer defer, its own internal defer is the
        // outermost frame, so fixAggregates fires immediately at the
        // bulkInsertTree exit. Pinning the symmetry confirms the
        // re-entrant counter behaviour isn't just "always skip".
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Area::bulkInsertTree([
            ['name' => 'a', 'tickets' => 4, 'children' => [
                ['name' => 'a1', 'tickets' => 6],
            ]],
        ], appendTo: $root);

        // Immediately after bulkInsertTree returns: aggregates are
        // already current (no outer defer was holding them back).
        $this->assertSame(10, (int) $root->refresh()->tickets_total);
    }

    /**
     * Factory for a closure that throws RuntimeException after a side
     * effect. Returning the closure from a method declared
     * `\Closure(): void` gives the call site a non-`never` parameter
     * type, so the surrounding try/catch isn't considered dead code.
     *
     * @return \Closure(): void
     */
    private function makeBoomClosure(Area $root): \Closure
    {
        return function () use ($root): never {
            (new Area(['name' => 'committed', 'tickets' => 7]))->appendToNode($root)->save();
            throw new \RuntimeException('boom');
        };
    }
}
