<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Concurrency;

use Vusys\NestedSet\Aggregates\Strategy\RecomputeMaintenance;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * `aggregate_locking = 'auto'` (and `'always'`) must serialise
 * concurrent aggregate updates against the same ancestor so the
 * stored MIN / MAX values converge to the freshly computed value.
 * Without the lock, two recompute paths can both SELECT the
 * candidate values, compute a new aggregate from a stale view, and
 * write — last-write-wins, with the loser's drift baked into
 * storage.
 *
 * MIN / MAX go through {@see RecomputeMaintenance}
 * when the change is in the "lost-holder" direction — lowering a node's
 * `tickets` when it was the MAX holder, or raising it when it was the
 * MIN holder. That's the path the lock guards. The test workload
 * deliberately drives every worker into that direction so every save
 * issues a `FOR UPDATE`-locked ancestor SELECT.
 *
 * **Deadlock retry is part of the contract.** When N workers each
 * update a different child, every save needs to lock the shared
 * ancestor chain. MySQL/MariaDB's InnoDB and PostgreSQL both detect
 * lock cycles and abort one transaction with SQLSTATE 40001 — that's
 * how row-level locking works, not a package bug. Real callers must
 * retry on `40001`; these workers do the same. The package's
 * auto-transaction passes attempts=1 today; if that grows, the
 * worker-level retry becomes redundant but harmless.
 *
 * Skipped on SQLite (single writer) and where `pcntl_fork` is missing.
 */
final class AggregateLockingConcurrencyTest extends TestCase
{
    use ConcurrencyHarness;
    use InteractsWithTrees;

    public function test_recompute_min_max_under_auto_locking_converges_to_fresh_values(): void
    {
        $this->requireForkableMultiWriterBackend();

        config(['nestedset.aggregate_locking' => 'auto']);

        // Root at tickets=25, children all at tickets=100. Each
        // worker lowers its child to a unique value in {10..40} —
        // the "lost-holder" direction for MAX, since every child
        // was a max-holder and after the update no child is. The
        // post-update MAX (40) lives on a *leaf*, not the root, so
        // a stale recompute that misses any in-flight child would
        // compute MAX=100 (from a not-yet-updated child) and fail
        // the assertion. Same shape for MIN: a stale read of a
        // pre-update child sees 100, post-update MIN = 10 (a leaf).
        $root = new Area(['name' => 'root', 'tickets' => 25]);
        $root->saveAsRoot();

        $childIds = [];
        for ($i = 0; $i < 4; $i++) {
            $child = new Area(['name' => "c{$i}", 'tickets' => 100]);
            $child->appendToNode($root->refresh())->save();
            $childIds[] = (int) $child->refresh()->id;
        }

        $newValues = [10, 20, 30, 40];

        $exits = $this->runConcurrentWorkers(4, function (int $i) use ($childIds, $newValues): void {
            config(['nestedset.aggregate_locking' => 'auto']);

            $this->withDeadlockRetry(function () use ($childIds, $newValues, $i): void {
                $child = Area::query()->findOrFail($childIds[$i]);
                $child->tickets = $newValues[$i];
                $child->save();
            });
        });

        $this->assertSame(
            array_fill(0, 4, 0),
            $exits,
            'every worker must complete (with retry) without surfacing an error',
        );

        $root = $root->refresh();

        // Post-update tickets across the subtree: {25 (root), 10, 20, 30, 40}.
        // MIN = 10 (a leaf), MAX = 40 (a leaf). Both extrema move
        // to leaves — a stale recompute that read any pre-update
        // child would see tickets=100 and compute MAX=100, drift
        // that this assertion catches. The MIN check is symmetric:
        // a pre-update child reads as 100, post-update MIN=10.
        $this->assertSame(10, $root->tickets_min, 'root.tickets_min must equal the min over the post-update subtree');
        $this->assertSame(40, $root->tickets_max, 'root.tickets_max must equal the max over the post-update subtree');

        // Cross-check against freshly-computed values — anchors the
        // assertion against drift between stored and computed if the
        // constants above ever rotate.
        $this->assertAggregateMatchesFresh($root, 'tickets_min');
        $this->assertAggregateMatchesFresh($root, 'tickets_max');

        // SUM uses the delta path so it's lock-independent. Worth
        // pinning that it matches the freshly summed value after
        // concurrent writes too: 25 + 10 + 20 + 30 + 40 = 125.
        $this->assertSame(125, $root->tickets_total);
        $this->assertAggregateMatchesFresh($root, 'tickets_total');

        $this->assertAggregatesAreIntact(Area::class);
    }

    public function test_recompute_under_always_locking_converges_to_fresh_values(): void
    {
        // `'always'` locks even where `'auto'` would short-circuit.
        // Same end-state contract as `'auto'`; pinning both prevents
        // a future change to the auto heuristic from quietly diverging.
        $this->requireForkableMultiWriterBackend();

        config(['nestedset.aggregate_locking' => 'always']);

        // Root at tickets=65, children all at tickets=1. Workers raise
        // children to {50..80} — the "lost-holder" direction for MIN.
        // The root's 65 lands neither at MIN nor MAX of the post-update
        // set, so both extrema move to leaves: MIN=50, MAX=80. A stale
        // recompute that sees any pre-update child (tickets=1) would
        // compute MIN=1, drift that this assertion catches.
        $root = new Area(['name' => 'root', 'tickets' => 65]);
        $root->saveAsRoot();

        $childIds = [];
        for ($i = 0; $i < 4; $i++) {
            $child = new Area(['name' => "c{$i}", 'tickets' => 1]);
            $child->appendToNode($root->refresh())->save();
            $childIds[] = (int) $child->refresh()->id;
        }

        $newValues = [50, 60, 70, 80];

        $exits = $this->runConcurrentWorkers(4, function (int $i) use ($childIds, $newValues): void {
            config(['nestedset.aggregate_locking' => 'always']);

            $this->withDeadlockRetry(function () use ($childIds, $newValues, $i): void {
                $child = Area::query()->findOrFail($childIds[$i]);
                $child->tickets = $newValues[$i];
                $child->save();
            });
        });

        $this->assertSame(array_fill(0, 4, 0), $exits);

        $root = $root->refresh();

        // Post-update tickets: {65 (root), 50, 60, 70, 80}.
        // MIN = 50 (leaf), MAX = 80 (leaf) — root is not an extremum.
        $this->assertSame(50, $root->tickets_min);
        $this->assertSame(80, $root->tickets_max);

        $this->assertAggregateMatchesFresh($root, 'tickets_min');
        $this->assertAggregateMatchesFresh($root, 'tickets_max');
        $this->assertAggregatesAreIntact(Area::class);
    }
}
