<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Locks the query count of each mutation path so any future change that
 * sneaks in an N+1 (e.g. forgetting a join, accidentally lazy-loading)
 * gets caught immediately rather than turning up in production logs.
 *
 * Counts here are the current happy path — bump them in lockstep with
 * deliberate changes; resist the urge to bump them just to make a test
 * pass.
 */
final class QueryCountTest extends TestCase
{
    private function countQueriesFor(\Closure $work): int
    {
        $count = 0;
        DB::listen(static function () use (&$count): void {
            $count++;
        });

        $work();

        return $count;
    }

    #[Test]
    public function save_as_root_runs_three_queries(): void
    {
        $node = new Category(['name' => 'Root']);

        $queries = $this->countQueriesFor(static function () use ($node): void {
            $node->saveAsRoot();
        });

        // 1: select max(rgt)
        // 2: makeGap update
        // 3: insert
        $this->assertSame(3, $queries);
    }

    #[Test]
    public function append_to_node_new_runs_three_queries(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root = $root->refresh();

        $child = new Category(['name' => 'Child']);

        $queries = $this->countQueriesFor(static function () use ($child, $root): void {
            $child->appendToNode($root)->save();
        });

        // 1: read fresh parent bounds (getNodeData)
        // 2: makeGap
        // 3: insert
        $this->assertSame(3, $queries);
    }

    #[Test]
    public function append_existing_node_runs_six_queries(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();

        $b = new Category(['name' => 'B']);
        $b->appendToNode($root->refresh())->save();

        $queries = $this->countQueriesFor(static function () use ($a, $b): void {
            $a->appendToNode($b->refresh())->save();
        });

        // 1: fresh parent bounds (b)
        // 2: read $this fresh bounds (in positionAt — guards against stale in-memory state)
        // 3: select Eloquent uses to detect concurrent modifications (lockForUpdate via save)
        // 4: moveNode update
        // 5: re-read $this bounds (for dirty tracking)
        // 6: eloquent update (parent_id, updated_at)
        $this->assertSame(6, $queries);
    }

    #[Test]
    public function count_errors_runs_four_queries(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $queries = $this->countQueriesFor(static function (): void {
            Category::countErrors();
        });

        // 1: invalid_bounds
        // 2: duplicate_lft
        // 3: duplicate_rgt
        // 4: orphans
        $this->assertSame(4, $queries);
    }
}
