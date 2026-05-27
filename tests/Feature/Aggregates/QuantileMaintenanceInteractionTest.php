<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Pins the contract between Median/Percentile (fresh-read-only) and the
 * stored-and-maintained aggregate machinery (`fixAggregates`,
 * `aggregateErrors`, the per-mutation maintenance hooks).
 *
 * Two guarantees:
 *  1. NestedSetAggregate construction rejects median/percentile with a
 *     specific error pointing the user at withFreshAggregates().
 *  2. fixAggregates() on a model with maintained aggregates does NOT
 *     interact with ad-hoc fresh-read quantile aliases on a query —
 *     the two surfaces are independent.
 */
final class QuantileMaintenanceInteractionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();

        // Build the tree through the public API so every maintained
        // aggregate (including the AVG companions) is populated
        // consistently — the fix-aggregates test below asserts 0 rows
        // rewritten, which only holds when the seed is in a true
        // steady state.
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root->refresh())->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 50]);
        $a1->appendToNode($a->refresh())->save();

        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();
    }

    public function test_declaring_median_via_nested_set_aggregate_throws_with_pointer_to_fresh_read(): void
    {
        // The attribute constructor is what surfaces this — instantiate
        // and call toDefinition() directly to bypass the registry's
        // model-scan plumbing.
        $attribute = new NestedSetAggregate(column: 'subtree_median', median: 'tickets');

        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('median() and percentile() are recompute-only');
        $this->expectExceptionMessage('withFreshAggregates()');

        $attribute->toDefinition();
    }

    public function test_declaring_percentile_via_nested_set_aggregate_throws_with_pointer_to_fresh_read(): void
    {
        $attribute = new NestedSetAggregate(column: 'subtree_p90', percentile: 'tickets');

        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('median() and percentile() are recompute-only');
        $this->expectExceptionMessage('withFreshAggregates()');

        $attribute->toDefinition();
    }

    private function asFloat(mixed $value): float
    {
        $this->assertTrue(is_numeric($value), 'Expected numeric, got '.get_debug_type($value));

        return (float) $value;
    }

    public function test_fix_aggregates_leaves_quantile_fresh_reads_unaffected(): void
    {
        // Baseline: maintained aggregates are clean and quantile fresh
        // read returns the expected median (50.0 across {25,50,50,100}).
        $this->assertSame(0, array_sum(Area::aggregateErrors()), 'maintained aggregates should be clean before test');

        $rootMedianBefore = Area::query()
            ->withFreshAggregates(['subtree_median' => Aggregate::median('tickets')])
            ->where('id', 1)
            ->firstOrFail()
            ->getAttribute('subtree_median');

        $this->assertEqualsWithDelta(50.0, $this->asFloat($rootMedianBefore), 0.0001);

        // Run fixAggregates() — should rewrite zero rows because the
        // maintained columns are already correct, and must not interact
        // with anything quantile-related (no quantile column is stored
        // on Area; the alias only exists on a query).
        $fix = Area::fixAggregates();
        $this->assertSame(0, $fix->totalRowsUpdated, 'fixAggregates rewrote rows despite a clean tree');
        $this->assertSame(0, array_sum(Area::aggregateErrors()), 'fixAggregates produced new drift');

        // Quantile fresh read still returns the same answer after the
        // maintenance pass — fixAggregates doesn't write to a stored
        // subtree_median column (there is none), so the alias-only
        // query path is untouched.
        $rootMedianAfter = Area::query()
            ->withFreshAggregates(['subtree_median' => Aggregate::median('tickets')])
            ->where('id', 1)
            ->firstOrFail()
            ->getAttribute('subtree_median');

        $this->assertEqualsWithDelta(50.0, $this->asFloat($rootMedianAfter), 0.0001);
    }

    public function test_quantile_fresh_read_composes_with_maintained_aggregate_columns_on_same_query(): void
    {
        // Stored tickets_total (SUM) and tickets_avg coexist with an
        // ad-hoc median alias on the same row. The two come from
        // different code paths — stored columns from the row's
        // attributes, the alias from a per-row correlated subquery —
        // and the test pins they don't trample each other.
        $root = Area::query()
            ->withFreshAggregates([
                'subtree_median' => Aggregate::median('tickets'),
                'subtree_p25' => Aggregate::percentile('tickets', 0.25),
            ])
            ->where('id', 1)
            ->firstOrFail();

        $this->assertSame(225, (int) $root->tickets_total, 'stored SUM column unchanged');
        $this->assertEqualsWithDelta(56.25, $this->asFloat($root->tickets_avg), 0.0001, 'stored AVG column unchanged');
        $this->assertEqualsWithDelta(50.0, $this->asFloat($root->getAttribute('subtree_median')), 0.0001, 'fresh-read median present on same row');
        $this->assertEqualsWithDelta(43.75, $this->asFloat($root->getAttribute('subtree_p25')), 0.0001, 'fresh-read p25 present on same row');
    }
}
