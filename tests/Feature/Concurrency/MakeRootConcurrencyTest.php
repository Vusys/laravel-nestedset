<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Concurrency;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Parallel `makeRoot()` callers must serialise on the max-rgt row
 * lock. Without it, two callers can read the same max-rgt and both
 * insert at the same `lft`/`rgt` slot — duplicate-lft / duplicate-rgt
 * corruption.
 *
 * `MutationSemanticsTest::test_make_root_locks_max_rgt_read_against_concurrent_writers`
 * pins that the SQL emitted by `makeRoot()` includes a `FOR UPDATE` clause
 * — but a "lock clause present" assertion doesn't prove the lock actually
 * works under contention. This file does that proof: it forks N worker
 * processes that all race to `saveAsRoot()`, then verifies the post-state
 * has unique `(lft, rgt)` pairs across every row.
 *
 * Skipped on SQLite (no row locking; in-memory DB doesn't cross fork).
 * Skipped when `pcntl_fork` is unavailable. Runs end-to-end on the CI
 * matrix's MySQL / MariaDB / PostgreSQL cells.
 */
final class MakeRootConcurrencyTest extends TestCase
{
    use ConcurrencyHarness;

    public function test_parallel_make_root_callers_do_not_produce_duplicate_lft_or_rgt(): void
    {
        $this->requireForkableMultiWriterBackend();

        // Seed one root so workers race against an existing row,
        // mirroring real production state where a forest already
        // exists.
        $seed = new Category(['name' => 'seed']);
        $seed->saveAsRoot();

        // Workers × iterations chosen to keep contention high enough
        // to exercise the lock but low enough that the deadlock-retry
        // budget covers the unluckiest worker. 8×3 over-stressed PG
        // (≥30 deadlocks across the run; the most-collided worker
        // exhausted 8 retries); 5×2 produces real parallelism with
        // headroom. `maxAttempts: 16` gives every worker the tail
        // budget to ride out a deadlock storm.
        $workers = 5;
        $perWorker = 2;

        $exits = $this->runConcurrentWorkers($workers, function (int $worker) use ($perWorker): void {
            for ($j = 0; $j < $perWorker; $j++) {
                // Even with the FOR-UPDATE lock on max-rgt, the
                // subsequent `makeGap` UPDATE can produce row-lock
                // cycles between workers on PostgreSQL / MySQL —
                // both backends detect the deadlock and abort one
                // transaction. Real callers handle 40001 / 40P01 by
                // retrying; this worker does the same.
                $this->withDeadlockRetry(function () use ($worker, $j): void {
                    $node = new Category(['name' => sprintf('w%d-%d', $worker, $j)]);
                    $node->saveAsRoot();
                }, maxAttempts: 16);
            }
        });

        $this->assertSame(
            array_fill(0, $workers, 0),
            $exits,
            'every worker must complete without error',
        );

        $expected = 1 + $workers * $perWorker;
        $this->assertSame($expected, Category::query()->count());

        // Every root's (lft, rgt) must be unique. Without the lock,
        // two workers reading the same max-rgt simultaneously would
        // both insert at the same lft/rgt and trip these uniqueness
        // checks (or trip a backend unique-index violation first).
        $rows = Category::query()
            ->orderBy('lft')
            ->get(['lft', 'rgt'])
            ->all();

        $lfts = array_map(static fn (Category $r): int => $r->lft, $rows);
        $rgts = array_map(static fn (Category $r): int => $r->rgt, $rows);

        $this->assertSame(count($lfts), count(array_unique($lfts)), 'duplicate lft values found across roots');
        $this->assertSame(count($rgts), count(array_unique($rgts)), 'duplicate rgt values found across roots');

        // Roots in a forest are leaves (lft+1 == rgt) — every worker
        // saved a fresh root with no children.
        foreach ($rows as $r) {
            $this->assertSame($r->lft + 1, $r->rgt, 'each root should occupy a 2-slot range');
        }

        // Bounds form a contiguous 1..2N permutation per scope. If
        // any worker double-booked a slot, fixTree's countErrors()
        // would surface either invalid_bounds or duplicate_lft/_rgt.
        $this->assertFalse(Category::isBroken(), 'tree must remain intact after concurrent makeRoot');
    }

    public function test_parallel_make_root_in_a_scope_does_not_leak_into_sibling_scope(): void
    {
        // Scope-isolation cousin of the test above. Two scopes' roots
        // each restart `lft` / `rgt` at 1, so a missing scope filter
        // on the max-rgt lookup would either (a) place scope-A's new
        // root past scope-B's bounds (gap), or (b) duplicate-lft
        // collide with scope-B. The structural lock must include the
        // scope predicate.
        $this->requireForkableMultiWriterBackend();

        $menuA = Menu::create(['name' => 'A']);
        $menuB = Menu::create(['name' => 'B']);

        $seedA = new MenuItem(['name' => 'A-seed', 'menu_id' => $menuA->id]);
        $seedA->saveAsRoot();
        $seedB = new MenuItem(['name' => 'B-seed', 'menu_id' => $menuB->id]);
        $seedB->saveAsRoot();

        $workers = 6;

        $exits = $this->runConcurrentWorkers($workers, function (int $worker) use ($menuA, $menuB): void {
            // Even workers hit menu A; odd workers hit menu B.
            $menuId = $worker % 2 === 0 ? $menuA->id : $menuB->id;
            $this->withDeadlockRetry(function () use ($worker, $menuId): void {
                $node = new MenuItem(['name' => sprintf('w%d', $worker), 'menu_id' => $menuId]);
                $node->saveAsRoot();
            }, maxAttempts: 16);
        });

        $this->assertSame(array_fill(0, $workers, 0), $exits);

        foreach ([$menuA, $menuB] as $menu) {
            $anchor = MenuItem::query()->where('menu_id', $menu->id)->first();
            $this->assertNotNull($anchor);

            $this->assertFalse(
                MenuItem::isBroken($anchor),
                "menu {$menu->id} tree is broken after concurrent makeRoot",
            );

            /** @var list<int> $lfts */
            $lfts = MenuItem::query()
                ->where('menu_id', $menu->id)
                ->get(['lft'])
                ->map(static fn (MenuItem $m): int => $m->lft)
                ->all();

            $this->assertSame(
                count($lfts),
                count(array_unique($lfts, SORT_NUMERIC)),
                "duplicate lft within menu {$menu->id}",
            );
        }
    }
}
