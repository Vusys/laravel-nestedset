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
}
