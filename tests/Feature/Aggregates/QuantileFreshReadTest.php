<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Query\TreeAggregateBuilder;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Tree shape used throughout these tests:
 *
 *  Root  tickets=100  lft=1  rgt=8  depth=0
 *  ├── A      tickets= 50  lft=2  rgt=5  depth=1
 *  │   └── A1 tickets= 50  lft=3  rgt=4  depth=2
 *  └── B      tickets= 25  lft=6  rgt=7  depth=1
 *
 * Inclusive subtree ticket sets:
 *   Root: {25, 50, 50, 100}   median=50, p25=43.75, p75=62.5
 *   A:    {50, 50}             median=50
 *   A1:   {50}                 median=50
 *   B:    {25}                 median=25
 *
 * Exclusive subtree ticket sets:
 *   Root: {25, 50, 50}         median=50
 *   A:    {50}                 median=50
 *   A1:   {}                   median=NULL
 *   B:    {}                   median=NULL
 */
final class QuantileFreshReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();

        DB::table('areas')->insert([
            ['id' => 1, 'name' => 'Root', 'tickets' => 100, 'lft' => 1, 'rgt' => 8, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'tickets' => 50,  'lft' => 2, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'A1',   'tickets' => 50,  'lft' => 3, 'rgt' => 4, 'depth' => 2, 'parent_id' => 2],
            ['id' => 4, 'name' => 'B',    'tickets' => 25,  'lft' => 6, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
        ]);

        $this->syncSequence('areas');
    }

    private function asFloat(mixed $value): float
    {
        $this->assertNotNull($value, 'Expected numeric value, got null.');
        $this->assertTrue(is_numeric($value), 'Expected numeric value, got '.get_debug_type($value).'.');

        return (float) $value;
    }

    // ----------------------------------------------------------------
    // median() — withFreshAggregates() row set
    // ----------------------------------------------------------------

    public function test_median_inclusive_at_root(): void
    {
        $root = Area::query()
            ->withFreshAggregates(['subtree_median' => Aggregate::median('tickets')])
            ->where('id', 1)
            ->firstOrFail();

        $this->assertEqualsWithDelta(50.0, $this->asFloat($root->getAttribute('subtree_median')), 0.0001);
    }

    public function test_median_inclusive_all_nodes(): void
    {
        $rows = Area::query()
            ->withFreshAggregates(['subtree_median' => Aggregate::median('tickets')])
            ->orderBy('lft')
            ->get()
            ->mapWithKeys(fn (Area $a): array => [$a->id => $a->getAttribute('subtree_median')])
            ->all();

        // Root: {25,50,50,100} → 50; A: {50,50} → 50; A1: {50} → 50; B: {25} → 25
        $this->assertEqualsWithDelta(50.0, $this->asFloat($rows[1]), 0.0001);
        $this->assertEqualsWithDelta(50.0, $this->asFloat($rows[2]), 0.0001);
        $this->assertEqualsWithDelta(50.0, $this->asFloat($rows[3]), 0.0001);
        $this->assertEqualsWithDelta(25.0, $this->asFloat($rows[4]), 0.0001);
    }

    public function test_median_exclusive_all_nodes(): void
    {
        $rows = Area::query()
            ->withFreshAggregates(['excl_median' => Aggregate::median('tickets')->exclusive()])
            ->orderBy('lft')
            ->get()
            ->mapWithKeys(fn (Area $a): array => [$a->id => $a->getAttribute('excl_median')])
            ->all();

        // Root: {50,50,25} → 50; A: {50} → 50; A1: {} → null; B: {} → null
        $this->assertEqualsWithDelta(50.0, $this->asFloat($rows[1]), 0.0001);
        $this->assertEqualsWithDelta(50.0, $this->asFloat($rows[2]), 0.0001);
        $this->assertNull($rows[3], 'exclusive leaf must be NULL');
        $this->assertNull($rows[4], 'exclusive leaf must be NULL');
    }

    // ----------------------------------------------------------------
    // percentile() — withFreshAggregates() row set
    // ----------------------------------------------------------------

    public function test_percentile_p25_at_root(): void
    {
        $root = Area::query()
            ->withFreshAggregates(['p25' => Aggregate::percentile('tickets', 0.25)])
            ->where('id', 1)
            ->firstOrFail();

        // {25,50,50,100}: k=0.75 → 0.25*25+0.75*50 = 43.75
        $this->assertEqualsWithDelta(43.75, $this->asFloat($root->getAttribute('p25')), 0.001);
    }

    public function test_percentile_p75_at_root(): void
    {
        $root = Area::query()
            ->withFreshAggregates(['p75' => Aggregate::percentile('tickets', 0.75)])
            ->where('id', 1)
            ->firstOrFail();

        // {25,50,50,100}: k=2.25 → 0.75*50+0.25*100 = 62.5
        $this->assertEqualsWithDelta(62.5, $this->asFloat($root->getAttribute('p75')), 0.001);
    }

    public function test_percentile_p0_returns_minimum(): void
    {
        $root = Area::query()
            ->withFreshAggregates(['p0' => Aggregate::percentile('tickets', 0.0)])
            ->where('id', 1)
            ->firstOrFail();

        $this->assertEqualsWithDelta(25.0, $this->asFloat($root->getAttribute('p0')), 0.001);
    }

    public function test_percentile_p1_returns_maximum(): void
    {
        $root = Area::query()
            ->withFreshAggregates(['p1' => Aggregate::percentile('tickets', 1.0)])
            ->where('id', 1)
            ->firstOrFail();

        $this->assertEqualsWithDelta(100.0, $this->asFloat($root->getAttribute('p1')), 0.001);
    }

    // ----------------------------------------------------------------
    // percentiles() and quartiles() convenience methods
    // ----------------------------------------------------------------

    public function test_percentiles_spreads_multiple_into_fresh_aggregates(): void
    {
        $root = Area::query()
            ->withFreshAggregates([...Aggregate::percentiles('tickets', ['p25' => 0.25, 'p50' => 0.5, 'p75' => 0.75])])
            ->where('id', 1)
            ->firstOrFail();

        $this->assertEqualsWithDelta(43.75, $this->asFloat($root->getAttribute('p25')), 0.001);
        $this->assertEqualsWithDelta(50.0, $this->asFloat($root->getAttribute('p50')), 0.001);
        $this->assertEqualsWithDelta(62.5, $this->asFloat($root->getAttribute('p75')), 0.001);
    }

    public function test_quartiles_returns_q1_median_q3(): void
    {
        $root = Area::query()
            ->withFreshAggregates([...Aggregate::quartiles('tickets')])
            ->where('id', 1)
            ->firstOrFail();

        $this->assertEqualsWithDelta(43.75, $this->asFloat($root->getAttribute('q1')), 0.001);
        $this->assertEqualsWithDelta(50.0, $this->asFloat($root->getAttribute('median')), 0.001);
        $this->assertEqualsWithDelta(62.5, $this->asFloat($root->getAttribute('q3')), 0.001);
    }

    // ----------------------------------------------------------------
    // TreeAggregateBuilder::scalar() — single-node read
    // ----------------------------------------------------------------

    public function test_scalar_median_at_root(): void
    {
        $root = Area::query()->findOrFail(1);
        $def = Aggregate::median('tickets')->into('m');

        $value = TreeAggregateBuilder::scalar($root, $def);

        $this->assertEqualsWithDelta(50.0, $this->asFloat($value), 0.0001);
    }

    public function test_scalar_median_at_leaf(): void
    {
        $b = Area::query()->findOrFail(4);
        $def = Aggregate::median('tickets')->into('m');

        $value = TreeAggregateBuilder::scalar($b, $def);

        $this->assertEqualsWithDelta(25.0, $this->asFloat($value), 0.0001);
    }

    public function test_scalar_median_exclusive_at_leaf_is_null(): void
    {
        $b = Area::query()->findOrFail(4);
        $def = Aggregate::median('tickets')->exclusive()->into('m');

        $value = TreeAggregateBuilder::scalar($b, $def);

        $this->assertNull($value);
    }

    public function test_scalar_percentile_p25_at_root(): void
    {
        $root = Area::query()->findOrFail(1);
        $def = Aggregate::percentile('tickets', 0.25)->into('p');

        $value = TreeAggregateBuilder::scalar($root, $def);

        $this->assertEqualsWithDelta(43.75, $this->asFloat($value), 0.001);
    }

    // ----------------------------------------------------------------
    // Leaf fast-path: leaf node is a single-element subtree
    // ----------------------------------------------------------------

    public function test_median_leaf_equals_its_own_source_value(): void
    {
        // A1 is a leaf (lft=3, rgt=4). Its inclusive subtree is {50}.
        // The leaf fast-path must return 50, not NULL.
        $a1 = Area::query()
            ->withFreshAggregates(['m' => Aggregate::median('tickets')])
            ->where('id', 3)
            ->firstOrFail();

        $this->assertEqualsWithDelta(50.0, $this->asFloat($a1->getAttribute('m')), 0.0001);
    }

    // ----------------------------------------------------------------
    // Validation — factory guards
    // ----------------------------------------------------------------

    public function test_median_rejects_empty_source(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('source column must not be empty');

        Aggregate::median('');
    }

    public function test_percentile_rejects_empty_source(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('source column must not be empty');

        Aggregate::percentile('', 0.5);
    }

    public function test_percentile_rejects_p_below_zero(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('percentile point must be in [0.0, 1.0]');

        Aggregate::percentile('tickets', -0.1);
    }

    public function test_percentile_rejects_p_above_one(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('percentile point must be in [0.0, 1.0]');

        Aggregate::percentile('tickets', 1.1);
    }

    public function test_percentiles_rejects_empty_source(): void
    {
        $this->expectException(AggregateConfigurationException::class);

        Aggregate::percentiles('', ['p50' => 0.5]);
    }

    public function test_percentiles_rejects_empty_points_array(): void
    {
        $this->expectException(AggregateConfigurationException::class);

        Aggregate::percentiles('tickets', []);
    }

    public function test_percentiles_rejects_empty_alias(): void
    {
        $this->expectException(AggregateConfigurationException::class);

        Aggregate::percentiles('tickets', ['' => 0.5]);
    }

    // ----------------------------------------------------------------
    // NestedSetAggregate attribute rejection
    // ----------------------------------------------------------------

    public function test_nested_set_aggregate_attribute_rejects_median(): void
    {
        $attr = new NestedSetAggregate(column: 'x', median: 'tickets');

        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('withFreshAggregates()');

        $attr->toDefinition();
    }

    public function test_nested_set_aggregate_attribute_rejects_percentile(): void
    {
        $attr = new NestedSetAggregate(column: 'x', percentile: 'tickets');

        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('withFreshAggregates()');

        $attr->toDefinition();
    }
}
