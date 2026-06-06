<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Repair;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Phase P: synchronous chunked entry point on `fixAggregates()`.
 *
 * The async counterpart (FixAggregatesJob with chunkSize) already
 * covers production drift-recovery on huge trees; Phase P adds a sync
 * loop so a CLI command can stream progress to stdout without going
 * through the queue.
 */
final class SyncChunkedFixAggregatesTest extends TestCase
{
    /**
     * Build a small tree with $count nodes via the standard
     * appendToNode path. Each node has tickets=1 so drift is easy to
     * spot in aggregate totals.
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

    private function corruptAllAggregates(): void
    {
        DB::table('areas')->update([
            'tickets_total' => 0,
            'tickets_count_all' => 0,
            'tickets_avg' => null,
            'tickets_min' => null,
            'tickets_max' => null,
            'tickets_avg__sum' => 0,
            'tickets_avg__count' => 0,
        ]);
    }

    #[Test]
    public function chunked_loop_repairs_a_drifted_tree(): void
    {
        $this->seedAreaTree(7);
        $this->corruptAllAggregates();
        $this->assertTrue(Area::aggregatesAreBroken());

        $result = Area::fixAggregates(chunkSize: 3);

        $this->assertInstanceOf(AggregateFixResult::class, $result);
        $this->assertGreaterThan(0, $result->totalRowsUpdated);
        $this->assertFalse(Area::aggregatesAreBroken(), 'every chunk repaired');
    }

    #[Test]
    public function returned_result_aggregates_per_chunk_totals(): void
    {
        $this->seedAreaTree(5);
        $this->corruptAllAggregates();

        $perChunkSum = 0;
        $result = Area::fixAggregates(
            chunkSize: 2,
            onChunk: function (AggregateFixResult $chunkResult) use (&$perChunkSum): void {
                $perChunkSum += $chunkResult->totalRowsUpdated;
            },
        );

        $this->assertSame(
            $perChunkSum,
            $result->totalRowsUpdated,
            'aggregate result.totalRowsUpdated == sum of per-chunk totals',
        );
    }

    #[Test]
    public function on_chunk_callback_receives_result_index_and_cursor(): void
    {
        $this->seedAreaTree(6);
        $this->corruptAllAggregates();

        $captured = [];
        Area::fixAggregates(
            chunkSize: 2,
            onChunk: function (AggregateFixResult $r, int $i, ?int $cursor) use (&$captured): void {
                $captured[] = ['index' => $i, 'cursor' => $cursor, 'rows' => $r->totalRowsUpdated];
            },
        );

        $indices = array_column($captured, 'index');
        $this->assertSame(range(0, count($captured) - 1), $indices, 'indices are sequential from 0');

        // The final invocation receives cursor=null — we've reached the end.
        $finalEntry = $captured[count($captured) - 1];
        $this->assertNull($finalEntry['cursor'], 'final chunk emits a null cursor');

        // Intermediate cursors must be non-null and strictly increasing.
        $intermediates = array_slice(array_column($captured, 'cursor'), 0, -1);
        foreach ($intermediates as $cursor) {
            $this->assertNotNull($cursor);
        }
        $sorted = $intermediates;
        sort($sorted);
        $this->assertSame($sorted, $intermediates, 'cursors advance monotonically');
    }

    #[Test]
    public function chunked_loop_on_clean_tree_is_a_noop_per_chunk(): void
    {
        $this->seedAreaTree(4);
        $this->assertFalse(Area::aggregatesAreBroken());

        $totalsPerChunk = [];
        $result = Area::fixAggregates(
            chunkSize: 2,
            onChunk: function (AggregateFixResult $r) use (&$totalsPerChunk): void {
                $totalsPerChunk[] = $r->totalRowsUpdated;
            },
        );

        $this->assertSame(0, $result->totalRowsUpdated);
        foreach ($totalsPerChunk as $perChunk) {
            $this->assertSame(0, $perChunk, 'every chunk on a clean tree updates 0 rows');
        }
    }

    #[Test]
    public function chunked_loop_short_circuits_on_empty_table(): void
    {
        $this->assertSame(0, DB::table('areas')->count(), 'baseline: empty table');

        $invoked = 0;
        $result = Area::fixAggregates(
            chunkSize: 10,
            onChunk: function () use (&$invoked): void {
                $invoked++;
            },
        );

        $this->assertSame(0, $result->totalRowsUpdated);
        // One chunk fires (it returns zero ids → nextAfterId=null → loop
        // ends). The callback gets called once with a zero-row result.
        $this->assertSame(1, $invoked);
    }

    #[Test]
    public function chunked_path_with_chunk_size_zero_falls_back_to_one_shot(): void
    {
        $this->seedAreaTree(3);
        $this->corruptAllAggregates();

        $callbackHit = false;
        Area::fixAggregates(
            chunkSize: 0,
            onChunk: function () use (&$callbackHit): void {
                $callbackHit = true;
            },
        );

        $this->assertFalse(
            $callbackHit,
            'chunkSize=0 means "no chunking" — falls back to one-shot fixAggregates(), callback never fires',
        );
        $this->assertFalse(Area::aggregatesAreBroken(), 'one-shot path still repaired the tree');
    }

    #[Test]
    public function low_level_fix_aggregates_chunk_rejects_a_non_positive_chunk_size(): void
    {
        // The public `fixAggregates(chunkSize: 0)` wrapper treats 0 as
        // "no chunking", but the lower-level cursor entry point requires
        // a real page size — a zero/negative chunk would never advance.
        $this->seedAreaTree(2);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chunkSize must be > 0');

        Area::fixAggregatesChunk(null, null, 0);
    }

    #[Test]
    public function fix_aggregates_chunk_throws_when_anchor_row_is_missing(): void
    {
        // A queued chunk job picked up minutes after dispatch can find
        // its anchor gone (hard-delete between dispatch and execution).
        // The pre-fix code silently widened the chunk to every row in
        // scope — a one-subtree repair turned into a whole-table sweep.
        $this->seedAreaTree(3);
        $root = Area::query()->whereIsRoot()->firstOrFail();

        // Hard-delete the anchor row out from under the chunk call,
        // then invoke the low-level chunk entry point directly with
        // the now-stale anchor.
        DB::table('areas')->where('id', $root->id)->delete();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/anchor id .* not found/');

        Area::fixAggregatesChunk($root, null, 100);
    }
}
