<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\TypedArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Tree shape used throughout these tests:
 *
 *  Root        tickets=10  type=null   lft=1  rgt=10  depth=0  parent=null
 *  ├── Fire1   tickets=20  type=fire   lft=2  rgt=3   depth=1  parent=Root
 *  ├── Fire2   tickets=30  type=fire   lft=4  rgt=7   depth=1  parent=Root
 *  │   └── WaterChild  tickets=15  type=water  lft=5  rgt=6  depth=2  parent=Fire2
 *  └── Water1  tickets=40  type=water  lft=8  rgt=9   depth=1  parent=Root
 */
final class FilteredFreshReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();

        DB::table('typed_areas')->insert([
            ['id' => 1, 'name' => 'Root',       'tickets' => 10, 'type' => null,    'lft' => 1,  'rgt' => 10, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'Fire1',      'tickets' => 20, 'type' => 'fire',  'lft' => 2,  'rgt' => 3,  'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'Fire2',      'tickets' => 30, 'type' => 'fire',  'lft' => 4,  'rgt' => 7,  'depth' => 1, 'parent_id' => 1],
            ['id' => 4, 'name' => 'WaterChild', 'tickets' => 15, 'type' => 'water', 'lft' => 5,  'rgt' => 6,  'depth' => 2, 'parent_id' => 3],
            ['id' => 5, 'name' => 'Water1',     'tickets' => 40, 'type' => 'water', 'lft' => 8,  'rgt' => 9,  'depth' => 1, 'parent_id' => 1],
        ]);

        $this->syncSequence('typed_areas');
    }

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

    public function test_filtered_sum_counts_only_matching_type(): void
    {
        $root = TypedArea::query()->withFreshAggregates()->where('id', 1)->firstOrFail();

        $this->assertSame(50, $this->asInt($root->fire_tickets));
    }

    public function test_filtered_count_counts_only_matching_rows(): void
    {
        $root = TypedArea::query()->withFreshAggregates()->where('id', 1)->firstOrFail();

        $this->assertSame(2, $this->asInt($root->fire_count));
    }

    public function test_filtered_max_returns_max_for_matching_type(): void
    {
        $root = TypedArea::query()->withFreshAggregates()->where('id', 1)->firstOrFail();

        $this->assertSame(40, $this->asInt($root->water_max));
    }

    public function test_filter_not_null_counts_non_null_source(): void
    {
        $root = TypedArea::query()->withFreshAggregates()->where('id', 1)->firstOrFail();

        $this->assertSame(5, $this->asInt($root->has_tickets));
    }

    public function test_leaf_node_filtered_sum(): void
    {
        $fire1 = TypedArea::query()->withFreshAggregates()->where('id', 2)->firstOrFail();

        $this->assertSame(20, $this->asInt($fire1->fire_tickets));
    }

    public function test_leaf_node_non_matching_type_filtered_sum(): void
    {
        $water1 = TypedArea::query()->withFreshAggregates()->where('id', 5)->firstOrFail();

        $this->assertSame(0, $this->asInt($water1->fire_tickets));
    }

    public function test_fresh_aggregate_scalar_with_filter(): void
    {
        $fire2 = TypedArea::query()->where('id', 3)->firstOrFail();

        $this->assertSame(30, $this->asInt($fire2->freshAggregate('fire_tickets')));
    }
}
