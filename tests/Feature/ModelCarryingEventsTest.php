<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Events\AggregateDriftDetected;
use Vusys\NestedSet\Events\BulkInsertNodeSaved;
use Vusys\NestedSet\Events\BulkInsertTreeCompleted;
use Vusys\NestedSet\Events\BulkInsertTreePlanned;
use Vusys\NestedSet\Events\BulkInsertTreeSaved;
use Vusys\NestedSet\Events\BulkInsertTreeStarting;
use Vusys\NestedSet\Events\DeferredMaintenanceStarting;
use Vusys\NestedSet\Events\NodeAggregatesRecomputed;
use Vusys\NestedSet\Events\NodePromotedToRoot;
use Vusys\NestedSet\Events\NodesSwapped;
use Vusys\NestedSet\Events\ScopeViolationDetected;
use Vusys\NestedSet\Events\SoftDeleteMarkerCaptured;
use Vusys\NestedSet\Events\SubtreeForceDeleted;
use Vusys\NestedSet\Events\SubtreeForceDeleting;
use Vusys\NestedSet\Events\SubtreeMoved;
use Vusys\NestedSet\Events\SubtreeMoving;
use Vusys\NestedSet\Events\SubtreeRestored;
use Vusys\NestedSet\Events\SubtreeRestoring;
use Vusys\NestedSet\Events\SubtreeSoftDeleted;
use Vusys\NestedSet\Events\SubtreeSoftDeleting;
use Vusys\NestedSet\Events\TreeIntegrityChecked;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Coverage for events that carry live model instances (or descendant
 * ids gathered for queue-safe handoff). The complement to the existing
 * {@see EventsTest} which only covers scalar/telemetry events.
 */
final class ModelCarryingEventsTest extends TestCase
{
    protected bool $allowBrokenTreeAtTearDown = true;

    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    private function rootIdOf(Area|Category $node): int
    {
        $key = $node->getKey();
        if (! is_numeric($key)) {
            $this->fail('expected numeric id');
        }

        return (int) $key;
    }

    // ================================================================
    // Bulk insert lifecycle
    // ================================================================

    public function test_bulk_insert_emits_full_lifecycle_in_order(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Event::fake([
            BulkInsertTreeStarting::class,
            BulkInsertTreePlanned::class,
            BulkInsertNodeSaved::class,
            BulkInsertTreeSaved::class,
            BulkInsertTreeCompleted::class,
        ]);

        $tree = [
            ['name' => 'A', 'tickets' => 1, 'children' => [
                ['name' => 'A1', 'tickets' => 2],
            ]],
            ['name' => 'B', 'tickets' => 3],
        ];

        Area::bulkInsertTree($tree, appendTo: $root);

        Event::assertDispatchedTimes(BulkInsertTreeStarting::class, 1);
        Event::assertDispatched(BulkInsertTreeStarting::class, function (BulkInsertTreeStarting $e) use ($root, $tree): bool {
            $this->assertSame(Area::class, $e->modelClass);
            $this->assertSame($root->getKey(), $e->appendTo?->getKey());
            $this->assertSame($tree, $e->tree);

            return true;
        });

        Event::assertDispatchedTimes(BulkInsertTreePlanned::class, 1);
        Event::assertDispatched(BulkInsertTreePlanned::class, function (BulkInsertTreePlanned $e): bool {
            $this->assertCount(3, $e->plan);
            $this->assertSame('A', $e->plan[0]['attributes']['name']);
            $this->assertSame('A1', $e->plan[1]['attributes']['name']);
            $this->assertSame('B', $e->plan[2]['attributes']['name']);
            $this->assertSame(0, $e->plan[1]['parentPlanIndex']);
            $this->assertNull($e->plan[0]['parentPlanIndex']);

            return true;
        });

        Event::assertDispatchedTimes(BulkInsertNodeSaved::class, 3);
        /** @var array<int, BulkInsertNodeSaved> $bulkNodeEvents */
        $bulkNodeEvents = [];
        Event::assertDispatched(BulkInsertNodeSaved::class, function (BulkInsertNodeSaved $e) use (&$bulkNodeEvents): bool {
            $bulkNodeEvents[$e->planIndex] = $e;

            return true;
        });

        $this->assertCount(3, $bulkNodeEvents);
        $a1Event = $bulkNodeEvents[1];
        $this->assertSame('A1', $a1Event->node->getAttribute('name'));
        $parentOfA1 = $a1Event->parent;
        $this->assertNotNull($parentOfA1);
        $this->assertSame('A', $parentOfA1->getAttribute('name'));
        $this->assertNotSame($root->getKey(), $parentOfA1->getKey());

        Event::assertDispatchedTimes(BulkInsertTreeSaved::class, 1);
        Event::assertDispatched(BulkInsertTreeSaved::class, function (BulkInsertTreeSaved $e) use ($root): bool {
            $this->assertSame(Area::class, $e->modelClass);
            $this->assertSame($this->rootIdOf($root), $e->anchorId);
            $this->assertCount(3, $e->nodes);
            // DFS pre-order: A, A1, B.
            $this->assertSame('A', $e->nodes[0]->getAttribute('name'));
            $this->assertSame('A1', $e->nodes[1]->getAttribute('name'));
            $this->assertSame('B', $e->nodes[2]->getAttribute('name'));

            return true;
        });

        Event::assertDispatchedTimes(BulkInsertTreeCompleted::class, 1);
        Event::assertDispatched(BulkInsertTreeCompleted::class, function (BulkInsertTreeCompleted $e): bool {
            $this->assertCount(3, $e->nodeIds);
            foreach ($e->nodeIds as $id) {
                $this->assertIsInt($id);
            }

            return true;
        });
    }

