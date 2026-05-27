<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Events\FixAggregatesChunkCompleted;
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

    // ----------------------------------------------------------------
    // displayName() — Horizon / failed-jobs UI label
    //
    // displayName composes the model class and an optional anchor-id
    // suffix into a single string used by Horizon, the Telescope
    // failed-jobs UI, and `php artisan queue:failed`. The two arms of
    // the conditional (with / without anchorId) weren't pinned by any
    // existing test, leaving the `=== null` Identical mutant and the
    // ternary arm-swap mutant escaping in the master Infection run.
    // ----------------------------------------------------------------

    public function test_display_name_includes_anchor_id_suffix_when_set(): void
    {
        $job = new FixAggregatesJob(
            modelClass: Area::class,
            anchorId: 42,
        );

        $this->assertSame('fixAggregates('.Area::class.'#42)', $job->displayName());
    }

    public function test_display_name_omits_suffix_when_anchor_id_is_null(): void
    {
        $job = new FixAggregatesJob(
            modelClass: Area::class,
        );

        $this->assertSame('fixAggregates('.Area::class.')', $job->displayName());
    }

    // ----------------------------------------------------------------
    // Chunk-size = 0 boundary
    //
    // `FixAggregatesJob::handle()` decides whether to take the chunked
    // path with `if ($this->chunkSize !== null && $this->chunkSize > 0)`.
    // The "0 means no chunking" contract is tested elsewhere for the
    // trait's `Area::fixAggregates(chunkSize: 0)` entry, but the JOB's
    // own boundary check wasn't pinned — leaving the `&&` LogicalAnd
    // and `> 0` GreaterThan mutants escaped (a flipped `||` or `>=`
    // would route `chunkSize=0` through handleChunked, which fires
    // FixAggregatesChunkCompleted; the non-chunked path doesn't).
    // ----------------------------------------------------------------

    public function test_handle_with_chunk_size_zero_takes_non_chunked_path(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $child = new Area(['name' => 'c', 'tickets' => 5]);
        $child->appendToNode($root)->save();

        // Drift one aggregate so the repair path does observable work.
        DB::table('areas')->where('id', $root->id)->update(['tickets_total' => 0]);

        Event::fake([FixAggregatesChunkCompleted::class]);

        $job = new FixAggregatesJob(modelClass: Area::class, chunkSize: 0);
        $result = $job->handle();

        // Non-chunked path repairs in one shot — no chunk events emitted.
        Event::assertNotDispatched(FixAggregatesChunkCompleted::class);

        // And the repair still happened (the test's premise depends on
        // the job doing the work it would do on the non-chunked path).
        $this->assertGreaterThan(0, $result->totalRowsUpdated);
    }

    // ----------------------------------------------------------------
    // Real-queue serialization round-trip
    //
    // The DB / Redis queue drivers serialize the job to a string before
    // handing it to a worker. `Queue::fake()` skips that step entirely,
    // so the rest of this file doesn't pin that anchors survive the
    // round-trip. These tests use `serialize()` / `unserialize()`
    // directly — same encoding the drivers use under the hood — to
    // confirm scalar carries through and the deserialised job still
    // runs end-to-end.
    // ----------------------------------------------------------------

    public function test_job_round_trips_through_php_serialization_with_anchor_id(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 7]);
        $root->saveAsRoot();
        $root->refresh();

        $original = new FixAggregatesJob(
            modelClass: Area::class,
            anchorId: (int) $root->id,
            chunkSize: 100,
            cursorAfterId: 42,
            chunkIndex: 3,
        );

        /** @var FixAggregatesJob $revived */
        $revived = unserialize(serialize($original));

        $this->assertSame($original->modelClass, $revived->modelClass);
        $this->assertSame($original->anchorId, $revived->anchorId);
        $this->assertSame($original->chunkSize, $revived->chunkSize);
        $this->assertSame($original->cursorAfterId, $revived->cursorAfterId);
        $this->assertSame($original->chunkIndex, $revived->chunkIndex);

        // End-to-end: the deserialised job runs against the real DB
        // without issue — the constructor-readonly state survived
        // serialization with no extra wiring (no models to re-hydrate,
        // since the anchor is carried as an id and re-queried by handle).
        $result = $revived->handle();
        $this->assertInstanceOf(AggregateFixResult::class, $result);
    }

    public function test_job_round_trips_through_php_serialization_without_anchor(): void
    {
        $original = new FixAggregatesJob(modelClass: Area::class);

        /** @var FixAggregatesJob $revived */
        $revived = unserialize(serialize($original));

        $this->assertSame(Area::class, $revived->modelClass);
        $this->assertNull($revived->anchorId);
        $this->assertNull($revived->chunkSize);
        $this->assertSame(0, $revived->chunkIndex);
    }

    // ----------------------------------------------------------------
    // Failure-mode contract
    //
    // FixAggregatesJob doesn't override Laravel's `failed()` hook —
    // any thrown exception bubbles to the queue runtime, which then
    // calls the application-level `Queue::failing()` listeners or the
    // package's AggregateMaintenanceFailed wrapper installed by the
    // caller. These tests pin that handle() doesn't swallow exceptions
    // (which would silently skip the failure path) and that the typical
    // failure modes surface the expected exception class.
    // ----------------------------------------------------------------

    public function test_handle_exception_is_propagated_for_queue_runtime_to_record(): void
    {
        // Anchor id that doesn't exist → handle() throws RuntimeException
        // unchanged. The job has no try/catch, so the queue runtime
        // observes the throw and routes the job to `failed_jobs` (DB
        // driver) or invokes the configured failed listeners. Without
        // this propagation, FixAggregatesJob failures would be invisible.
        $job = new FixAggregatesJob(modelClass: Area::class, anchorId: 999_999);

        try {
            $job->handle();
            $this->fail('handle() should have thrown — silent failure would skip Laravel\'s failed-job path');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found on', $e->getMessage());
            $this->assertStringContainsString(Area::class, $e->getMessage());
        }
    }
}
