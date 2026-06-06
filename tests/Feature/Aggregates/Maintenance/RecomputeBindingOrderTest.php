<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Maintenance;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\TypedArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Locks down the positional-bindings contract for
 * {@see RecomputeMaintenance::apply()} on a filtered aggregate column.
 *
 * The recompute SELECT places the inner filtered-aggregate expressions
 * in the SELECT clause (textually before WHERE) — the filter predicate's
 * `?` placeholders therefore precede the outer bounds / scope `?` in the
 * final SQL, so the bindings array passed to `connection->select()` must
 * have predicate values first.
 *
 * Without that ordering the call silently binds the wrong value to the
 * wrong placeholder; on a non-strict driver the recompute would return
 * a corrupted MAX without erroring.
 */
final class RecomputeBindingOrderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();

        // Parent with two children — both 'water' so the MAX recompute
        // has rows that match the filter, but values that differ so we
        // can observe the right one was picked.
        DB::table('typed_areas')->insert([
            'id' => 1,
            'name' => 'Parent',
            'tickets' => 0,
            'type' => null,
            'lft' => 1,
            'rgt' => 6,
            'depth' => 0,
            'parent_id' => null,
            'fire_tickets' => 0,
            'fire_count' => 0,
            'water_max' => null,
            'has_tickets' => 0,
        ]);
        $this->syncSequence('typed_areas');
    }

    public function test_recompute_select_orders_predicate_bindings_before_outer_bounds(): void
    {
        $parent = TypedArea::find(1);
        $this->assertNotNull($parent);

        $highWater = new TypedArea(['name' => 'High', 'tickets' => 50, 'type' => 'water']);
        $highWater->appendToNode($parent)->save();

        $lowWater = new TypedArea(['name' => 'Low', 'tickets' => 10, 'type' => 'water']);
        $lowWater->appendToNode($parent)->save();

        $parent->refresh();
        $this->assertSame(50, $this->asInt($parent->water_max), 'precondition: parent.water_max tracks the high child');

        // Deleting the current MAX bearer triggers RecomputeMaintenance
        // (the deleted value WAS the extremum; the parent's stored MAX
        // can't be derived from a delta and must be re-derived).
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $highWater->delete();
        } finally {
            DB::disableQueryLog();
        }

        $recomputeEntry = null;
        foreach (DB::getQueryLog() as $entry) {
            $sql = $entry['query'];
            if (! str_starts_with(strtolower($sql), 'select')) {
                continue;
            }
            if (! str_contains($sql, 'outer_a') || ! str_contains($sql, 'inner_a')) {
                continue;
            }
            $recomputeEntry = $entry;

            break;
        }

        $this->assertNotNull($recomputeEntry, 'expected a RecomputeMaintenance SELECT in the query log');

        $sql = $recomputeEntry['query'];
        $bindings = $recomputeEntry['bindings'];

        // Sanity: SQL has one `?` per binding.
        $this->assertSame(
            substr_count($sql, '?'),
            count($bindings),
            'SQL placeholder count must equal bindings count',
        );

        // Order invariant: the inner filtered MAX (containing the
        // predicate `?`) lives in the SELECT clause; the outer bounds /
        // filterEquals `?` live in the WHERE clause — so the predicate
        // binding must lead the array.
        $this->assertSame(
            'water',
            $bindings[0],
            'filter predicate binding must precede outer bounds bindings to align with positional `?`',
        );

        // Splice-count invariant: predicate is spliced once per filtered
        // MAX column (one column here), so 'water' appears exactly once.
        $waterCount = count(array_filter($bindings, static fn (mixed $b): bool => $b === 'water'));
        $this->assertSame(1, $waterCount, 'filter predicate bindings should appear once per splice — MAX splices once');
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
}
