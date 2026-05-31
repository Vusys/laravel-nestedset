<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Events;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Concerns\HasNestedSetAggregates;
use Vusys\NestedSet\Events\Aggregates\NestedSetAggregateChanged;
use Vusys\NestedSet\Events\Aggregates\NodeAggregatesRecomputed;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\SoftBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Coverage for the opt-in aggregate change-feed event
 * {@see NestedSetAggregateChanged}. Pairs the broader
 * {@see NodeAggregatesRecomputed} (per-mutation telemetry) with
 * per-row, per-column CDC-style diffs suitable for mirroring
 * aggregate values to Redis / Kafka / Reverb without polling.
 */
final class NestedSetAggregateChangedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    public function test_create_fires_one_event_per_ancestor_column_with_old_and_new_values(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();

        $this->fakeChangeFeed();

        $child = new Area(['name' => 'C', 'tickets' => 5]);
        $child->appendToNode($root->refresh())->save();

        // Root and self both gain tickets_total = 5, tickets_count_all = 1,
        // tickets_min = 5, tickets_max = 5, tickets_avg = 5.0 — five columns
        // times two rows = 10 events.
        $events = $this->collectChangeEvents();
        $this->assertNotEmpty($events);

        $rootTotal = $this->findEvent($events, $root->getKey(), 'tickets_total');
        $this->assertSame(0, (int) $rootTotal->oldValue);
        $this->assertSame(5, (int) $rootTotal->newValue);
        $this->assertSame('on_create', $rootTotal->stage);

        $childTotal = $this->findEvent($events, $child->getKey(), 'tickets_total');
        $this->assertSame(0, (int) $childTotal->oldValue);
        $this->assertSame(5, (int) $childTotal->newValue);

        // Ancestor chain is identical across every event from this mutation.
        $this->assertSame($rootTotal->ancestorChain, $childTotal->ancestorChain);
        $this->assertContains($root->getKey(), $rootTotal->ancestorChain);
        $this->assertContains($child->getKey(), $rootTotal->ancestorChain);
    }

    public function test_source_column_update_fires_only_for_columns_that_actually_changed(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $child = new Area(['name' => 'C', 'tickets' => 5]);
        $child->appendToNode($root->refresh())->save();

        $this->fakeChangeFeed();

        // Bumping tickets by 2 changes tickets_total / tickets_count_all stays
        // the same (count didn't move). tickets_max moves 5 → 7; tickets_min
        // moves 5 → 7 (only one node). tickets_avg moves 5 → 7.
        $child->refresh();
        $child->tickets = 7;
        $child->save();

        $events = $this->collectChangeEvents();
        $changedColumns = [];
        foreach ($events as $event) {
            $changedColumns[$event->column] = true;
        }

        $this->assertArrayHasKey('tickets_total', $changedColumns);
        $this->assertArrayHasKey('tickets_min', $changedColumns);
        $this->assertArrayHasKey('tickets_max', $changedColumns);
        $this->assertArrayHasKey('tickets_avg', $changedColumns);
        // count didn't move — no event for it.
        $this->assertArrayNotHasKey('tickets_count_all', $changedColumns);
    }

    public function test_delete_fires_with_oldvalue_greater_than_newvalue(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $child = new Area(['name' => 'C', 'tickets' => 10]);
        $child->appendToNode($root->refresh())->save();

        $this->fakeChangeFeed();

        // refresh() so the model's in-memory tickets_total reflects the
        // post-save aggregate (10) rather than the pre-save default (0)
        // — the delete handler reads stored aggregates off $this to
        // compute the delta.
        $child->refresh()->delete();

        $events = $this->collectChangeEvents();
        $rootTotal = $this->findEvent($events, $root->getKey(), 'tickets_total');
        $this->assertSame(10, (int) $rootTotal->oldValue);
        $this->assertSame(0, (int) $rootTotal->newValue);
        $this->assertSame('on_delete', $rootTotal->stage);

        // Self (the deleted row) should NOT appear in the event stream — its
        // row is gone and the consumer learns of the deletion via Eloquent.
        foreach ($events as $event) {
            $this->assertNotSame($child->getKey(), $event->nodeId);
        }
    }

    public function test_move_fires_two_passes_one_for_old_chain_one_for_new(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $branchA = new Area(['name' => 'A', 'tickets' => 0]);
        $branchA->appendToNode($root->refresh())->save();
        $branchB = new Area(['name' => 'B', 'tickets' => 0]);
        $branchB->appendToNode($root->refresh())->save();
        $leaf = new Area(['name' => 'Leaf', 'tickets' => 3]);
        $leaf->appendToNode($branchA->refresh())->save();

        $this->fakeChangeFeed();

        // Move leaf from under A to under B.
        $leaf->refresh();
        $leaf->appendToNode($branchB->refresh())->save();

        $events = $this->collectChangeEvents();

        // Branch A loses tickets_total = 3.
        $aTotal = $this->findEvent($events, $branchA->getKey(), 'tickets_total');
        $this->assertSame(3, (int) $aTotal->oldValue);
        $this->assertSame(0, (int) $aTotal->newValue);
        $this->assertSame('move', $aTotal->stage);

        // Branch B gains tickets_total = 3.
        $bTotal = $this->findEvent($events, $branchB->getKey(), 'tickets_total');
        $this->assertSame(0, (int) $bTotal->oldValue);
        $this->assertSame(3, (int) $bTotal->newValue);
        $this->assertSame('move', $bTotal->stage);

        // The two passes have different ancestor chains: A side contains A,
        // B side contains B; root is in both.
        $this->assertContains($branchA->getKey(), $aTotal->ancestorChain);
        $this->assertContains($branchB->getKey(), $bTotal->ancestorChain);
        $this->assertNotContains($branchB->getKey(), $aTotal->ancestorChain);
        $this->assertNotContains($branchA->getKey(), $bTotal->ancestorChain);
    }

    public function test_value_equality_uses_string_compare_for_large_64bit_integers(): void
    {
        // Two integers that exceed the 2^53 float-mantissa limit but
        // differ only in the low-order bits collapse to the same float
        // under a `(float)$a === (float)$b` comparison. The change-feed
        // diff must catch this so a one-unit move on a large SUM still
        // emits an event.
        $reflection = new \ReflectionMethod(
            HasNestedSetAggregates::class,
            'aggregateChangeFeedValuesEqual',
        );

        // 2^53 + 1 (the first integer PHP float cannot represent exactly).
        $small = '9007199254740992';
        $larger = '9007199254740993';

        $this->assertTrue($reflection->invoke(null, $small, $small));
        $this->assertFalse($reflection->invoke(null, $small, $larger));
        // Int-shaped string vs PHP int with the same value: still equal.
        $this->assertTrue($reflection->invoke(null, (int) $small, $small));
        // Driver-side leading-zero / sign formatting normalises away.
        $this->assertTrue($reflection->invoke(null, '00042', 42));
        $this->assertTrue($reflection->invoke(null, '-0', 0));
        // Float-valued aggregates (AVG / variance) still compare numerically.
        $this->assertTrue($reflection->invoke(null, 1.5, '1.5'));
        $this->assertFalse($reflection->invoke(null, 1.5, '1.500001'));
    }

    public function test_event_does_not_fire_when_no_listener_is_registered(): void
    {
        // Fake the event WITHOUT registering a listener first. The
        // firing site short-circuits when nobody is subscribed, so no
        // dispatch should happen even though the event is being faked.
        // Also asserts the snapshot SELECT is suppressed (no extra
        // queries with `ORDER BY lft DESC` over the aggregate columns).
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();

        Event::fake([NestedSetAggregateChanged::class]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $child = new Area(['name' => 'C', 'tickets' => 5]);
        $child->appendToNode($root->refresh())->save();

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        Event::assertNotDispatched(NestedSetAggregateChanged::class);

        foreach ($log as $entry) {
            $sql = strtolower((string) $entry['query']);
            $this->assertFalse(
                str_contains($sql, 'select') && str_contains($sql, 'tickets_total')
                && str_contains($sql, 'order by') && str_contains($sql, 'lft')
                && ! str_contains($sql, 'update'),
                'Change-feed snapshot SELECT fired despite no listener: '.$entry['query'],
            );
        }
    }

    public function test_event_does_not_fire_for_models_without_aggregates(): void
    {
        // Listener registered but model has no aggregates: the firing
        // site early-exits because $columns is empty before any work.
        $this->fakeChangeFeed();

        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $child = new Category(['name' => 'C']);
        $child->appendToNode($root->refresh())->save();

        Event::assertNotDispatched(NestedSetAggregateChanged::class);
    }

    public function test_event_payload_carries_modelclass_and_column_metadata(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();

        $this->fakeChangeFeed();

        $child = new Area(['name' => 'C', 'tickets' => 5]);
        $child->appendToNode($root->refresh())->save();

        $events = $this->collectChangeEvents();
        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertSame(Area::class, $event->modelClass);
            $this->assertNotSame('', $event->column);
            $this->assertContains($event->stage, ['on_create', 'on_update', 'on_delete', 'move', 'on_restore']);
        }
    }

    public function test_internal_companion_columns_are_excluded_from_events(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();

        $this->fakeChangeFeed();

        $child = new Area(['name' => 'C', 'tickets' => 5]);
        $child->appendToNode($root->refresh())->save();

        $events = $this->collectChangeEvents();
        foreach ($events as $event) {
            // AVG companions are named `<col>__sum` / `<col>__count`. They
            // exist on the migration but are an internal implementation
            // detail, never visible in events.
            $this->assertStringNotContainsString('__sum', $event->column);
            $this->assertStringNotContainsString('__count', $event->column);
        }
    }

    public function test_restore_fires_on_restore_stage(): void
    {
        $root = new SoftBranch(['name' => 'Root', 'tickets' => 0, 'active' => 1]);
        $root->saveAsRoot();
        $child = new SoftBranch(['name' => 'C', 'tickets' => 100, 'active' => 1]);
        $child->appendToNode($root->refresh())->save();
        $child->refresh();
        $child->delete();

        $this->fakeChangeFeed();

        $restored = SoftBranch::withTrashed()->where('id', $child->getKey())->firstOrFail();
        $restored->restore();

        $events = $this->collectChangeEvents();
        // At least one event with stage 'on_restore' (root regains the
        // child's revenue contribution).
        $stages = array_unique(array_map(static fn (NestedSetAggregateChanged $e): string => $e->stage, $events));
        $this->assertContains('on_restore', $stages);
    }

    /**
     * Registers a no-op listener so the firing site's
     * {@see EventDispatcher::hasListeners()} gate opens — the
     * change-feed event is opt-in by listener presence — then
     * fakes the event so PHPUnit can assert dispatches. The
     * order matters: `Event::fake()` forwards `hasListeners`
     * checks to the wrapped real dispatcher, which only returns
     * true when a listener has been registered on it.
     */
    private function fakeChangeFeed(): void
    {
        Event::fake([NestedSetAggregateChanged::class]);
        // Listener-presence gate: the firing site only does the
        // snapshot + dispatch work when at least one listener is
        // registered. EventFake forwards `hasListeners` to its
        // wrapped (real) dispatcher, so attach the no-op listener
        // there after faking.
        Event::listen(NestedSetAggregateChanged::class, static fn (NestedSetAggregateChanged $e): null => null);
    }

    /**
     * @return list<NestedSetAggregateChanged>
     */
    private function collectChangeEvents(): array
    {
        $collected = [];
        Event::assertDispatched(NestedSetAggregateChanged::class, function (NestedSetAggregateChanged $event) use (&$collected): bool {
            $collected[] = $event;

            return true;
        });

        return $collected;
    }

    /**
     * @param  list<NestedSetAggregateChanged>  $events
     */
    private function findEvent(array $events, mixed $nodeId, string $column): NestedSetAggregateChanged
    {
        if (! is_int($nodeId) && ! is_string($nodeId)) {
            $this->fail('Node id must be int or string for change-feed lookups.');
        }

        foreach ($events as $event) {
            if ($event->nodeId === $nodeId && $event->column === $column) {
                return $event;
            }
        }

        $this->fail(sprintf(
            'No NestedSetAggregateChanged event found for node %s column %s. Got: %s',
            $nodeId,
            $column,
            implode(', ', array_map(static fn (NestedSetAggregateChanged $e): string => $e->nodeId.':'.$e->column, $events)),
        ));
    }
}
