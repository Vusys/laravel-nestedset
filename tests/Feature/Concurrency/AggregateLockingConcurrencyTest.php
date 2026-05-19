<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Concurrency;

use Illuminate\Database\QueryException;
use Illuminate\Support\Sleep;
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

        // Root + 4 children all at tickets=100. Each worker lowers
        // its child's tickets to a unique value below 100, which is
        // the "lost-holder" direction for MAX — the child was a
        // max-holder, and after the update the stored MAX on the
        // root is potentially stale. The package responds by issuing
        // a RecomputeMaintenance pass against ancestors, taking the
        // FOR UPDATE lock that this test is verifying actually
        // serialises concurrent writers.
        $root = new Area(['name' => 'root', 'tickets' => 100]);
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

        // Post-update tickets across the subtree: {100 (root), 10, 20, 30, 40}.
        // MIN = 10 (a leaf), MAX = 100 (the root itself). If the lock
        // were missing, two concurrent recomputes could each compute
        // MAX from a partial view of the post-update children and
        // write a stale value (e.g. one worker sees 100 as max
        // because only its own child has been written, ignoring the
        // other worker's still-pending lower value).
        $this->assertSame(10, $root->tickets_min, 'root.tickets_min must equal the min over the post-update subtree');
        $this->assertSame(100, $root->tickets_max, 'root.tickets_max must equal the max over the post-update subtree');

        // Cross-check against freshly-computed values — anchors the
        // assertion against drift between stored and computed if the
        // constants above ever rotate.
        $this->assertAggregateMatchesFresh($root, 'tickets_min');
        $this->assertAggregateMatchesFresh($root, 'tickets_max');

        // SUM uses the delta path so it's lock-independent. Worth
        // pinning that it matches the freshly summed value after
        // concurrent writes too: 100 + 10 + 20 + 30 + 40 = 200.
        $this->assertSame(200, $root->tickets_total);
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

        $root = new Area(['name' => 'root', 'tickets' => 1]);
        $root->saveAsRoot();

        $childIds = [];
        for ($i = 0; $i < 4; $i++) {
            // Children start at low values so workers can raise them,
            // driving recompute in the MIN-lost-holder direction.
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

        // Post-update tickets: {1 (root), 50, 60, 70, 80}.
        // MIN = 1 (root); MAX = 80 (a leaf).
        $this->assertSame(1, $root->tickets_min);
        $this->assertSame(80, $root->tickets_max);

        $this->assertAggregateMatchesFresh($root, 'tickets_min');
        $this->assertAggregateMatchesFresh($root, 'tickets_max');
        $this->assertAggregatesAreIntact(Area::class);
    }

    /**
     * Wraps a closure with SQLSTATE 40001 (serialization failure /
     * deadlock victim) retry. Real callers under contended writes
     * need this; the test workers do too.
     *
     * @param  \Closure(): void  $fn
     */
    private function withDeadlockRetry(\Closure $fn, int $maxAttempts = 8): void
    {
        $attempt = 0;
        while (true) {
            try {
                $fn();

                return;
            } catch (QueryException $e) {
                $attempt++;
                if ($attempt >= $maxAttempts || ! $this->isDeadlockOrLockTimeout($e)) {
                    throw $e;
                }
                // Exponential backoff with jitter so the next attempt
                // doesn't land in the exact same instant as another
                // retrying worker.
                Sleep::usleep(1_000 * (2 ** $attempt) + random_int(0, 5_000));
            }
        }
    }

    private function isDeadlockOrLockTimeout(QueryException $e): bool
    {
        // SQLSTATE classes: 40001 = serialization failure (deadlock
        // victim) on every supported backend; 40P01 = PostgreSQL's
        // deadlock-detected; HY000 with driver code 1205 = MySQL's
        // lock-wait-timeout (not a true deadlock, but the recovery
        // shape is identical).
        $sqlState = (string) $e->getCode();
        if ($sqlState === '40001' || $sqlState === '40P01') {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'deadlock')
            || str_contains($message, 'lock wait timeout');
    }
}
