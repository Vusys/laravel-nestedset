<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Events\AggregateMaintenanceFailed;
use Vusys\NestedSet\Events\BulkInsertTreeCompleted;
use Vusys\NestedSet\Events\DeferredAggregateMaintenanceCompleted;
use Vusys\NestedSet\Events\FixAggregatesChunkCompleted;
use Vusys\NestedSet\Events\FixAggregatesCompleted;
use Vusys\NestedSet\Events\FixAggregatesJobDispatched;
use Vusys\NestedSet\Events\FixTreeCompleted;
use Vusys\NestedSet\Events\NodeMoved;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Item 18: typed events on Laravel's event bus around the package's
 * meaningful operations. One test per event class asserts firing,
 * payload, and (where it applies) suppression via the
 * `nestedset.events_enabled` config flag.
 */
final class EventsTest extends TestCase
{
    protected bool $allowBrokenTreeAtTearDown = true;

    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    /** Narrows the mixed return of getKey() to int for assertion comparisons. */
    private function rootIdOf(Area $node): int
    {
        $key = $node->getKey();
        if (! is_numeric($key)) {
            $this->fail('Expected numeric id, got '.get_debug_type($key));
        }

        return (int) $key;
    }

    private function seedMotivatingTree(): Area
    {
        // Root(100) > A(50) > A1(50); Root > B(25).
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 50]);
        $a1->appendToNode($a->refresh())->save();

        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();

        return $root->refresh();
    }

    // ----------------------------------------------------------------
    // FixTreeCompleted
    // ----------------------------------------------------------------

    public function test_fix_tree_fires_completion_event_with_aggregate_count(): void
    {
        $this->seedMotivatingTree();
        Event::fake([FixTreeCompleted::class]);

        Area::fixTree();

        Event::assertDispatched(FixTreeCompleted::class, function (FixTreeCompleted $e): bool {
            $this->assertSame(Area::class, $e->modelClass);
            $this->assertNull($e->anchorId);
            $this->assertGreaterThan(0, $e->nodesUpdated);
            $this->assertGreaterThanOrEqual(0.0, $e->durationMs);
            // Area declares aggregates, so the aggregatesFixed field is populated (may be 0 on a clean tree).
            $this->assertNotNull($e->aggregatesFixed);

            return true;
        });
    }

    public function test_fix_tree_aggregates_fixed_is_null_when_model_has_no_aggregates(): void
    {
        $category = new Category(['name' => 'Root']);
        $category->saveAsRoot();
        Event::fake([FixTreeCompleted::class]);

        Category::fixTree();

        Event::assertDispatched(FixTreeCompleted::class, function (FixTreeCompleted $e): bool {
            $this->assertNull($e->aggregatesFixed);

            return true;
        });
    }

    public function test_fix_tree_with_anchor_carries_anchor_id(): void
    {
        // The whole-table fixTree() test above already pins anchorId=null.
        // This is the per-subtree case where fixTree($anchor) is given a
        // persisted node and the event's `anchorId` should be that
        // node's key — exercising the
        //   $rootId = is_int($key) || is_string($key) ? $key : null
        // narrowing for the non-null branch. Without this case, the
        // `$anchor instanceof Model` guard, the ternary, and the
        // `||` between the type checks can all be mutated without any
        // observable failure (the unanchored test still passes because
        // its expected anchorId is null).
        $root = $this->seedMotivatingTree();

        Event::fake([FixTreeCompleted::class]);

        Area::fixTree($root);

        Event::assertDispatched(FixTreeCompleted::class, function (FixTreeCompleted $e) use ($root): bool {
            $this->assertSame(Area::class, $e->modelClass);
            $this->assertSame($this->rootIdOf($root), $e->anchorId);

            return true;
        });
    }

    // ----------------------------------------------------------------
    // FixAggregatesCompleted (non-chunked + chunked end-of-loop)
    // ----------------------------------------------------------------

    public function test_fix_aggregates_fires_completion_event_non_chunked(): void
    {
        $this->seedMotivatingTree();
        Event::fake([FixAggregatesCompleted::class, FixAggregatesChunkCompleted::class]);

        Area::fixAggregates();

        Event::assertDispatched(FixAggregatesCompleted::class, function (FixAggregatesCompleted $e): bool {
            $this->assertSame(Area::class, $e->modelClass);
            $this->assertNull($e->chunkSize);
            $this->assertSame(1, $e->totalChunks);

            return true;
        });
        Event::assertNotDispatched(FixAggregatesChunkCompleted::class);
    }

    public function test_fix_aggregates_chunked_fires_chunk_events_then_completion(): void
    {
        // Seed enough rows that chunkSize=2 produces multiple chunks.
        // The exact count depends on the loop's termination condition
        // (one trailing "empty" chunk after the last data chunk), so
        // assert at-least rather than exactly.
        $this->seedMotivatingTree();
        Event::fake([FixAggregatesCompleted::class, FixAggregatesChunkCompleted::class]);

        Area::fixAggregates(chunkSize: 2);

        Event::assertDispatched(FixAggregatesChunkCompleted::class);

        Event::assertDispatched(FixAggregatesCompleted::class, function (FixAggregatesCompleted $e): bool {
            $this->assertSame(2, $e->chunkSize);
            $this->assertGreaterThanOrEqual(2, $e->totalChunks);

            return true;
        });
    }

    public function test_fix_aggregates_chunk_event_carries_progress_fields(): void
    {
        $this->seedMotivatingTree();
        Event::fake([FixAggregatesChunkCompleted::class]);

        Area::fixAggregates(chunkSize: 2);

        Event::assertDispatched(FixAggregatesChunkCompleted::class, function (FixAggregatesChunkCompleted $e): bool {
            $this->assertSame(Area::class, $e->modelClass);
            $this->assertSame(2, $e->chunkSize);
            $this->assertGreaterThanOrEqual(0, $e->chunkIndex);

            return true;
        });
    }

    // ----------------------------------------------------------------
    // FixAggregatesJobDispatched
    // ----------------------------------------------------------------

    public function test_queue_fix_aggregates_fires_dispatch_event(): void
    {
        $this->seedMotivatingTree();
        Event::fake([FixAggregatesJobDispatched::class]);

        Area::queueFixAggregates(chunkSize: 1_000);

        Event::assertDispatched(FixAggregatesJobDispatched::class, function (FixAggregatesJobDispatched $e): bool {
            $this->assertSame(Area::class, $e->modelClass);
            $this->assertSame(1_000, $e->chunkSize);

            return true;
        });
    }

    public function test_queue_fix_aggregates_with_anchor_event_carries_anchor_id(): void
    {
        // `queueFixAggregates($anchor)` fires FixAggregatesJobDispatched
        // with the anchor's key narrowed through `anchorRootId()`. The
        // existing dispatch-event test uses no anchor (anchorId would be
        // null) and `QueueFixAggregatesTest` pins the *job's* anchorId
        // but not the *event's* — so the event-side narrowing call site
        // had no observable test.
        $root = $this->seedMotivatingTree();

        Event::fake([FixAggregatesJobDispatched::class]);

        Area::queueFixAggregates($root);

        Event::assertDispatched(FixAggregatesJobDispatched::class, function (FixAggregatesJobDispatched $e) use ($root): bool {
            $this->assertSame($this->rootIdOf($root), $e->anchorId);

            return true;
        });
    }

    // ----------------------------------------------------------------
    // BulkInsertTreeCompleted
    // ----------------------------------------------------------------

    public function test_bulk_insert_tree_fires_completion_event(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Event::fake([BulkInsertTreeCompleted::class]);

        Area::bulkInsertTree([
            ['name' => 'a', 'tickets' => 1],
            ['name' => 'b', 'tickets' => 2],
        ], appendTo: $root);

        Event::assertDispatched(BulkInsertTreeCompleted::class, function (BulkInsertTreeCompleted $e) use ($root): bool {
            $this->assertSame(Area::class, $e->modelClass);
            $this->assertSame($this->rootIdOf($root), $e->anchorId);
            $this->assertSame(2, $e->rowsInserted);

            return true;
        });
    }

    // ----------------------------------------------------------------
    // DeferredAggregateMaintenanceCompleted
    // ----------------------------------------------------------------

    public function test_deferred_aggregate_maintenance_fires_boundary_event(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Event::fake([DeferredAggregateMaintenanceCompleted::class]);

        Area::withDeferredAggregateMaintenance(function () use ($root): void {
            foreach (range(1, 3) as $i) {
                $node = new Area(['name' => "n{$i}", 'tickets' => $i]);
                $node->appendToNode($root->refresh())->save();
            }
        }, $root);

        Event::assertDispatchedTimes(DeferredAggregateMaintenanceCompleted::class, 1);
        Event::assertDispatched(DeferredAggregateMaintenanceCompleted::class, function (DeferredAggregateMaintenanceCompleted $e) use ($root): bool {
            $this->assertSame(Area::class, $e->modelClass);
            $this->assertSame($this->rootIdOf($root), $e->anchorId);
            $this->assertGreaterThanOrEqual(0.0, $e->closureDurationMs);
            $this->assertGreaterThanOrEqual(0.0, $e->repairDurationMs);

            return true;
        });
    }

    public function test_deferred_aggregate_maintenance_event_not_fired_when_closure_throws(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Event::fake([DeferredAggregateMaintenanceCompleted::class]);

        // Closure is extracted so PHPStan can see the throw site; the
        // wrapper re-raises any Throwable that escapes the user's work.
        $boom = $this->makeBoomClosure();

        try {
            Area::withDeferredAggregateMaintenance($boom, $root);
        } catch (\Throwable) {
            // expected — the wrapper re-throws after firing its fix-up.
        }

        Event::assertNotDispatched(DeferredAggregateMaintenanceCompleted::class);
    }

    /**
     * @return \Closure(): void
     */
    private function makeBoomClosure(): \Closure
    {
        return static function (): never {
            throw new RuntimeException('boom');
        };
    }

    public function test_deferred_aggregate_maintenance_event_fires_only_at_outermost_exit(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Event::fake([DeferredAggregateMaintenanceCompleted::class]);

        Area::withDeferredAggregateMaintenance(function () use ($root): void {
            Area::withDeferredAggregateMaintenance(function () use ($root): void {
                $node = new Area(['name' => 'n', 'tickets' => 1]);
                $node->appendToNode($root)->save();
            }, $root);
        }, $root);

        Event::assertDispatchedTimes(DeferredAggregateMaintenanceCompleted::class, 1);
    }

    // ----------------------------------------------------------------
    // NodeMoved
    // ----------------------------------------------------------------

    public function test_node_moved_fires_for_existing_node_append_to_node(): void
    {
        $this->seedMotivatingTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $b = Area::query()->where('name', 'B')->firstOrFail();

        Event::fake([NodeMoved::class]);

        // Move A under B.
        $a->appendToNode($b->refresh())->save();

        Event::assertDispatched(NodeMoved::class, function (NodeMoved $e) use ($a): bool {
            $this->assertSame(Area::class, $e->modelClass);
            $this->assertSame($this->rootIdOf($a), $e->nodeId);
            $this->assertSame('appendTo', $e->operation);
            $this->assertNotEquals($e->fromBounds->lft, $e->toBounds->lft);

            return true;
        });
    }

    public function test_node_moved_does_not_fire_on_new_node_create(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Event::fake([NodeMoved::class]);

        $child = new Area(['name' => 'c', 'tickets' => 1]);
        $child->appendToNode($root)->save();

        // appendToNode on a *new* node is a placement, not a move.
        Event::assertNotDispatched(NodeMoved::class);
    }

    // ----------------------------------------------------------------
    // AggregateMaintenanceFailed
    // ----------------------------------------------------------------

    public function test_aggregate_maintenance_failed_fires_when_hook_throws(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Event::fake([AggregateMaintenanceFailed::class]);

        // Force a failure by registering a listener on created that
        // throws *inside* the aggregate-maintenance hook surface. We
        // attach to the saved event with the aggregate-related
        // companion — the cleanest reproducer would inject a faulty
        // strategy, but a coarser approach works: corrupt the SQL by
        // wrapping the connection's update method via a query
        // listener that throws when the package's delta UPDATE hits.
        //
        // Simpler: register a closure on the saved event that runs
        // BEFORE the trait's `applyAggregateDeltas` listener and
        // throws — but listeners on the same event fire in
        // registration order, and the trait registers first via
        // bootNodeTrait. The portable way is to throw inside the
        // restored hook: register a global event listener that
        // throws when the trait fires applyAggregateOnRestore.
        //
        // Easiest: configure the DB to fail a specific aggregate
        // UPDATE. We do that by dropping the aggregate column on
        // disk so the trait's UPDATE references a missing column.
        DB::statement('CREATE TABLE areas_backup AS SELECT * FROM areas');

        try {
            DB::statement('ALTER TABLE areas DROP COLUMN tickets_total');
        } catch (\Throwable) {
            $this->markTestSkipped('Backend rejects DROP COLUMN on this connection.');
        }

        try {
            $node = new Area(['name' => 'fail', 'tickets' => 1]);
            try {
                $node->appendToNode($root)->save();
            } catch (\Throwable) {
                // The trait propagates the failure; AggregateMaintenanceFailed should still have fired.
            }

            Event::assertDispatched(AggregateMaintenanceFailed::class, function (AggregateMaintenanceFailed $e) use ($node): bool {
                $this->assertSame(Area::class, $e->modelClass);
                // stage is one of capture/apply/on_create/on_delete/on_restore
                $this->assertContains($e->stage, ['capture', 'apply', 'on_create', 'on_delete', 'on_restore']);
                $this->assertInstanceOf(\Throwable::class, $e->exception);

                // anchorId narrows mixed Model::getKey() to int|string|null
                // via `is_int($k) || is_string($k) ? $k : null`. For Area
                // (int-keyed) the anchorId must equal the persisted key —
                // pinning this guards the type-narrow ternary against
                // mutants that flip the operator or swap the arms.
                $this->assertSame($node->getKey(), $e->anchorId);

                return true;
            });
        } finally {
            // Restore schema so tearDown doesn't blow up
            DB::statement('DROP TABLE areas');
            DB::statement('ALTER TABLE areas_backup RENAME TO areas');
        }
    }

    // ----------------------------------------------------------------
    // Config flag
    // ----------------------------------------------------------------

    public function test_events_enabled_false_suppresses_every_event(): void
    {
        $root = new Area(['name' => 'r', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        config(['nestedset.events_enabled' => false]);

        Event::fake([
            FixTreeCompleted::class,
            FixAggregatesCompleted::class,
            FixAggregatesChunkCompleted::class,
            FixAggregatesJobDispatched::class,
            BulkInsertTreeCompleted::class,
            DeferredAggregateMaintenanceCompleted::class,
            NodeMoved::class,
        ]);

        // Exercise the surfaces that fire each event.
        Area::fixTree();
        Area::fixAggregates();
        Area::fixAggregates(chunkSize: 1_000);
        Area::queueFixAggregates();
        Area::bulkInsertTree([['name' => 'x', 'tickets' => 1]], appendTo: $root);

        $node = Area::query()->where('name', 'x')->firstOrFail();
        $node->appendToNode($root->refresh())->save();

        Area::withDeferredAggregateMaintenance(function (): void {
            // empty
        }, $root);

        Event::assertNotDispatched(FixTreeCompleted::class);
        Event::assertNotDispatched(FixAggregatesCompleted::class);
        Event::assertNotDispatched(FixAggregatesChunkCompleted::class);
        Event::assertNotDispatched(FixAggregatesJobDispatched::class);
        Event::assertNotDispatched(BulkInsertTreeCompleted::class);
        Event::assertNotDispatched(DeferredAggregateMaintenanceCompleted::class);
        Event::assertNotDispatched(NodeMoved::class);
    }
}
