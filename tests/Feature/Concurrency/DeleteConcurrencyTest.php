<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Concurrency;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Parallel deletes under a shared parent race each other's gap-shift. When
 * worker A soft/hard-deletes child[i], the closeGap shifts every later
 * sibling's lft/rgt — including the child[j] that worker B is mid-delete
 * on. The `deleting` hook re-reads child[j]'s structural columns before
 * running the cascade + closeGap; without a FOR UPDATE lock on that
 * re-read, B can read stale bounds (from before A's shift) and then close
 * the wrong gap, producing overlapping bounds, orphans, or duplicate
 * slots — structural corruption `isBroken()` detects.
 *
 * The invariant asserted is *validity* (proper nesting), not contiguity:
 * the nested-set model tolerates gaps in the lft/rgt sequence, and racing
 * closeGaps can legally leave a vacated slot unreclaimed (closing every
 * gap would additionally require serialising the unbounded shift UPDATEs).
 * What must never happen is two bounds overlapping or a row stranded
 * outside its parent.
 *
 * Companion to {@see AppendToNodeConcurrencyTest} (which races makeGap on a
 * shared parent); this one races closeGap. Skipped on SQLite (no row
 * locking, in-memory DB doesn't cross fork) and when pcntl_fork is absent.
 * Runs on the CI matrix's MySQL / MariaDB / PostgreSQL cells.
 */
final class DeleteConcurrencyTest extends TestCase
{
    use ConcurrencyHarness;

    #[Test]
    public function parallel_deletes_under_one_parent_keep_the_bounds_sequence_intact(): void
    {
        $this->requireForkableMultiWriterBackend();

        $parent = new Category(['name' => 'parent']);
        $parent->saveAsRoot();
        $parentId = (int) $parent->id;

        // Two children per worker: worker i deletes the first of its pair
        // and leaves the second. The survivors' bounds must stay a
        // contiguous permutation, which a torn closeGap would break.
        $workers = 5;
        $survivorIds = [];
        $victimIds = [];
        for ($i = 0; $i < $workers; $i++) {
            $victim = new Category(['name' => "victim-$i"]);
            $victim->appendToNode(Category::query()->findOrFail($parentId))->save();
            $victimIds[$i] = (int) $victim->id;

            $survivor = new Category(['name' => "survivor-$i"]);
            $survivor->appendToNode(Category::query()->findOrFail($parentId))->save();
            $survivorIds[] = (int) $survivor->id;
        }

        $exits = $this->runConcurrentWorkers($workers, function (int $worker) use ($victimIds): void {
            $this->withDeadlockRetry(function () use ($worker, $victimIds): void {
                Category::query()->findOrFail($victimIds[$worker])->delete();
            }, maxAttempts: 16);
        });

        $this->assertSame(
            array_fill(0, $workers, 0),
            $exits,
            'every worker must complete without error',
        );

        // Parent + one survivor per worker remain.
        $this->assertSame(1 + $workers, Category::query()->count());

        // Every survivor is still a leaf directly under the parent — a
        // torn closeGap built on stale bounds would widen a survivor's
        // range (borrowing a vanished victim's slots) or push it outside
        // the parent.
        $rows = Category::query()->orderBy('lft')->get(['id', 'lft', 'rgt', 'parent_id'])->all();
        foreach ($rows as $r) {
            if ((int) $r->id === $parentId) {
                continue;
            }
            $this->assertSame($r->lft + 1, $r->rgt, "survivor {$r->id} is no longer a leaf");
            $this->assertSame($parentId, (int) $r->parent_id, "survivor {$r->id} has wrong parent_id");
        }

        // No overlaps / orphans / duplicate slots. Gaps are legal; broken
        // nesting is not.
        $this->assertFalse(Category::isBroken(), 'tree must stay valid after concurrent deletes');
    }
}
