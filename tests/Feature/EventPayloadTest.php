<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Vusys\NestedSet\Events\BulkInsertTreeCompleted;
use Vusys\NestedSet\Events\NodeMoved;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Goes deeper into event payload correctness than {@see EventsTest}.
 * Covers every {@see NodeMoved} `operation` variant
 * (appendTo / prependTo / sibling / root) and exercises
 * {@see BulkInsertTreeCompleted}'s payload shapes that
 * `EventsTest::test_bulk_insert_tree_fires_completion_event` doesn't
 * reach (rows-inserted = 0, no-anchor → anchorId null).
 */
final class EventPayloadTest extends TestCase
{
    private function rootIdOf(Area $node): int
    {
        $key = $node->getKey();
        if (! is_numeric($key)) {
            $this->fail('expected numeric id');
        }

        return (int) $key;
    }

    private function seedTree(): Area
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $a = new Area(['name' => 'A', 'tickets' => 1]);
        $a->appendToNode($root)->save();

        $b = new Area(['name' => 'B', 'tickets' => 2]);
        $b->appendToNode($root->refresh())->save();

        $c = new Area(['name' => 'C', 'tickets' => 3]);
        $c->appendToNode($root->refresh())->save();

        return $root->refresh();
    }

    // ================================================================
    // NodeMoved — one test per operation variant
    // ================================================================

    public function test_node_moved_fires_for_prepend_to_node(): void
    {
        $this->seedTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $b = Area::query()->where('name', 'B')->firstOrFail();

        Event::fake([NodeMoved::class]);

        $a->prependToNode($b->refresh())->save();

        Event::assertDispatched(NodeMoved::class, function (NodeMoved $e) use ($a): bool {
            $this->assertSame('prependTo', $e->operation);
            $this->assertSame($this->rootIdOf($a), $e->nodeId);
            $this->assertSame(Area::class, $e->modelClass);

            return true;
        });
    }

    public function test_node_moved_fires_for_insert_before_node(): void
    {
        $this->seedTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $c = Area::query()->where('name', 'C')->firstOrFail();

        Event::fake([NodeMoved::class]);

        // Move A to immediately before C (still siblings under root).
        $a->insertBeforeNode($c->refresh())->save();

        Event::assertDispatched(NodeMoved::class, function (NodeMoved $e): bool {
            $this->assertSame('sibling', $e->operation);

            return true;
        });
    }

    public function test_node_moved_fires_for_insert_after_node(): void
    {
        $this->seedTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $b = Area::query()->where('name', 'B')->firstOrFail();

        Event::fake([NodeMoved::class]);

        $a->insertAfterNode($b->refresh())->save();

        Event::assertDispatched(NodeMoved::class, function (NodeMoved $e): bool {
            $this->assertSame('sibling', $e->operation);

            return true;
        });
    }

    public function test_up_fires_node_moved_for_both_self_and_swapped_sibling(): void
    {
        $this->seedTree();
        $b = Area::query()->where('name', 'B')->firstOrFail();
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $bId = $this->rootIdOf($b);
        $aId = $this->rootIdOf($a);

        Event::fake([NodeMoved::class]);

        // B.up() should swap with A (its previous sibling).
        $b->up();

        Event::assertDispatched(NodeMoved::class, fn(NodeMoved $e): bool => $e->nodeId === $bId && $e->operation === 'sibling');
        Event::assertDispatched(NodeMoved::class, fn(NodeMoved $e): bool => $e->nodeId === $aId && $e->operation === 'sibling-displaced');
    }

    public function test_down_fires_node_moved_for_both_self_and_swapped_sibling(): void
    {
        $this->seedTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $b = Area::query()->where('name', 'B')->firstOrFail();
        $aId = $this->rootIdOf($a);
        $bId = $this->rootIdOf($b);

        Event::fake([NodeMoved::class]);

        $a->down();

        Event::assertDispatched(NodeMoved::class, fn(NodeMoved $e): bool => $e->nodeId === $aId && $e->operation === 'sibling');
        Event::assertDispatched(NodeMoved::class, fn(NodeMoved $e): bool => $e->nodeId === $bId && $e->operation === 'sibling-displaced');
    }

    public function test_node_moved_fires_for_make_root(): void
    {
        $this->seedTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();

        Event::fake([NodeMoved::class]);

        $a->makeRoot()->save();

        Event::assertDispatched(NodeMoved::class, function (NodeMoved $e) use ($a): bool {
            $this->assertSame('root', $e->operation);
            $this->assertSame($this->rootIdOf($a), $e->nodeId);

            return true;
        });
    }

    public function test_node_moved_payload_reflects_pre_and_post_bounds(): void
    {
        $this->seedTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $b = Area::query()->where('name', 'B')->firstOrFail();
        $preLft = $a->lft;
        $preRgt = $a->rgt;

        Event::fake([NodeMoved::class]);

        $a->appendToNode($b->refresh())->save();

        Event::assertDispatched(NodeMoved::class, function (NodeMoved $e) use ($preLft, $preRgt): bool {
            $this->assertSame($preLft, $e->fromBounds->lft, 'fromBounds.lft = pre-move lft');
            $this->assertSame($preRgt, $e->fromBounds->rgt, 'fromBounds.rgt = pre-move rgt');
            $this->assertNotSame($e->fromBounds->lft, $e->toBounds->lft, 'toBounds differ');
            $this->assertGreaterThan(0.0, $e->durationMs, 'durationMs is positive');

            return true;
        });
    }

    // ================================================================
    // BulkInsertTreeCompleted — payload edge cases
    // ================================================================

    public function test_bulk_insert_completion_event_carries_null_anchor_for_new_roots(): void
    {
        Event::fake([BulkInsertTreeCompleted::class]);

        Area::bulkInsertTree([
            ['name' => 'r1', 'tickets' => 1],
            ['name' => 'r2', 'tickets' => 2],
        ]);

        Event::assertDispatched(BulkInsertTreeCompleted::class, function (BulkInsertTreeCompleted $e): bool {
            $this->assertNull($e->anchorId, 'no anchor → anchorId is null');
            $this->assertSame(2, $e->rowsInserted);

            return true;
        });
    }

    public function test_bulk_insert_completion_event_does_not_fire_for_empty_input(): void
    {
        // bulkInsertTree([]) returns early — no event.
        Event::fake([BulkInsertTreeCompleted::class]);

        Area::bulkInsertTree([]);

        Event::assertNotDispatched(BulkInsertTreeCompleted::class);
    }

    public function test_bulk_insert_completion_event_carries_anchor_id_when_anchored(): void
    {
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();
        $rootId = $this->rootIdOf($root);

        Event::fake([BulkInsertTreeCompleted::class]);

        Area::bulkInsertTree([
            ['name' => 'x', 'tickets' => 1],
        ], appendTo: $root);

        Event::assertDispatched(BulkInsertTreeCompleted::class, function (BulkInsertTreeCompleted $e) use ($rootId): bool {
            $this->assertSame($rootId, $e->anchorId);
            $this->assertSame(1, $e->rowsInserted);

            return true;
        });
    }
}
