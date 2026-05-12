<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Phase H: integrity tooling. `aggregateErrors()` and
 * `aggregatesAreBroken()` detect stored-vs-fresh drift;
 * `fixAggregates()` repairs it; `fixTree()` chains a repair pass at
 * the end so a corrupted lft/rgt + aggregate combo can be recovered
 * with one call.
 */
final class AggregateIntegrityTest extends TestCase
{
    /** fixTree-style tests intentionally corrupt the tree. */
    protected bool $allowBrokenTreeAtTearDown = true;

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

    private function seedMotivatingTree(): void
    {
        // Root(100) > A(50) > A1(50); Root > B(25).
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 50]);
        $a1->appendToNode($a->refresh())->save();

        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();
    }

    // ----------------------------------------------------------------
    // aggregateErrors() / aggregatesAreBroken()
    // ----------------------------------------------------------------

    public function test_intact_tree_has_no_aggregate_errors(): void
    {
        $this->seedMotivatingTree();

        $this->assertSame(
            ['tickets_total' => 0, 'tickets_count_all' => 0, 'tickets_avg' => 0, 'tickets_min' => 0, 'tickets_max' => 0],
            Area::aggregateErrors(),
        );
        $this->assertFalse(Area::aggregatesAreBroken());
    }

    public function test_aggregate_errors_detect_a_single_stored_column_drift(): void
    {
        $this->seedMotivatingTree();

        // Corrupt one stored aggregate value directly.
        DB::table('areas')->where('name', 'Root')->update(['tickets_total' => 999]);

        $errors = Area::aggregateErrors();

        $this->assertSame(1, $errors['tickets_total']);
        $this->assertSame(0, $errors['tickets_count_all']);
        $this->assertTrue(Area::aggregatesAreBroken());
    }

    public function test_aggregate_errors_detect_drift_in_multiple_columns(): void
    {
        $this->seedMotivatingTree();

        DB::table('areas')->where('name', 'Root')->update([
            'tickets_total' => 999,
            'tickets_max' => 9999,
        ]);
        DB::table('areas')->where('name', 'A')->update([
            'tickets_total' => 12345,
        ]);

        $errors = Area::aggregateErrors();

        $this->assertSame(2, $errors['tickets_total']);
        $this->assertSame(1, $errors['tickets_max']);
        $this->assertSame(0, $errors['tickets_count_all']);
    }

    public function test_aggregate_errors_excludes_internal_companions(): void
    {
        $this->seedMotivatingTree();

        // Corrupt the internal AVG count companion.
        DB::table('areas')->where('name', 'Root')->update(['tickets_avg__count' => 999]);

        $errors = Area::aggregateErrors();

        // No user-facing column reports an error — the internal
        // companion drift is repair-side only.
        foreach ($errors as $column => $count) {
            $this->assertSame(0, $count, "internal-only drift must not surface as user error on {$column}");
        }
    }

    // ----------------------------------------------------------------
    // fixAggregates()
    // ----------------------------------------------------------------

    public function test_fix_aggregates_on_intact_tree_is_a_noop(): void
    {
        $this->seedMotivatingTree();

        $result = Area::fixAggregates();

        $this->assertInstanceOf(AggregateFixResult::class, $result);
        $this->assertSame(0, $result->totalRowsUpdated);
        $this->assertFalse($result->hasDrift());
    }

    public function test_fix_aggregates_repairs_single_column_drift(): void
    {
        $this->seedMotivatingTree();

        DB::table('areas')->where('name', 'Root')->update(['tickets_total' => 999]);

        $this->assertTrue(Area::aggregatesAreBroken(), 'precondition: drift is detected');

        $result = Area::fixAggregates();

        $this->assertSame(1, $result->totalRowsUpdated);
        $this->assertSame(1, $result->perColumn['tickets_total']);
        $this->assertFalse(Area::aggregatesAreBroken(), 'postcondition: drift is gone');

        $root = Area::query()->where('name', 'Root')->firstOrFail();
        $this->assertSame(225, $this->asInt($root->tickets_total));
    }

    public function test_fix_aggregates_repairs_drift_in_all_supported_functions(): void
    {
        $this->seedMotivatingTree();

        DB::table('areas')->where('name', 'Root')->update([
            'tickets_total' => 999,
            'tickets_count_all' => 42,
            'tickets_avg' => 0.0001,
            'tickets_min' => 5,
            'tickets_max' => 9999,
        ]);

        Area::fixAggregates();

        $root = Area::query()->where('name', 'Root')->firstOrFail();

        $this->assertSame(225, $this->asInt($root->tickets_total));
        $this->assertSame(4, $this->asInt($root->tickets_count_all));
        $this->assertEqualsWithDelta(56.25, (float) $root->tickets_avg, 0.0001);
        $this->assertSame(25, $this->asInt($root->tickets_min));
        $this->assertSame(100, $this->asInt($root->tickets_max));
    }

    public function test_fix_aggregates_also_repairs_internal_companions(): void
    {
        $this->seedMotivatingTree();

        DB::table('areas')->where('name', 'Root')->update([
            'tickets_avg__count' => 999,
        ]);

        $result = Area::fixAggregates();

        $this->assertGreaterThan(0, $result->totalRowsUpdated);

        $row = DB::table('areas')->where('name', 'Root')->first();
        $this->assertNotNull($row);
        $this->assertSame(4, $this->asInt($row->tickets_avg__count));
    }

    // ----------------------------------------------------------------
    // fixTree() composition
    // ----------------------------------------------------------------

    public function test_fix_tree_runs_fix_aggregates_as_final_step(): void
    {
        $this->seedMotivatingTree();

        // Corrupt both a tree-structural value AND an aggregate value.
        // fixTree must repair both in one call.
        DB::table('areas')->where('name', 'Root')->update([
            'tickets_total' => 9999,
            'lft' => 0, // corrupt structural state
        ]);

        $result = Area::fixTree();

        // Tree side repaired.
        $this->assertNotNull($result->aggregatesFixed);
        $this->assertSame(0, array_sum($result->errors), 'tree errors after fix');

        // Aggregate side repaired.
        $root = Area::query()->where('name', 'Root')->firstOrFail();
        $this->assertSame(225, $this->asInt($root->tickets_total));
        $this->assertFalse(Area::aggregatesAreBroken());
    }

    public function test_fix_tree_returns_aggregates_fixed_with_per_column_counts(): void
    {
        $this->seedMotivatingTree();

        DB::table('areas')->where('name', 'Root')->update(['tickets_total' => 999]);
        DB::table('areas')->where('name', 'A')->update(['tickets_max' => 88888]);

        $result = Area::fixTree();

        $this->assertNotNull($result->aggregatesFixed);
        $this->assertGreaterThanOrEqual(2, $result->aggregatesFixed->totalRowsUpdated);
        $this->assertSame(1, $result->aggregatesFixed->perColumn['tickets_total']);
        $this->assertSame(1, $result->aggregatesFixed->perColumn['tickets_max']);
    }

    // ----------------------------------------------------------------
    // Model without aggregates
    // ----------------------------------------------------------------

    public function test_fix_tree_on_model_without_aggregates_leaves_field_null(): void
    {
        // Categories declare no aggregate columns — fixTree's aggregate
        // pass should silently skip and leave aggregatesFixed unset.
        $category = new Category(['name' => 'Root']);
        $category->saveAsRoot();

        $result = Category::fixTree();

        $this->assertNull($result->aggregatesFixed);
    }
}
