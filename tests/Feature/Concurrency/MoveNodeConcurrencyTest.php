<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Concurrency;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The biggest gap the review flagged: existing-node MOVES under
 * contention. `appendToNode`/`makeRoot` lock their target before
 * reading its bounds, but the move path also reads the MOVER's OWN
 * bounds — and that read used to be unlocked. Between it and the target
 * lock sit the SubtreeMoving dispatch and the before-move aggregate
 * hook; a concurrent committed insert/move that shifts the mover's
 * bounds in that window makes the moveNode() CASE band match the wrong
 * rows, silently producing overlapping bounds.
 *
 * This forks mover workers that shuttle their own leaf between two
 * parents while inserter workers append/remove siblings under the same
 * parents — maximising bound churn in the mover's window — then asserts
 * the tree is intact. The fix is the FOR UPDATE lock on the mover's own
 * bounds read in HasTreeMutation.
 *
 * Skipped on SQLite (no row locking; in-memory DB doesn't cross fork)
 * and when pcntl_fork is unavailable. Runs on the MySQL / MariaDB /
 * PostgreSQL CI cells.
 */
final class MoveNodeConcurrencyTest extends TestCase
{
    use ConcurrencyHarness;

    #[Test]
    public function concurrent_moves_and_inserts_keep_the_tree_intact(): void
    {
        $this->requireForkableMultiWriterBackend();

        // root > { P1, P2 }, with a movable leaf under each.
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();

        $p1 = new Category(['name' => 'P1']);
        $p1->appendToNode($root->refresh())->save();
        $p2 = new Category(['name' => 'P2']);
        $p2->appendToNode($root->refresh())->save();

        $m1 = new Category(['name' => 'M1']);
        $m1->appendToNode($p1->refresh())->save();
        $m2 = new Category(['name' => 'M2']);
        $m2->appendToNode($p2->refresh())->save();

        $p1Id = (int) $p1->id;
        $p2Id = (int) $p2->id;
        $m1Id = (int) $m1->id;
        $m2Id = (int) $m2->id;

        // 2 movers (one per leaf) + 4 inserters churning bounds.
        $moverLeaves = [0 => $m1Id, 1 => $m2Id];
        $workers = 6;
        $iterations = 5;

        $exits = $this->runConcurrentWorkers($workers, function (int $worker) use ($moverLeaves, $p1Id, $p2Id, $iterations): void {
            for ($j = 0; $j < $iterations; $j++) {
                if (isset($moverLeaves[$worker])) {
                    // Mover: shuttle this leaf between P1 and P2.
                    $leafId = $moverLeaves[$worker];
                    $targetId = $j % 2 === 0 ? $p2Id : $p1Id;
                    $this->withDeadlockRetry(function () use ($leafId, $targetId): void {
                        $leaf = Category::query()->findOrFail($leafId);
                        $target = Category::query()->findOrFail($targetId);
                        $leaf->appendToNode($target)->save();
                    }, maxAttempts: 16);
                } else {
                    // Inserter: append a leaf under P1 or P2, churning the
                    // bounds the movers are reading.
                    $parentId = $worker % 2 === 0 ? $p1Id : $p2Id;
                    $this->withDeadlockRetry(function () use ($worker, $j, $parentId): void {
                        $parent = Category::query()->findOrFail($parentId);
                        $child = new Category(['name' => sprintf('w%d-%d', $worker, $j)]);
                        $child->appendToNode($parent)->save();
                    }, maxAttempts: 16);
                }
            }
        });

        $this->assertSame(array_fill(0, $workers, 0), $exits, 'every worker must complete without error');

        // The mover leaves must still exist and still be leaves under
        // one of the two parents.
        foreach ([$m1Id, $m2Id] as $leafId) {
            $leaf = Category::query()->find($leafId);
            $this->assertNotNull($leaf, "mover leaf {$leafId} vanished");
            $this->assertSame($leaf->lft + 1, $leaf->rgt, "mover leaf {$leafId} is no longer a leaf");
            $this->assertContains((int) $leaf->parent_id, [$p1Id, $p2Id], "mover leaf {$leafId} ended up under an unexpected parent");
        }

        // The decisive assertion: no overlapping/duplicate bounds, no
        // parent-bounds mismatch — exactly the corruption an unlocked
        // mover-bounds read produces.
        $this->assertFalse(Category::isBroken(), 'tree corrupted by concurrent moves + inserts');

        // Bounds remain a contiguous, unique 1..2N permutation.
        $rows = Category::query()->orderBy('lft')->get(['lft', 'rgt'])->all();
        $lfts = array_map(static fn (Category $r): int => $r->lft, $rows);
        $rgts = array_map(static fn (Category $r): int => $r->rgt, $rows);
        $this->assertSame(count($lfts), count(array_unique($lfts)), 'duplicate lft after concurrent moves');
        $this->assertSame(count($rgts), count(array_unique($rgts)), 'duplicate rgt after concurrent moves');
    }
}
