<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Jobs\FixAggregatesJob;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Phase N: `queueFixAggregates` dispatches a {@see FixAggregatesJob}
 * with the model class + optional anchor id. Tests use Queue::fake()
 * so the job is asserted without running, then one end-to-end test
 * runs the sync handler against a drifted tree to verify the actual
 * repair path.
 */
final class QueueFixAggregatesTest extends TestCase
{
    public function test_queue_fix_aggregates_dispatches_a_job(): void
    {
        Queue::fake();

        $result = Area::queueFixAggregates();

        $this->assertInstanceOf(FixAggregatesJob::class, $result);
        $this->assertSame(Area::class, $result->modelClass);
        $this->assertNull($result->anchorId);

        Queue::assertPushed(FixAggregatesJob::class, fn (FixAggregatesJob $job): bool => $job->modelClass === Area::class && $job->anchorId === null);
    }

    public function test_queue_fix_aggregates_with_anchor_carries_the_id(): void
    {
        Queue::fake();

        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Area::queueFixAggregates($root);

        Queue::assertPushed(FixAggregatesJob::class, fn (FixAggregatesJob $job): bool => $job->modelClass === Area::class
            && $job->anchorId === (int) $root->id);
    }

    public function test_per_call_queue_and_connection_overrides_take_precedence(): void
    {
        Queue::fake();

        Area::queueFixAggregates(
            anchor: null,
            onConnection: 'redis-custom',
            onQueue: 'aggregates-low',
        );

        Queue::assertPushedOn('aggregates-low', FixAggregatesJob::class);
    }

    public function test_config_defaults_route_when_no_per_call_overrides(): void
    {
        Queue::fake();
        config(['nestedset.queue.queue' => 'aggregates-default']);

        Area::queueFixAggregates();

        Queue::assertPushedOn('aggregates-default', FixAggregatesJob::class);
    }

    public function test_scoped_model_without_anchor_throws_at_dispatch_time(): void
    {
        Queue::fake();

        $this->expectException(ScopeViolationException::class);

        MenuItem::queueFixAggregates();
    }

    public function test_handle_repairs_drifted_aggregates_end_to_end(): void
    {
        // No Queue::fake() — run the job's handle() synchronously.
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();
        $child = new Area(['name' => 'c', 'tickets' => 5]);
        $child->appendToNode($root)->save();

        // Drift the stored aggregate so the job has something to repair.
        DB::table('areas')->where('id', $root->id)->update(['tickets_total' => 0]);
        $this->assertTrue(Area::aggregatesAreBroken());

        $job = new FixAggregatesJob(modelClass: Area::class);
        $result = $job->handle();

        $this->assertGreaterThan(0, $result->totalRowsUpdated);
        $this->assertFalse(Area::aggregatesAreBroken());
        $this->assertSame(5, (int) $root->refresh()->tickets_total);
    }

    public function test_handle_with_missing_anchor_throws_runtime_exception(): void
    {
        $job = new FixAggregatesJob(modelClass: Area::class, anchorId: 999_999);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found on');

        $job->handle();
    }

    public function test_handle_is_idempotent_on_clean_tree(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root->refresh();
        $this->assertFalse(Area::aggregatesAreBroken(), 'baseline sanity');

        $first = (new FixAggregatesJob(modelClass: Area::class))->handle();
        $this->assertSame(0, $first->totalRowsUpdated, 'clean tree → zero rows updated');

        $second = (new FixAggregatesJob(modelClass: Area::class))->handle();
        $this->assertSame(0, $second->totalRowsUpdated, 'second run still zero — idempotent');
    }

    // ----------------------------------------------------------------
    // Self-redispatching chunked path
    // ----------------------------------------------------------------

    /**
     * Build a small tree with $count nodes via the standard appendToNode
     * path. Each node has tickets=1 so aggregate drift is easy to spot.
     */
    private function seedAreaTree(int $count): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 1]);
        $root->saveAsRoot();
        $parent = $root->refresh();
        for ($i = 1; $i < $count; $i++) {
            $child = new Area(['name' => "n{$i}", 'tickets' => 1]);
            $child->appendToNode($parent)->save();
            $parent = $child->refresh();
        }
    }

    public function test_chunked_job_dispatches_a_followup_when_more_rows_remain(): void
    {
        Queue::fake();

        $this->seedAreaTree(5); // chunkSize=2 → at least one follow-up dispatch

        $job = new FixAggregatesJob(modelClass: Area::class, chunkSize: 2);
        $job->handle();

        Queue::assertPushed(FixAggregatesJob::class, fn (FixAggregatesJob $next): bool => $next->modelClass === Area::class
            && $next->chunkSize === 2
            && $next->cursorAfterId !== null);
    }

    public function test_chunked_job_stops_when_the_last_chunk_is_short(): void
    {
        Queue::fake();

        $this->seedAreaTree(3); // 3 nodes < chunkSize=5 → first chunk is the last

        $job = new FixAggregatesJob(modelClass: Area::class, chunkSize: 5);
        $job->handle();

        Queue::assertNotPushed(FixAggregatesJob::class);
    }

    public function test_chunked_redispatch_inherits_queue_and_connection(): void
    {
        Queue::fake();

        $this->seedAreaTree(4);

        $job = new FixAggregatesJob(modelClass: Area::class, chunkSize: 2);
        $job->onConnection('redis')->onQueue('aggregates-low');
        $job->handle();

        Queue::assertPushed(FixAggregatesJob::class, fn (FixAggregatesJob $next): bool => $next->connection === 'redis' && $next->queue === 'aggregates-low');
    }

    public function test_chunked_end_to_end_repairs_a_drifted_tree(): void
    {
        // No Queue::fake() — drive the chunk walk manually so we
        // exercise the real recursion logic without needing a worker.
        $this->seedAreaTree(6);
        DB::table('areas')->update([
            'tickets_total' => 0,
            'tickets_count_all' => 0,
            'tickets_avg' => null,
            'tickets_min' => null,
            'tickets_max' => null,
            'tickets_avg__sum' => 0,
            'tickets_avg__count' => 0,
        ]);
        $this->assertTrue(Area::aggregatesAreBroken());

        $cursor = null;
        $passes = 0;
        do {
            $passes++;
            $chunk = Area::fixAggregatesChunk(anchor: null, afterId: $cursor, chunkSize: 2);
            $cursor = $chunk['nextAfterId'];
            $this->assertLessThan(20, $passes, 'guard against infinite loop in chunk walk');
        } while ($cursor !== null);

        $this->assertFalse(Area::aggregatesAreBroken(), 'all aggregates repaired across chunks');
        $this->assertGreaterThanOrEqual(3, $passes, 'multiple chunks were required (6 rows / chunk=2)');
    }

    public function test_queue_fix_aggregates_chunk_size_param_carries_to_job(): void
    {
        Queue::fake();

        $job = Area::queueFixAggregates(chunkSize: 500);

        $this->assertSame(500, $job->chunkSize);
        Queue::assertPushed(FixAggregatesJob::class, fn (FixAggregatesJob $j): bool => $j->chunkSize === 500);
    }
}
