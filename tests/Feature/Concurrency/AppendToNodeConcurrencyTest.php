<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Concurrency;

use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Parallel `appendToNode($parent)->save()` callers against the SAME
 * parent are the single most common production hotspot — every "create
 * child of node X" web request lands here. Two callers reading the
 * parent's rgt simultaneously and both inserting at that slot would
 * produce duplicate-lft / duplicate-rgt corruption.
 *
 * Companion to `MakeRootConcurrencyTest`. Where that file races
 * roots against the max-rgt lock, this one races children against a
 * single parent's gap-shift — both rely on the FOR UPDATE lock that
 * `actAppendTo` / `actPrependTo` / `actSibling` acquire on the
 * target row before reading its bounds.
 *
 * Skipped on SQLite (no row locking; in-memory DB doesn't cross fork).
 * Skipped when `pcntl_fork` is unavailable. Runs end-to-end on the CI
 * matrix's MySQL / MariaDB / PostgreSQL cells.
 */
final class AppendToNodeConcurrencyTest extends TestCase
{
    use ConcurrencyHarness;

    public function test_parallel_append_to_node_on_same_parent_produces_no_duplicate_bounds(): void
    {
        $this->requireForkableMultiWriterBackend();

        $parent = new Category(['name' => 'parent']);
        $parent->saveAsRoot();
        $parentId = (int) $parent->id;

        $workers = 5;
        $perWorker = 2;

        $exits = $this->runConcurrentWorkers($workers, function (int $worker) use ($parentId, $perWorker): void {
            for ($j = 0; $j < $perWorker; $j++) {
                $this->withDeadlockRetry(function () use ($worker, $j, $parentId): void {
                    /** @var Category $freshParent */
                    $freshParent = Category::query()->findOrFail($parentId);

                    $child = new Category(['name' => sprintf('w%d-%d', $worker, $j)]);
                    $child->appendToNode($freshParent)->save();
                }, maxAttempts: 16);
            }
        });

        $this->assertSame(
            array_fill(0, $workers, 0),
            $exits,
            'every worker must complete without error',
        );

        // 1 parent + workers*perWorker children = expected row count.
        $expected = 1 + $workers * $perWorker;
        $this->assertSame($expected, Category::query()->count());

        // Every node's lft and rgt must be unique across the tree —
        // a missing gap-shift lock would let two appenders land at the
        // same slot.
        $rows = Category::query()
            ->orderBy('lft')
            ->get(['id', 'lft', 'rgt', 'parent_id'])
            ->all();

        $lfts = array_map(static fn (Category $r): int => $r->lft, $rows);
        $rgts = array_map(static fn (Category $r): int => $r->rgt, $rows);

        $this->assertSame(count($lfts), count(array_unique($lfts)), 'duplicate lft values across the tree');
        $this->assertSame(count($rgts), count(array_unique($rgts)), 'duplicate rgt values across the tree');

        // Every child is a leaf (lft + 1 === rgt) and rooted under the
        // parent (parent_id matches). A torn gap-shift would leave one
        // child with a wider rgt than lft + 1 (descendants borrowed from
        // a sibling), or with a wrong parent_id.
        foreach ($rows as $r) {
            if ($r->id === $parent->id) {
                continue;
            }
            $this->assertSame($r->lft + 1, $r->rgt, "row {$r->id} is not a leaf as expected");
            $this->assertSame($parent->id, $r->parent_id, "row {$r->id} has wrong parent_id");
        }

        // Parent rgt must equal 2 + 2*children — every child contributes
        // a 2-slot range. Drift here indicates a partial gap-shift.
        $freshParent = Category::query()->findOrFail($parent->id);
        $this->assertSame(
            2 + 2 * ($workers * $perWorker),
            (int) $freshParent->rgt,
            'parent rgt did not absorb every child gap — partial gap-shift detected',
        );

        // Catch-all: countErrors() finds nothing the assertions above
        // missed (a different corruption mode would otherwise slip by).
        $this->assertFalse(Category::isBroken(), 'tree must be intact after concurrent appendToNode');
    }
}
