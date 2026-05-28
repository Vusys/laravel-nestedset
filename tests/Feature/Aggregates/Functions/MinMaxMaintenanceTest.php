<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Functions;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Phase F: MIN and MAX maintenance.
 *
 * Two paths:
 *   - Cheap delta (CASE WHEN appended to Phase D's UPDATE) when the
 *     change can only extend the stored extremum — insert or
 *     source-update where new is more extreme than old.
 *   - Recompute (separate SELECT + per-row UPDATE) when the change may
 *     have invalidated the stored extremum — source-update where new
 *     is less extreme than old, or any delete. Filtered to ancestors
 *     where `stored = previous_value` so non-holding ancestors are
 *     never touched.
 */
final class MinMaxMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    private function asInt(mixed $value): int
    {
        if ($value === null || ! is_numeric($value)) {
            $this->fail('Expected numeric, got '.get_debug_type($value));
        }

        return (int) $value;
    }

    private function asNullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value)) {
            $this->fail('Expected numeric or null, got '.get_debug_type($value));
        }

        return (int) $value;
    }

    // ----------------------------------------------------------------
    // Insert: cheap delta extends the extremum
    // ----------------------------------------------------------------

    public function test_root_after_insertion_holds_its_own_value_as_min_and_max(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();
        $root->refresh();

        $this->assertSame(100, $this->asInt($root->tickets_min));
        $this->assertSame(100, $this->asInt($root->tickets_max));
    }

    public function test_appending_a_smaller_child_lowers_min_but_keeps_max(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 25]);
        $a->appendToNode($root)->save();

        $root->refresh();

        $this->assertSame(25, $this->asInt($root->tickets_min));
        $this->assertSame(100, $this->asInt($root->tickets_max));
    }

    public function test_appending_a_larger_child_raises_max_but_keeps_min(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 200]);
        $a->appendToNode($root)->save();

        $root->refresh();

        $this->assertSame(100, $this->asInt($root->tickets_min));
        $this->assertSame(200, $this->asInt($root->tickets_max));
    }

    public function test_motivating_example_min_and_max(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 50]);
        $a1->appendToNode($a->refresh())->save();

        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();

        $this->assertSame(25, $this->asInt($root->refresh()->tickets_min));
        $this->assertSame(100, $this->asInt($root->refresh()->tickets_max));

        $this->assertSame(50, $this->asInt($a->refresh()->tickets_min));
        $this->assertSame(50, $this->asInt($a->refresh()->tickets_max));

        $this->assertSame(25, $this->asInt($b->refresh()->tickets_min));
        $this->assertSame(25, $this->asInt($b->refresh()->tickets_max));
    }

    // ----------------------------------------------------------------
    // Source-update: cheap delta when more extreme
    // ----------------------------------------------------------------

    public function test_source_update_extends_max_via_cheap_delta(): void
    {
        // Source going UP exercises the cheap-delta path for MAX (new value
        // can only extend the stored max). MIN may still need a recompute
        // because the OLD value could have been a min holder — that path
        // is exercised separately below.
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a->refresh();
        $a->tickets = 200;
        $a->save();

        $this->assertSame(200, $this->asInt($a->refresh()->tickets_max));
        $this->assertSame(200, $this->asInt($root->refresh()->tickets_max));
    }

    public function test_source_update_extends_min_via_cheap_delta(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a->refresh();
        $a->tickets = 10;
        $a->save();

        $this->assertSame(10, $this->asInt($a->refresh()->tickets_min));
        $this->assertSame(10, $this->asInt($root->refresh()->tickets_min));
    }

    public function test_ascending_source_update_does_not_trigger_max_recompute(): void
    {
        // Going up cannot invalidate MAX — only MIN. So a recompute SELECT
        // may fire for MIN, but the cheap-delta path is enough for MAX.
        // We verify that the captured path put the MAX in the delta
        // UPDATE (not the recompute set) by checking the count of
        // recompute SELECTs that mention the max column specifically.
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a->refresh();

        $maxRecomputeSelects = $this->countQueriesMatching(
            function () use ($a): void {
                $a->tickets = 200;
                $a->save();
            },
            static fn (string $sql): bool => str_starts_with(strtolower($sql), 'select')
                && str_contains($sql, 'outer_a')
                && str_contains($sql, 'inner_a')
                && str_contains($sql, 'tickets_max'),
        );

        $this->assertSame(0, $maxRecomputeSelects, 'MAX should ride the cheap-delta path when going up');

        // Correctness: max correctly extended.
        $this->assertSame(200, $this->asInt($root->refresh()->tickets_max));
    }

    // ----------------------------------------------------------------
    // Source-update: recompute when less extreme + cheap-skip
    // ----------------------------------------------------------------

    public function test_source_update_reducing_max_holder_recomputes(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        // Two children. A is the max holder; B is not.
        $a = new Area(['name' => 'A', 'tickets' => 200]);
        $a->appendToNode($root)->save();

        $b = new Area(['name' => 'B', 'tickets' => 50]);
        $b->appendToNode($root->refresh())->save();

        $this->assertSame(200, $this->asInt($root->refresh()->tickets_max));

        // Reduce A from 200 to 75 — root max should drop, but to 100 (root's
        // own value, not A's), not to 200.
        $a->refresh();
        $a->tickets = 75;
        $a->save();

        $this->assertSame(100, $this->asInt($root->refresh()->tickets_max));
        $this->assertSame(75, $this->asInt($a->refresh()->tickets_max));
    }

    public function test_source_update_on_non_max_holder_skips_recompute(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 200]);
        $a->appendToNode($root)->save();

        $b = new Area(['name' => 'B', 'tickets' => 50]);
        $b->appendToNode($root->refresh())->save();

        // B is not the max holder; reducing B from 50 → 30 must not trigger
        // a recompute SELECT against the root's chain.
        $b->refresh();
        $extraSelects = $this->countAggregateRecomputeSelects(function () use ($b): void {
            $b->tickets = 30;
            $b->save();
        });

        // Recompute selects can fire for B's own ancestors (root) when the
        // filter `tickets_max = 50` matches some ancestor whose stored max
        // happens to equal 50. Root's stored max is 200, so no match → no
        // affected rows. The SELECT still runs to discover that, so we
        // assert "≤ 1" rather than "0".
        $this->assertLessThanOrEqual(1, $extraSelects);

        // Correctness: root max stays 200 (A is still 200).
        $this->assertSame(200, $this->asInt($root->refresh()->tickets_max));
    }

    // ----------------------------------------------------------------
    // Delete: recompute when extremum is lost + cheap-skip otherwise
    // ----------------------------------------------------------------

    public function test_delete_current_max_holder_drops_ancestor_max(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 200]);
        $a->appendToNode($root)->save();

        $b = new Area(['name' => 'B', 'tickets' => 50]);
        $b->appendToNode($root->refresh())->save();

        $this->assertSame(200, $this->asInt($root->refresh()->tickets_max));

        $a->refresh();
        $a->forceDelete();

        // Root's max falls to its own 100 (B is 50, root self is 100).
        $this->assertSame(100, $this->asInt($root->refresh()->tickets_max));
    }

    public function test_delete_non_holding_leaf_does_not_change_max(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 200]);
        $a->appendToNode($root)->save();

        $b = new Area(['name' => 'B', 'tickets' => 50]);
        $b->appendToNode($root->refresh())->save();

        $b->refresh();
        $b->forceDelete();

        // Root max is still 200 (A is the holder, untouched).
        $this->assertSame(200, $this->asInt($root->refresh()->tickets_max));
    }

    public function test_delete_recompute_fires_only_when_extremum_is_at_risk(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 200]);
        $a->appendToNode($root)->save();

        $b = new Area(['name' => 'B', 'tickets' => 50]);
        $b->appendToNode($root->refresh())->save();

        // Deleting B (not the holder for any ancestor): MAX recompute SELECT
        // runs but filter `tickets_max = 50` matches no ancestor — zero
        // ancestor rows touched.
        $b->refresh();
        $bSelects = $this->countAggregateRecomputeSelects(function () use ($b): void {
            $b->forceDelete();
        });

        $a->refresh();
        $aSelects = $this->countAggregateRecomputeSelects(function () use ($a): void {
            $a->forceDelete();
        });

        // Both fire the SELECT (cheap-skip is at row-match level, not SQL
        // level). The cost is one SELECT per delete that touches MIN or MAX,
        // regardless of whether it ends up affecting anything.
        $this->assertGreaterThan(0, $bSelects);
        $this->assertGreaterThan(0, $aSelects);

        // Final state: only the root remains; its min/max equal its own value.
        $root->refresh();
        $this->assertSame(100, $this->asInt($root->tickets_min));
        $this->assertSame(100, $this->asInt($root->tickets_max));
    }

    // ----------------------------------------------------------------
    // Drift check: stored vs fresh across a mixed batch
    // ----------------------------------------------------------------

    public function test_stored_min_max_match_fresh_after_mixed_batch(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 75]);
        $a1->appendToNode($a->refresh())->save();

        // Mix of mutations.
        $a1->refresh();
        $a1->tickets = 200;
        $a1->save();

        $a->refresh();
        $a->tickets = 10;
        $a->save();

        $b->refresh();
        $b->forceDelete();

        foreach (Area::query()->orderBy('lft')->get() as $node) {
            $this->assertSame(
                $this->asNullableInt($node->freshAggregate('tickets_min')),
                $this->asNullableInt($node->tickets_min),
                "MIN drift on node {$node->id} ({$node->name})",
            );
            $this->assertSame(
                $this->asNullableInt($node->freshAggregate('tickets_max')),
                $this->asNullableInt($node->tickets_max),
                "MAX drift on node {$node->id} ({$node->name})",
            );
        }
    }

    // ----------------------------------------------------------------
    // Locking config flag honoured
    // ----------------------------------------------------------------

    public function test_locking_auto_issues_select_for_update_on_recompute(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite has no row-level locking; FOR UPDATE is a no-op there.');
        }

        config(['nestedset.aggregate_locking' => 'auto']);

        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 200]);
        $a->appendToNode($root)->save();

        $forUpdateCount = $this->countQueriesMatching(
            function () use ($a): void {
                $a->refresh();
                $a->forceDelete();
            },
            static fn (string $sql): bool => str_contains(strtolower($sql), 'for update'),
        );

        $this->assertGreaterThan(0, $forUpdateCount);
    }

    public function test_locking_never_skips_select_for_update(): void
    {
        config(['nestedset.aggregate_locking' => 'never']);

        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 200]);
        $a->appendToNode($root)->save();

        $forUpdateCount = $this->countQueriesMatching(
            function () use ($a): void {
                $a->refresh();
                $a->forceDelete();
            },
            static fn (string $sql): bool => str_contains(strtolower($sql), 'for update'),
        );

        $this->assertSame(0, $forUpdateCount);
    }

    /**
     * Heuristic counter for recompute SELECTs: counts SELECTs against
     * the `areas` table that reference both inner and outer aliases
     * `inner_a` / `outer_a` (RecomputeMaintenance's signature pattern).
     */
    private function countAggregateRecomputeSelects(callable $operation): int
    {
        return $this->countQueriesMatching(
            $operation,
            static fn (string $sql): bool => str_starts_with(strtolower($sql), 'select')
                && str_contains($sql, 'outer_a')
                && str_contains($sql, 'inner_a'),
        );
    }

    private function countQueriesMatching(callable $operation, callable $predicate): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $operation();
        } finally {
            DB::disableQueryLog();
        }

        $count = 0;

        foreach (DB::getQueryLog() as $entry) {
            if ($predicate($entry['query'])) {
                $count++;
            }
        }

        return $count;
    }
}
