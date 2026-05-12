<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
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
    // getAggregateDefinitions()
    // ----------------------------------------------------------------

    public function test_get_aggregate_definitions_returns_only_user_facing_declarations(): void
    {
        $area = new Area;
        $definitions = $area->getAggregateDefinitions();

        $columns = array_map(static fn (AggregateDefinition $d): string => $d->column, $definitions);

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
