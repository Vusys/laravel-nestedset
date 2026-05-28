<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Integrity;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Contracts\AggregateDefinitionContract;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Tree shape used throughout these tests, matching the motivating
 * example from AGGREGATES.md §1:
 *
 *  Root  tickets=100  lft=1  rgt=8  depth=0
 *  ├── A      tickets= 50  lft=2  rgt=5  depth=1
 *  │   └── A1 tickets= 50  lft=3  rgt=4  depth=2
 *  └── B      tickets= 25  lft=6  rgt=7  depth=1
 *
 * Inclusive subtree expectations:
 *   Root: SUM=225 COUNT=4 AVG=56.25 MIN=25 MAX=100
 *   A:    SUM=100 COUNT=2 AVG=50    MIN=50 MAX= 50
 *   A1:   SUM= 50 COUNT=1 AVG=50    MIN=50 MAX= 50
 *   B:    SUM= 25 COUNT=1 AVG=25    MIN=25 MAX= 25
 */
final class FreshAggregateReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();

        DB::table('areas')->insert([
            ['id' => 1, 'name' => 'Root', 'tickets' => 100, 'lft' => 1, 'rgt' => 8, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'tickets' => 50, 'lft' => 2, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'A1',   'tickets' => 50, 'lft' => 3, 'rgt' => 4, 'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'B',    'tickets' => 25, 'lft' => 6, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
        ]);
    }

    /**
     * Narrows the `mixed` return of `freshAggregate()` / `getAttribute()`
     * to int without an unchecked cast. Aggregate values come back as
     * int from MySQL/SQLite, string from PostgreSQL — `is_numeric` +
     * cast covers both.
     */
    private function asInt(mixed $value): int
    {
        if ($value === null) {
            $this->fail('Expected numeric, got null.');
        }
        if (! is_numeric($value)) {
            $this->fail('Expected numeric, got '.get_debug_type($value));
        }

        return (int) $value;
    }

    private function asFloat(mixed $value): float
    {
        if ($value === null) {
            $this->fail('Expected numeric, got null.');
        }
        if (! is_numeric($value)) {
            $this->fail('Expected numeric, got '.get_debug_type($value));
        }

        return (float) $value;
    }

    // ----------------------------------------------------------------
    // freshAggregate() — single-node scalar
    // ----------------------------------------------------------------

    public function test_fresh_aggregate_sum_at_root_matches_motivating_example(): void
    {
        $root = Area::query()->where('id', 1)->firstOrFail();

        $this->assertSame(225, $this->asInt($root->freshAggregate('tickets_total')));
    }

    public function test_fresh_aggregate_sum_at_intermediate_node(): void
    {
        $a = Area::query()->where('id', 2)->firstOrFail();

        $this->assertSame(100, $this->asInt($a->freshAggregate('tickets_total')));
    }

    public function test_fresh_aggregate_sum_at_leaf_equals_own_tickets(): void
    {
        $b = Area::query()->where('id', 4)->firstOrFail();

        $this->assertSame(25, $this->asInt($b->freshAggregate('tickets_total')));
    }

    public function test_fresh_aggregate_count_includes_self(): void
    {
        $this->assertSame(4, $this->asInt(Area::query()->findOrFail(1)->freshAggregate('tickets_count_all')));
        $this->assertSame(2, $this->asInt(Area::query()->findOrFail(2)->freshAggregate('tickets_count_all')));
        $this->assertSame(1, $this->asInt(Area::query()->findOrFail(3)->freshAggregate('tickets_count_all')));
        $this->assertSame(1, $this->asInt(Area::query()->findOrFail(4)->freshAggregate('tickets_count_all')));
    }

    public function test_fresh_aggregate_avg_at_root(): void
    {
        $root = Area::query()->where('id', 1)->firstOrFail();

        $this->assertEqualsWithDelta(56.25, $this->asFloat($root->freshAggregate('tickets_avg')), 0.0001);
    }

    public function test_fresh_aggregate_min_at_root(): void
    {
        $root = Area::query()->where('id', 1)->firstOrFail();

        $this->assertSame(25, $this->asInt($root->freshAggregate('tickets_min')));
    }

    public function test_fresh_aggregate_max_at_root(): void
    {
        $root = Area::query()->where('id', 1)->firstOrFail();

        $this->assertSame(100, $this->asInt($root->freshAggregate('tickets_max')));
    }

    public function test_fresh_aggregate_on_undeclared_column_throws(): void
    {
        $root = Area::query()->where('id', 1)->firstOrFail();

        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('no aggregate column "nonexistent"');

        $root->freshAggregate('nonexistent');
    }

    public function test_fresh_aggregate_resolves_internal_avg_companion_columns(): void
    {
        // Area declares `tickets_avg` (AVG over tickets) alongside an
        // explicit `tickets_total` SUM over the same source — so the
        // registry reuses the SUM and only auto-promotes the COUNT
        // companion (`tickets_avg__count`). No matching user-declared
        // COUNT over `tickets` exists.
        //
        // Documents the current "leaky" surface: getAggregateDefinitions
        // hides these columns from the public list (they're an
        // implementation detail), but freshAggregate() reads them
        // because resolveDefinitionByColumn iterates the full registry.
        // Callers who reach for these names get a working value rather
        // than an exception. Pin that contract until F9's redesign
        // either formalises or hides it.
        $root = Area::query()->where('id', 1)->firstOrFail();

        // COUNT(*) over the inclusive subtree = 4.
        $this->assertSame(4, $this->asInt($root->freshAggregate('tickets_avg__count')));

        // ...and yet getAggregateDefinitions() does NOT list it.
        $publicColumns = array_map(
            static fn (AggregateDefinitionContract $d): string => $d->getColumn(),
            $root->getAggregateDefinitions(),
        );
        $this->assertNotContains('tickets_avg__count', $publicColumns,
            'internal companion stays out of the public definition list',
        );

        // The matching SUM was reused from `tickets_total`, so no
        // `tickets_avg__sum` companion was registered. freshAggregate()
        // for that name throws — proving the leak only exposes columns
        // the registry actually created.
        $this->expectException(AggregateConfigurationException::class);
        $root->freshAggregate('tickets_avg__sum');
    }

    // ----------------------------------------------------------------
    // withFreshAggregates() — query-level
    // ----------------------------------------------------------------

    public function test_with_fresh_aggregates_no_args_selects_every_user_facing_aggregate(): void
    {
        $root = Area::query()->withFreshAggregates()->where('id', 1)->firstOrFail();

        $this->assertSame(225, $this->asInt($root->tickets_total));
        $this->assertSame(4, $this->asInt($root->tickets_count_all));
        $this->assertEqualsWithDelta(56.25, $this->asFloat($root->tickets_avg), 0.0001);
        $this->assertSame(25, $this->asInt($root->tickets_min));
        $this->assertSame(100, $this->asInt($root->tickets_max));
    }

    public function test_with_fresh_aggregates_with_explicit_column_list(): void
    {
        $root = Area::query()
            ->withFreshAggregates(['tickets_total'])
            ->where('id', 1)
            ->firstOrFail();

        $this->assertSame(225, $this->asInt($root->tickets_total));
    }

    public function test_with_fresh_aggregates_yields_correct_values_for_each_node(): void
    {
        /** @var array<int, int> $totals */
        $totals = Area::query()
            ->withFreshAggregates()
            ->orderBy('lft')
            ->get()
            ->mapWithKeys(fn (Area $a): array => [$a->id => $this->asInt($a->tickets_total)])
            ->all();

        /** @var array<int, int> $maxes */
        $maxes = Area::query()
            ->withFreshAggregates(['tickets_max'])
            ->orderBy('lft')
            ->get()
            ->mapWithKeys(fn (Area $a): array => [$a->id => $this->asInt($a->tickets_max)])
            ->all();

        $this->assertSame([1 => 225, 2 => 100, 3 => 50, 4 => 25], $totals);
        $this->assertSame([1 => 100, 2 => 50, 3 => 50, 4 => 25], $maxes);
    }

    public function test_with_fresh_aggregates_overlays_stored_value_when_aliases_match(): void
    {
        // Hand-corrupt the stored value to differ from the source-of-truth.
        DB::table('areas')->where('id', 1)->update(['tickets_total' => 999]);

        $rawStored = $this->asInt(DB::table('areas')->where('id', 1)->value('tickets_total'));
        $this->assertSame(999, $rawStored, 'sanity: stored value updated');

        $rootFresh = Area::query()
            ->withFreshAggregates(['tickets_total'])
            ->where('id', 1)
            ->firstOrFail();

        $this->assertSame(225, $this->asInt($rootFresh->tickets_total), 'fresh overlays stored');
    }

    public function test_save_after_with_fresh_aggregates_does_not_write_fresh_back_to_stored_column(): void
    {
        // Hand-corrupt the stored value: 999 is the drifted value the DB
        // holds, 225 is the source-of-truth that withFreshAggregates
        // recomputes. The overlay aliases fresh under the stored
        // column name, so PDO collapses both into a single attribute
        // holding the fresh value. The model's $original is set from
        // the same hydration, so dirty tracking does not flag
        // tickets_total. A subsequent save() emits no UPDATE for that
        // column and the drift persists in the DB.
        //
        // This pins the documented "read-only snapshot" contract for
        // withFreshAggregates() (see TreeQueryBuilder::withFreshAggregates
        // docblock). A future PR may move fresh selects under a `_fresh`
        // suffix by default; until then, this test guards the current
        // behaviour so it does not change unintentionally.
        DB::table('areas')->where('id', 1)->update(['tickets_total' => 999]);

        $rootFresh = Area::query()
            ->withFreshAggregates(['tickets_total'])
            ->where('id', 1)
            ->firstOrFail();

        // In-memory view shows the fresh value, not the stored value.
        $this->assertSame(225, $this->asInt($rootFresh->tickets_total));

        // Mutate an unrelated column and save — emulates a user calling
        // save() on a model that was loaded with fresh aggregates.
        $rootFresh->name = 'Renamed';
        $rootFresh->save();

        // Stored value untouched: save() did not propagate the fresh
        // overlay back. Repair still needs fixAggregates().
        $rawStoredAfterSave = $this->asInt(DB::table('areas')->where('id', 1)->value('tickets_total'));
        $this->assertSame(999, $rawStoredAfterSave, 'fresh value did not leak into the stored column');
    }

    // ----------------------------------------------------------------
    // Ad-hoc aggregates (Aggregate value object as query argument)
    // ----------------------------------------------------------------

    public function test_with_fresh_aggregates_accepts_ad_hoc_declarations(): void
    {
        $root = Area::query()
            ->withFreshAggregates([
                'subtree_tickets' => Aggregate::sum('tickets'),
                'subtree_count' => Aggregate::count(),
            ])
            ->where('id', 1)
            ->firstOrFail();

        $this->assertSame(225, $this->asInt($root->getAttribute('subtree_tickets')));
        $this->assertSame(4, $this->asInt($root->getAttribute('subtree_count')));
    }

    public function test_ad_hoc_alias_is_in_memory_only_and_dropped_on_refresh(): void
    {
        // Aliases that don't match a declared aggregate column live
        // only on the in-memory model — they are not persisted via
        // save(), and refresh() drops them because there is no
        // schema column to read back.
        $root = Area::query()
            ->withFreshAggregates([
                'descendants_total' => Aggregate::sum('tickets')->exclusive(),
            ])
            ->where('id', 1)
            ->firstOrFail();

        $this->assertSame(125, $this->asInt($root->getAttribute('descendants_total')));

        $root->save();

        $reloaded = Area::query()->findOrFail(1);
        $this->assertNull(
            $reloaded->getAttribute('descendants_total'),
            'ad-hoc alias must not be persisted to a non-existent column',
        );

        // Same model in memory after refresh: the alias is gone.
        $root->refresh();
        $this->assertNull(
            $root->getAttribute('descendants_total'),
            'refresh() drops in-memory-only aliases',
        );
    }

    public function test_with_fresh_aggregates_supports_mixed_declared_and_ad_hoc(): void
    {
        $root = Area::query()
            ->withFreshAggregates([
                'tickets_total',
                'tickets_max',
                'descendants_total' => Aggregate::sum('tickets')->exclusive(),
            ])
            ->where('id', 1)
            ->firstOrFail();

        $this->assertSame(225, $this->asInt($root->tickets_total));
        $this->assertSame(100, $this->asInt($root->tickets_max));
        $this->assertSame(125, $this->asInt($root->getAttribute('descendants_total'))); // 50+50+25
    }

    public function test_with_fresh_aggregates_rejects_unkeyed_aggregate_instance(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('must be keyed by a string column alias');

        Area::query()
            ->withFreshAggregates([Aggregate::sum('tickets')])
            ->get();
    }

    public function test_with_fresh_aggregates_rejects_undeclared_column_name(): void
    {
        $this->expectException(AggregateConfigurationException::class);

        Area::query()
            ->withFreshAggregates(['ghost_column'])
            ->get();
    }

    // ----------------------------------------------------------------
    // Exclusive aggregation
    // ----------------------------------------------------------------

    public function test_exclusive_sum_is_descendants_only(): void
    {
        $root = Area::query()
            ->withFreshAggregates([
                'descendants_total' => Aggregate::sum('tickets')->exclusive(),
            ])
            ->where('id', 1)
            ->firstOrFail();

        $this->assertSame(125, $this->asInt($root->getAttribute('descendants_total')));
    }

    public function test_exclusive_sum_on_a_leaf_is_zero(): void
    {
        $b = Area::query()
            ->withFreshAggregates([
                'descendants_total' => Aggregate::sum('tickets')->exclusive(),
            ])
            ->where('id', 4)
            ->firstOrFail();

        $this->assertSame(0, $this->asInt($b->getAttribute('descendants_total')));
    }

    public function test_exclusive_min_on_a_leaf_is_null(): void
    {
        $b = Area::query()
            ->withFreshAggregates([
                'descendants_min' => Aggregate::min('tickets')->exclusive(),
            ])
            ->where('id', 4)
            ->firstOrFail();

        $this->assertNull($b->getAttribute('descendants_min'));
    }

    // ----------------------------------------------------------------
    // Leaf fast-path — locks in semantics. Leaves have `rgt = lft + 1`
    // and skip the LATERAL/derived join in favour of an inline CASE
    // branch that reads from the source column directly. Every value
    // the fast-path produces has to match what the slow path would
    // have computed for the same row.
    // ----------------------------------------------------------------

    public function test_leaf_fast_path_inclusive_count_returns_one(): void
    {
        // Leaf B (id=4) inclusive COUNT(*) = 1 (the leaf is its own subtree).
        $b = Area::query()
            ->withFreshAggregates(['tickets_count_all'])
            ->where('id', 4)
            ->firstOrFail();

        $this->assertSame(1, $this->asInt($b->tickets_count_all));
    }

    public function test_leaf_fast_path_inclusive_avg_returns_source_value(): void
    {
        // Leaf B (id=4) inclusive AVG(tickets) = 25 / 1 = 25.
        $b = Area::query()
            ->withFreshAggregates(['tickets_avg'])
            ->where('id', 4)
            ->firstOrFail();

        $this->assertSame(25.0, $this->asFloat($b->tickets_avg));
    }

    public function test_leaf_fast_path_inclusive_min_max_returns_source_value(): void
    {
        $b = Area::query()
            ->withFreshAggregates(['tickets_min', 'tickets_max'])
            ->where('id', 4)
            ->firstOrFail();

        $this->assertSame(25, $this->asInt($b->tickets_min));
        $this->assertSame(25, $this->asInt($b->tickets_max));
    }

    public function test_mixed_result_set_leaves_and_internals_each_get_correct_value(): void
    {
        // One query returning the whole tree — internal nodes go through
        // the join, leaves through the inline branch. The hydrated
        // attribute must match per-row regardless of which branch fired.
        /** @var array<int, array{total: int, count: int, min: int, max: int}> $byId */
        $byId = Area::query()
            ->withFreshAggregates()
            ->orderBy('lft')
            ->get()
            ->mapWithKeys(fn (Area $a): array => [$a->id => [
                'total' => $this->asInt($a->tickets_total),
                'count' => $this->asInt($a->tickets_count_all),
                'min' => $this->asInt($a->tickets_min),
                'max' => $this->asInt($a->tickets_max),
            ]])
            ->all();

        // Root (internal): 100+50+50+25=225, count 4, min 25, max 100
        $this->assertSame(['total' => 225, 'count' => 4, 'min' => 25, 'max' => 100], $byId[1]);
        // A (internal): 50+50=100, count 2, min 50, max 50
        $this->assertSame(['total' => 100, 'count' => 2, 'min' => 50, 'max' => 50], $byId[2]);
        // A1 (leaf via fast-path): own tickets=50, count 1
        $this->assertSame(['total' => 50, 'count' => 1, 'min' => 50, 'max' => 50], $byId[3]);
        // B (leaf via fast-path): own tickets=25, count 1
        $this->assertSame(['total' => 25, 'count' => 1, 'min' => 25, 'max' => 25], $byId[4]);
    }

    public function test_leaf_fast_path_with_zero_tickets(): void
    {
        // Edge case: a leaf with tickets = 0 should still report
        // SUM = 0, COUNT = 1 (not 0), AVG = 0, MIN = MAX = 0.
        // Distinguishes "subtree is empty" (COUNT=0, AVG=NULL) from
        // "subtree has one node valued zero" (COUNT=1, AVG=0).
        DB::table('areas')->where('id', 4)->update(['tickets' => 0]);

        $b = Area::query()
            ->withFreshAggregates()
            ->where('id', 4)
            ->firstOrFail();

        $this->assertSame(0, $this->asInt($b->tickets_total));
        $this->assertSame(1, $this->asInt($b->tickets_count_all));
        $this->assertSame(0.0, $this->asFloat($b->tickets_avg));
        $this->assertSame(0, $this->asInt($b->tickets_min));
        $this->assertSame(0, $this->asInt($b->tickets_max));
    }

    public function test_leaf_fast_path_ad_hoc_count_with_source_returns_one(): void
    {
        // Counting a specific column (not COUNT(*)) at the leaf:
        // `1` when the source is non-null, `0` when null. Tests the
        // CASE WHEN source IS NULL THEN 0 ELSE 1 END branch.
        $b = Area::query()
            ->withFreshAggregates([
                'tickets_count_source' => Aggregate::count('tickets'),
            ])
            ->where('id', 4)
            ->firstOrFail();

        $this->assertSame(1, $this->asInt($b->getAttribute('tickets_count_source')));
    }

    // ----------------------------------------------------------------
    // getAggregateDefinitions()
    // ----------------------------------------------------------------

    public function test_get_aggregate_definitions_returns_only_user_facing_declarations(): void
    {
        $area = new Area;
        $definitions = $area->getAggregateDefinitions();

        $columns = array_map(static fn (AggregateDefinitionContract $d): string => $d->getColumn(), $definitions);

        $this->assertContains('tickets_total', $columns);
        $this->assertContains('tickets_count_all', $columns);
        $this->assertContains('tickets_avg', $columns);
        $this->assertContains('tickets_min', $columns);
        $this->assertContains('tickets_max', $columns);

        // Internal AVG companions exist in the registry but are excluded here.
        $this->assertNotContains('tickets_avg'.AggregateRegistry::AVG_SUM_SUFFIX, $columns);
        $this->assertNotContains('tickets_avg'.AggregateRegistry::AVG_COUNT_SUFFIX, $columns);
    }
}
