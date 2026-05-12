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
