<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Concurrency;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\InvalidSiblingOrderException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * reorderChildren() reads the parent's bounds and the child bounds,
 * computes the per-sibling shift windows, then issues one UPDATE. Those
 * reads used to run OUTSIDE the UPDATE's transaction, so two concurrent
 * reorders of the same parent could both read the same starting bounds
 * and apply conflicting shifts, tearing the lft/rgt sequence.
 *
 * The fix wraps the reads and the UPDATE in one transaction with the
 * parent row locked FOR UPDATE; concurrent reorders serialise. This
 * forks workers that repeatedly reverse the same parent's children and
 * asserts the tree stays intact.
 *
 * Skipped on SQLite / without pcntl_fork; runs on MySQL / MariaDB / PG.
 */
final class ReorderChildrenConcurrencyTest extends TestCase
{
    use ConcurrencyHarness;

    #[Test]
    public function concurrent_reorders_of_the_same_parent_keep_the_tree_intact(): void
    {
        $this->requireForkableMultiWriterBackend();

        $parent = new Category(['name' => 'parent']);
        $parent->saveAsRoot();
        $parentId = (int) $parent->id;

        // Five children to give the reorder real shift work.
        for ($i = 0; $i < 5; $i++) {
            $child = new Category(['name' => "c{$i}"]);
            $child->appendToNode(Category::query()->findOrFail($parentId))->save();
        }

        $childIds = Category::query()
            ->where('parent_id', $parentId)
            ->orderBy('lft')
            ->pluck('id')
            ->map(static fn ($id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        $workers = 4;
        $iterations = 5;

        $exits = $this->runConcurrentWorkers($workers, function (int $worker) use ($parentId, $iterations): void {
            for ($j = 0; $j < $iterations; $j++) {
                $this->withDeadlockRetry(function () use ($parentId): void {
                    $parent = Category::query()->findOrFail($parentId);

                    // Re-read the current child order and reverse it.
                    /** @var list<int> $order */
                    $order = Category::query()
                        ->where('parent_id', $parentId)
                        ->orderBy('lft')
                        ->pluck('id')
                        ->map(static fn ($id): int => is_numeric($id) ? (int) $id : 0)
                        ->reverse()
                        ->values()
                        ->all();

                    try {
                        $parent->reorderChildren($order);
                    } catch (InvalidSiblingOrderException) {
                        // A racing reorder changed the membership snapshot
                        // between our read and the locked re-validation —
                        // a legitimate optimistic-failure, not corruption.
                    }
                }, maxAttempts: 16);
            }
        });

        $this->assertSame(array_fill(0, $workers, 0), $exits, 'every worker must complete without error');

        // Same five children, still all direct leaves of the parent.
        $after = Category::query()
            ->where('parent_id', $parentId)
            ->orderBy('lft')
            ->pluck('id')
            ->map(static fn ($id): int => is_numeric($id) ? (int) $id : 0)
            ->all();

        sort($childIds);
        $afterSorted = $after;
        sort($afterSorted);
        $this->assertSame($childIds, $afterSorted, 'child membership changed under concurrent reorders');

        $this->assertFalse(Category::isBroken(), 'tree corrupted by concurrent reorders');

        $rows = Category::query()->orderBy('lft')->get(['lft', 'rgt'])->all();
        $lfts = array_map(static fn (Category $r): int => $r->lft, $rows);
        $this->assertSame(count($lfts), count(array_unique($lfts)), 'duplicate lft after concurrent reorders');
    }
}