    public function test_bulk_insert_starting_carries_null_appendto_for_new_roots(): void
    {
        Event::fake([BulkInsertTreeStarting::class]);

        Area::bulkInsertTree([
            ['name' => 'standalone', 'tickets' => 5],
        ]);

        Event::assertDispatched(BulkInsertTreeStarting::class, function (BulkInsertTreeStarting $e): bool {
            $this->assertNull($e->appendTo);

            return true;
        });
    }

    public function test_bulk_insert_emits_nothing_for_empty_input(): void
    {
        Event::fake([
            BulkInsertTreeStarting::class,
            BulkInsertTreePlanned::class,
            BulkInsertTreeCompleted::class,
        ]);

        $result = Area::bulkInsertTree([]);

        $this->assertSame([], $result);
        Event::assertNotDispatched(BulkInsertTreeStarting::class);
        Event::assertNotDispatched(BulkInsertTreePlanned::class);
        Event::assertNotDispatched(BulkInsertTreeCompleted::class);
    }

    // ================================================================
    // Cascade events
    // ================================================================

    private function seedSoftDeleteTree(): Category
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();

        $a1 = new Category(['name' => 'A1']);
        $a1->appendToNode($a->refresh())->save();

        return $root->refresh();
    }

    public function test_subtree_soft_delete_pair_fires_with_descendant_ids(): void
    {
        $this->seedSoftDeleteTree();
        $a = Category::query()->where('name', 'A')->firstOrFail();

        /** @var list<SubtreeSoftDeleting> $deleting */
        $deleting = [];
        /** @var list<SubtreeSoftDeleted> $deleted */
        $deleted = [];
        Event::listen(SubtreeSoftDeleting::class, function (SubtreeSoftDeleting $e) use (&$deleting): void {
            $deleting[] = $e;
        });
        Event::listen(SubtreeSoftDeleted::class, function (SubtreeSoftDeleted $e) use (&$deleted): void {
            $deleted[] = $e;
        });

        $a->refresh()->delete();

        $this->assertCount(1, $deleting);
        $this->assertSame(Category::class, $deleting[0]->modelClass);
        $this->assertSame($a->getKey(), $deleting[0]->anchor->getKey());
        $this->assertNotSame('', $deleting[0]->deletedAt);

        $this->assertCount(1, $deleted);
        // A1 is the sole descendant of A.
        $this->assertCount(1, $deleted[0]->descendantIds);
    }

    public function test_subtree_restore_pair_fires_with_descendant_ids(): void
    {
        $this->seedSoftDeleteTree();
        $a = Category::query()->where('name', 'A')->firstOrFail();
        $a->refresh()->delete();

        /** @var list<SubtreeRestoring> $restoring */
        $restoring = [];
        /** @var list<SubtreeRestored> $restored */
        $restored = [];
        /** @var list<SoftDeleteMarkerCaptured> $markers */
        $markers = [];
        Event::listen(SubtreeRestoring::class, function (SubtreeRestoring $e) use (&$restoring): void {
            $restoring[] = $e;
        });
        Event::listen(SubtreeRestored::class, function (SubtreeRestored $e) use (&$restored): void {
            $restored[] = $e;
        });
        Event::listen(SoftDeleteMarkerCaptured::class, function (SoftDeleteMarkerCaptured $e) use (&$markers): void {
            $markers[] = $e;
        });

        $restoredAnchor = Category::withTrashed()->where('id', $a->getKey())->firstOrFail();
        $restoredAnchor->restore();

        $this->assertCount(1, $markers);
        $this->assertCount(1, $restoring);
        $this->assertNotSame('', $restoring[0]->marker);
        $this->assertCount(1, $restored);
        $this->assertCount(1, $restored[0]->descendantIds);
    }

    public function test_subtree_force_delete_pair_fires_with_descendant_ids(): void
    {
        $root = new Category(['name' => 'r']);
        $root->saveAsRoot();
        $a = new Category(['name' => 'a']);
        $a->appendToNode($root->refresh())->save();
        $a1 = new Category(['name' => 'a1']);
        $a1->appendToNode($a->refresh())->save();
        $a2 = new Category(['name' => 'a2']);
        $a2->appendToNode($a->refresh())->save();

        /** @var list<SubtreeForceDeleting> $forceDeleting */
        $forceDeleting = [];
        /** @var list<SubtreeForceDeleted> $forceDeleted */
        $forceDeleted = [];
        Event::listen(SubtreeForceDeleting::class, function (SubtreeForceDeleting $e) use (&$forceDeleting): void {
            $forceDeleting[] = $e;
        });
        Event::listen(SubtreeForceDeleted::class, function (SubtreeForceDeleted $e) use (&$forceDeleted): void {
            $forceDeleted[] = $e;
        });

        $a->refresh()->forceDelete();

        $this->assertCount(1, $forceDeleting);
        // a1 + a2 in the about-to-delete list.
        $this->assertCount(2, $forceDeleting[0]->descendantIds);

        $this->assertCount(1, $forceDeleted);
        $this->assertSame(2, $forceDeleted[0]->descendantsAffected);
    }

    // ================================================================
    // Subtree movement
    // ================================================================

    private function seedMoveTree(): Area
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $a = new Area(['name' => 'A', 'tickets' => 1]);
        $a->appendToNode($root->refresh())->save();
        $b = new Area(['name' => 'B', 'tickets' => 2]);
        $b->appendToNode($root->refresh())->save();
        $c = new Area(['name' => 'C', 'tickets' => 3]);
        $c->appendToNode($root->refresh())->save();

        return $root->refresh();
    }

    public function test_subtree_moving_and_subtree_moved_bracket_a_move(): void
    {
        $this->seedMoveTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $b = Area::query()->where('name', 'B')->firstOrFail();

        Event::fake([SubtreeMoving::class, SubtreeMoved::class]);

        $a->appendToNode($b->refresh())->save();

        Event::assertDispatchedTimes(SubtreeMoving::class, 1);
        Event::assertDispatched(SubtreeMoving::class, function (SubtreeMoving $e) use ($a): bool {
            $this->assertSame($a->getKey(), $e->anchor->getKey());
            $this->assertSame('appendTo', $e->operation);

            return true;
        });

        Event::assertDispatchedTimes(SubtreeMoved::class, 1);
        Event::assertDispatched(SubtreeMoved::class, function (SubtreeMoved $e) use ($a): bool {
            $this->assertSame($a->getKey(), $e->anchor->getKey());
            $this->assertSame('appendTo', $e->operation);
            $this->assertNotEquals($e->fromBounds, $e->toBounds);
            // A is a leaf in this seed, so no strict descendants.
            $this->assertSame([], $e->descendantIds);

            return true;
        });
    }

    public function test_subtree_moved_includes_descendant_ids_for_interior_moves(): void
    {
        $this->seedMoveTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $a1 = new Area(['name' => 'A1', 'tickets' => 10]);
        $a1->appendToNode($a->refresh())->save();
        $a2 = new Area(['name' => 'A2', 'tickets' => 20]);
        $a2->appendToNode($a->refresh())->save();

        $b = Area::query()->where('name', 'B')->firstOrFail();

        /** @var list<SubtreeMoved> $moved */
        $moved = [];
        Event::listen(SubtreeMoved::class, function (SubtreeMoved $e) use (&$moved): void {
            $moved[] = $e;
        });

        $a->refresh()->appendToNode($b->refresh())->save();

        $this->assertCount(1, $moved);
        // A1 + A2 should be reported as descendants.
        $this->assertCount(2, $moved[0]->descendantIds);
    }

    public function test_node_promoted_to_root_fires_only_for_makeroot(): void
    {
        $this->seedMoveTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $previousParent = $a->getParentId();
        $previousDepth = $a->getDepth();

        Event::fake([NodePromotedToRoot::class]);

        $a->makeRoot()->save();

        Event::assertDispatchedTimes(NodePromotedToRoot::class, 1);
        Event::assertDispatched(NodePromotedToRoot::class, function (NodePromotedToRoot $e) use ($a, $previousParent, $previousDepth): bool {
            $this->assertSame($a->getKey(), $e->anchor->getKey());
            $this->assertSame($previousParent, $e->previousParentId);
            $this->assertSame($previousDepth, $e->previousDepth);

            return true;
        });
    }

    public function test_node_promoted_to_root_does_not_fire_for_other_operations(): void
    {
        $this->seedMoveTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $b = Area::query()->where('name', 'B')->firstOrFail();

        Event::fake([NodePromotedToRoot::class]);

        $a->appendToNode($b->refresh())->save();

        Event::assertNotDispatched(NodePromotedToRoot::class);
    }

    public function test_nodes_swapped_fires_for_up_and_down(): void
    {
        $this->seedMoveTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $b = Area::query()->where('name', 'B')->firstOrFail();

        Event::fake([NodesSwapped::class]);

        $b->refresh()->up();

        Event::assertDispatchedTimes(NodesSwapped::class, 1);
        Event::assertDispatched(NodesSwapped::class, function (NodesSwapped $e) use ($a, $b): bool {
            $this->assertSame($b->getKey(), $e->movedNode->getKey());
            $this->assertSame($a->getKey(), $e->displacedSibling->getKey());
            $this->assertSame('up', $e->direction);

            return true;
        });

        Event::fake([NodesSwapped::class]);
        $b->refresh()->down();

        Event::assertDispatched(NodesSwapped::class, function (NodesSwapped $e): bool {
            $this->assertSame('down', $e->direction);

            return true;
        });
    }

    // ================================================================
    // Observability
    // ================================================================

    public function test_node_aggregates_recomputed_fires_on_create_when_aggregates_declared(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();

        Event::fake([NodeAggregatesRecomputed::class]);

        $child = new Area(['name' => 'C', 'tickets' => 5]);
        $child->appendToNode($root->refresh())->save();

        Event::assertDispatched(NodeAggregatesRecomputed::class, function (NodeAggregatesRecomputed $e) use ($child): bool {
            $this->assertSame('on_create', $e->stage);
            $this->assertSame($child->getKey(), $e->nodeId);
            $this->assertContains('tickets_total', $e->columns);

            return true;
        });
    }

    public function test_node_aggregates_recomputed_does_not_fire_for_models_without_aggregates(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        Event::fake([NodeAggregatesRecomputed::class]);

        $child = new Category(['name' => 'C']);
        $child->appendToNode($root->refresh())->save();

        Event::assertNotDispatched(NodeAggregatesRecomputed::class);
    }

    public function test_aggregate_drift_detected_fires_only_when_drift_exists(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        Event::fake([AggregateDriftDetected::class]);
        Area::aggregateErrors();
        Event::assertNotDispatched(AggregateDriftDetected::class);

        // Corrupt the rolled-up tickets_total to introduce drift.
        DB::table('areas')
            ->where('id', $root->getKey())
            ->update(['tickets_total' => 999]);

        Event::fake([AggregateDriftDetected::class]);
        Area::aggregateErrors();
        Event::assertDispatched(AggregateDriftDetected::class, function (AggregateDriftDetected $e): bool {
            $this->assertGreaterThan(0, $e->totalDrift);
            $this->assertNotEmpty($e->perColumn);

            return true;
        });
    }

    public function test_tree_integrity_checked_fires_on_every_check(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();

        Event::fake([TreeIntegrityChecked::class]);

        Area::isBroken();
        Area::countErrors();

        Event::assertDispatchedTimes(TreeIntegrityChecked::class, 2);
        Event::assertDispatched(TreeIntegrityChecked::class, function (TreeIntegrityChecked $e): bool {
            $this->assertArrayHasKey('invalid_bounds', $e->errors);
            $this->assertArrayHasKey('duplicate_lft', $e->errors);

            return true;
        });
    }

    public function test_deferred_maintenance_starting_pairs_with_completed(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Event::fake([DeferredMaintenanceStarting::class]);

        Area::withDeferredAggregateMaintenance(function (): void {
            // empty
        }, $root);

        Event::assertDispatchedTimes(DeferredMaintenanceStarting::class, 1);
        Event::assertDispatched(DeferredMaintenanceStarting::class, function (DeferredMaintenanceStarting $e) use ($root): bool {
            $this->assertSame($this->rootIdOf($root), $e->anchorId);

            return true;
        });
    }

    public function test_deferred_maintenance_starting_only_fires_for_outermost(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Event::fake([DeferredMaintenanceStarting::class]);

        Area::withDeferredAggregateMaintenance(function () use ($root): void {
            Area::withDeferredAggregateMaintenance(function (): void {
                // nested
            }, $root);
        }, $root);

        Event::assertDispatchedTimes(DeferredMaintenanceStarting::class, 1);
    }

    public function test_throwing_deferred_maintenance_listener_does_not_leak_depth(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        // A listener that throws on the opening boundary would, before
        // the fix, leak self::$deferredDepth and disable aggregate
        // maintenance for the rest of the process. Run two consecutive
        // wrappers — the second one's per-row aggregate hooks must
        // still fire after the first one's listener throws.
        Event::listen(DeferredMaintenanceStarting::class, function (): never {
            throw new \RuntimeException('listener boom');
        });

        try {
            Area::withDeferredAggregateMaintenance(function (): void {
                // empty
            }, $root);
            $this->fail('expected the listener to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('listener boom', $e->getMessage());
        }

        // Detach the throwing listener so the second wrapper's opening
        // boundary doesn't blow up again — what we're testing is that
        // the counter recovered, not that the listener stops throwing.
        Event::forget(DeferredMaintenanceStarting::class);

        // A normal create inside withDeferredAggregateMaintenance: the
        // closing fixAggregates must run, proving the depth counter
        // came back to zero.
        Area::withDeferredAggregateMaintenance(function () use ($root): void {
            $child = new Area(['name' => 'leaf', 'tickets' => 7]);
            $child->appendToNode($root->refresh())->save();
        }, $root);

        $rolledUp = $root->refresh()->getAttribute('tickets_total');
        $this->assertIsNumeric($rolledUp);
        $this->assertSame(7, (int) $rolledUp);
    }

    public function test_scope_violation_detected_fires_before_exception(): void
    {
        Event::fake([ScopeViolationDetected::class]);

        try {
            MenuItem::isBroken();
            $this->fail('expected ScopeViolationException');
        } catch (ScopeViolationException) {
            // expected
        }

        Event::assertDispatched(ScopeViolationDetected::class, function (ScopeViolationDetected $e): bool {
            $this->assertSame(MenuItem::class, $e->modelClass);
            $this->assertSame('repair', $e->stage);

            return true;
        });
    }

    // ================================================================
    // events_enabled = false suppresses every new event
    // ================================================================

    public function test_new_events_are_suppressed_when_telemetry_disabled(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        config(['nestedset.events_enabled' => false]);

        Event::fake([
            BulkInsertTreeStarting::class,
            BulkInsertTreePlanned::class,
            BulkInsertNodeSaved::class,
            BulkInsertTreeSaved::class,
            SubtreeMoving::class,
            SubtreeMoved::class,
            NodesSwapped::class,
            NodeAggregatesRecomputed::class,
            TreeIntegrityChecked::class,
            DeferredMaintenanceStarting::class,
        ]);

        Area::bulkInsertTree([['name' => 'x', 'tickets' => 1]], appendTo: $root);
        $x = Area::query()->where('name', 'x')->firstOrFail();
        $x->appendToNode($root->refresh())->save();
        Area::isBroken();
        Area::withDeferredAggregateMaintenance(function (): void {
            // empty
        }, $root);

        Event::assertNotDispatched(BulkInsertTreeStarting::class);
        Event::assertNotDispatched(BulkInsertTreePlanned::class);
        Event::assertNotDispatched(BulkInsertNodeSaved::class);
        Event::assertNotDispatched(BulkInsertTreeSaved::class);
        Event::assertNotDispatched(SubtreeMoving::class);
        Event::assertNotDispatched(SubtreeMoved::class);
        Event::assertNotDispatched(NodeAggregatesRecomputed::class);
        Event::assertNotDispatched(TreeIntegrityChecked::class);
        Event::assertNotDispatched(DeferredMaintenanceStarting::class);
    }
}
