<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Functions;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Phase E maintenance tests: AVG aggregate stays correct as nodes are
 * created, source columns updated, and nodes deleted. AVG is derived
 * from internal SUM and COUNT companions auto-promoted by the registry
 * (Phase A) and maintained as deltas (Phase D); this phase adds the
 * `display_col = (sum + Δsum) / NULLIF(count + Δcount, 0)` clause to
 * the same UPDATE so AVG settles on its mathematical answer atomically
 * with its inputs.
 */
final class AvgMaintenanceTest extends TestCase
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

    private function asFloat(mixed $value): float
    {
        if ($value === null || ! is_numeric($value)) {
            $this->fail('Expected numeric, got '.get_debug_type($value));
        }

        return (float) $value;
    }

    // ----------------------------------------------------------------
    // AVG on insert
    // ----------------------------------------------------------------

    #[Test]
    public function avg_on_root_after_insertion_equals_self_value(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();
        $root->refresh();

        $this->assertEqualsWithDelta(100.0, $this->asFloat($root->tickets_avg), 0.0001);
    }

    #[Test]
    public function avg_for_motivating_example_tree(): void
    {
        // Root(100) > A(50) > A1(50); Root > B(25). AGGREGATES.md §1.
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 50]);
        $a1->appendToNode($a->refresh())->save();

        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();

        $root->refresh();
        $a->refresh();
        $a1->refresh();
        $b->refresh();

        // Inclusive AVGs from the doc's worked example.
        $this->assertEqualsWithDelta(225 / 4, $this->asFloat($root->tickets_avg), 0.0001);
        $this->assertEqualsWithDelta(100 / 2, $this->asFloat($a->tickets_avg), 0.0001);
        $this->assertEqualsWithDelta(50, $this->asFloat($a1->tickets_avg), 0.0001);
        $this->assertEqualsWithDelta(25, $this->asFloat($b->tickets_avg), 0.0001);
    }

    #[Test]
    public function avg_companions_are_maintained_alongside_user_declarations(): void
    {
        // Area's user-declared `tickets_total` (SUM(tickets)) already
        // satisfies the AVG's sum-companion requirement, so the registry
        // does NOT auto-promote a `tickets_avg__sum`. The user's
        // `tickets_count_all` is COUNT(*) (source = null), which does
        // NOT match AVG's source=tickets, so a `tickets_avg__count`
        // companion IS auto-promoted. Phase E must maintain it.
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $row = DB::table('areas')->where('id', $root->id)->first();
        $this->assertNotNull($row);

        // tickets_total drives the AVG sum half (already covered by
        // DeltaMaintenanceTest; re-asserted here as the precondition).
        $this->assertSame(150, $this->asInt($row->tickets_total));

        // tickets_avg__count is the auto-promoted COUNT(tickets) companion.
        $this->assertSame(2, $this->asInt($row->tickets_avg__count));

        // The unused tickets_avg__sum column stays at its default. Reserved
        // by the migration for forward compatibility but never written when
        // the user's SUM declaration already covers the source column.
        $this->assertSame(0, $this->asInt($row->tickets_avg__sum));
    }

    // ----------------------------------------------------------------
    // AVG on source-column update
    // ----------------------------------------------------------------

    #[Test]
    public function avg_recomputes_on_source_update(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        // Sanity before mutation: root avg = (100+50)/2 = 75.
        $this->assertEqualsWithDelta(75.0, $this->asFloat($root->refresh()->tickets_avg), 0.0001);

        $a->refresh();
        $a->tickets = 100; // +50
        $a->save();

        // After: root avg = (100+100)/2 = 100, child avg = 100.
        $this->assertEqualsWithDelta(100.0, $this->asFloat($root->refresh()->tickets_avg), 0.0001);
        $this->assertEqualsWithDelta(100.0, $this->asFloat($a->refresh()->tickets_avg), 0.0001);
    }

    #[Test]
    public function avg_recomputes_when_source_goes_to_zero(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a->refresh();
        $a->tickets = 0;
        $a->save();

        // Root avg = (100+0)/2 = 50; child avg = 0.
        $this->assertEqualsWithDelta(50.0, $this->asFloat($root->refresh()->tickets_avg), 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->asFloat($a->refresh()->tickets_avg), 0.0001);
    }

    // ----------------------------------------------------------------
    // AVG on delete
    // ----------------------------------------------------------------

    #[Test]
    public function avg_recomputes_on_delete(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();

        // Root avg = (100+50+25)/3 = 58.333…
        $this->assertEqualsWithDelta(175 / 3, $this->asFloat($root->refresh()->tickets_avg), 0.0001);

        $b->refresh();
        $b->forceDelete();

        // Root avg now = (100+50)/2 = 75.
        $this->assertEqualsWithDelta(75.0, $this->asFloat($root->refresh()->tickets_avg), 0.0001);
    }

    // ----------------------------------------------------------------
    // Stored vs fresh AVG: drift check across mixed batch
    // ----------------------------------------------------------------

    #[Test]
    public function stored_avg_matches_fresh_after_mixed_batch(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 30]);
        $a1->appendToNode($a->refresh())->save();

        $a1->refresh();
        $a1->tickets = 40;
        $a1->save();

        $b->refresh();
        $b->forceDelete();

        foreach (Area::query()->orderBy('lft')->get() as $node) {
            $stored = $this->asFloat($node->tickets_avg);
            $fresh = $this->asFloat($node->freshAggregate('tickets_avg'));

            $this->assertEqualsWithDelta(
                $fresh,
                $stored,
                0.0001,
                "AVG drift on node {$node->id} ({$node->name}): stored={$stored}, fresh={$fresh}",
            );
        }
    }

    // ----------------------------------------------------------------
    // Single-UPDATE invariant (Phase D promise still holds)
    // ----------------------------------------------------------------

    #[Test]
    public function appending_a_leaf_still_fires_exactly_one_extra_aggregate_update(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $extraUpdates = $this->countAggregateUpdates(function () use ($root): void {
            $a = new Area(['name' => 'A', 'tickets' => 50]);
            $a->appendToNode($root)->save();
        });

        $this->assertSame(1, $extraUpdates, 'AVG should ride the same UPDATE as the SUM/COUNT deltas');
    }

    /**
     * Identical heuristic to DeltaMaintenanceTest::countAggregateUpdates.
     */
    private function countAggregateUpdates(callable $operation): int
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
            $lower = strtolower((string) $entry['query']);
            if (! str_starts_with($lower, 'update')) {
                continue;
            }
            if (! str_contains($lower, 'areas')) {
                continue;
            }

            if (str_contains($lower, 'tickets_total') || str_contains($lower, 'tickets_count_all')) {
                $count++;
            }
        }

        return $count;
    }
}
