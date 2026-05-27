<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Vusys\NestedSet\Aggregates\AggregateFixResult;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
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

    // ----------------------------------------------------------------
    // Chain-shape fast-path: trees where every parent has exactly one
    // child take a linear PHP fold over the rows instead of the
    // O(N²) per-row subtree aggregation SQL. Tests below assert
    // **value equivalence with the slow path** for every supported
    // aggregate function and both inclusivities. The benchmarks
    // (PathologicalShapesBenchmarkTest::test_deep_chain_*) anchor
    // the perf side.
    // ----------------------------------------------------------------

    /**
     * 5-node chain: R(10) -> a(20) -> b(30) -> c(40) -> d(50).
     * Each parent has exactly one child; chain detection should fire.
     */
    private function seedChain(): void
    {
        $root = new Area(['name' => 'R', 'tickets' => 10]);
        $root->saveAsRoot();
        $prev = $root;
        foreach (['a' => 20, 'b' => 30, 'c' => 40, 'd' => 50] as $name => $tickets) {
            $node = new Area(['name' => $name, 'tickets' => $tickets]);
            $node->appendToNode($prev->refresh())->save();
            $prev = $node;
        }
    }

    public function test_chain_fast_path_intact_tree_has_no_aggregate_errors(): void
    {
        $this->seedChain();

        // Intact tree: maintained aggregates already match the source.
        // The fast-path's PHP fold should agree with the stored values.
        $this->assertSame(
            ['tickets_total' => 0, 'tickets_count_all' => 0, 'tickets_avg' => 0, 'tickets_min' => 0, 'tickets_max' => 0],
            Area::aggregateErrors(),
        );
        $this->assertFalse(Area::aggregatesAreBroken());
    }

    public function test_chain_fast_path_detects_drift_in_every_function(): void
    {
        $this->seedChain();

        // Hand-corrupt one row's aggregates so the chain fast-path has
        // to flag drift in every column simultaneously.
        DB::table('areas')->where('name', 'b')->update([
            'tickets_total' => 999,
            'tickets_count_all' => 999,
            'tickets_avg' => 999,
            'tickets_min' => 999,
            'tickets_max' => 999,
        ]);

        $errors = Area::aggregateErrors();

        // Every user-facing column should register exactly one drift.
        $this->assertSame(1, $errors['tickets_total']);
        $this->assertSame(1, $errors['tickets_count_all']);
        $this->assertSame(1, $errors['tickets_avg']);
        $this->assertSame(1, $errors['tickets_min']);
        $this->assertSame(1, $errors['tickets_max']);
    }

    public function test_chain_fast_path_repairs_all_function_drift(): void
    {
        $this->seedChain();

        // Drift every aggregate column on the deepest node.
        DB::table('areas')->where('name', 'd')->update([
            'tickets_total' => 1, 'tickets_count_all' => 1,
            'tickets_avg' => 1, 'tickets_min' => 1, 'tickets_max' => 1,
        ]);

        $result = Area::fixAggregates();

        $this->assertInstanceOf(AggregateFixResult::class, $result);
        $this->assertSame(1, $result->totalRowsUpdated);

        // After repair, the deepest node's inclusive aggregates over
        // its single-node subtree are: SUM=50, COUNT=1, AVG=50,
        // MIN=MAX=50. The fast-path's fold must produce those.
        $d = Area::query()->where('name', 'd')->firstOrFail();
        $this->assertSame(50, $this->asInt($d->tickets_total));
        $this->assertSame(1, $this->asInt($d->tickets_count_all));
        $this->assertSame(50, $this->asInt($d->tickets_min));
        $this->assertSame(50, $this->asInt($d->tickets_max));
        // AVG comes back as decimal:4 → string on most backends.
        $this->assertSame(50.0, (float) $d->tickets_avg);
    }

    public function test_chain_fast_path_repairs_along_the_whole_chain(): void
    {
        $this->seedChain();

        // Wipe every node's aggregates so the fast-path has to repair
        // each row of the chain.
        DB::table('areas')->update([
            'tickets_total' => 0, 'tickets_count_all' => 0,
            'tickets_avg' => null, 'tickets_min' => null, 'tickets_max' => null,
        ]);

        Area::fixAggregates();

        // Expected inclusive subtree sums along R(10) -> a -> b -> c -> d (5 nodes):
        //   d:    SUM= 50 COUNT=1 MIN=50 MAX= 50
        //   c:    SUM= 90 COUNT=2 MIN=40 MAX= 50
        //   b:    SUM=120 COUNT=3 MIN=30 MAX= 50
        //   a:    SUM=140 COUNT=4 MIN=20 MAX= 50
        //   R:    SUM=150 COUNT=5 MIN=10 MAX= 50
        $expected = [
            'd' => ['total' => 50,  'count' => 1, 'min' => 50, 'max' => 50],
            'c' => ['total' => 90,  'count' => 2, 'min' => 40, 'max' => 50],
            'b' => ['total' => 120, 'count' => 3, 'min' => 30, 'max' => 50],
            'a' => ['total' => 140, 'count' => 4, 'min' => 20, 'max' => 50],
            'R' => ['total' => 150, 'count' => 5, 'min' => 10, 'max' => 50],
        ];

        foreach ($expected as $name => $values) {
            $row = Area::query()->where('name', $name)->firstOrFail();
            $this->assertSame($values['total'], $this->asInt($row->tickets_total), "{$name} total");
            $this->assertSame($values['count'], $this->asInt($row->tickets_count_all), "{$name} count");
            $this->assertSame($values['min'], $this->asInt($row->tickets_min), "{$name} min");
            $this->assertSame($values['max'], $this->asInt($row->tickets_max), "{$name} max");
        }
    }

    public function test_fix_aggregates_rejects_cross_class_anchor(): void
    {
        // Persist a Category so it has a numeric id — the unguarded
        // path would emit `WHERE id = <category-id>` against the
        // `areas` table and either resolve to the wrong row or to no
        // row at all, silently doing the wrong thing.
        $category = new Category(['name' => 'root']);
        $category->saveAsRoot();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an instance of');

        Area::fixAggregates(anchor: $category);
    }

    public function test_aggregate_errors_rejects_cross_class_anchor(): void
    {
        $category = new Category(['name' => 'root']);
        $category->saveAsRoot();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an instance of');

        Area::aggregateErrors(anchor: $category);
    }

    public function test_chain_fast_path_bails_when_a_branch_point_appears(): void
    {
        // R(10) -> a(20), with a *second* child of R — not a chain.
        // The detector must observe `parent_id=R, COUNT=2` and route
        // through the slow path instead of the chain fold. We exercise
        // the bail-out by setting up drift and confirming repair still
        // works — the slow path is well-covered by existing tests.
        $this->seedMotivatingTree(); // has branching: Root -> A, B

        DB::table('areas')->update([
            'tickets_total' => 0, 'tickets_count_all' => 0,
            'tickets_avg' => null, 'tickets_min' => null, 'tickets_max' => null,
        ]);

        $result = Area::fixAggregates();

        // Slow path must have run (chain detection bailed). The
        // motivating-tree assertions from elsewhere in this file lock
        // in the values; here we just confirm the repair ran.
        $this->assertGreaterThan(0, $result->totalRowsUpdated);
        $this->assertFalse(Area::aggregatesAreBroken());
    }

    public function test_chain_detector_treats_disjoint_chains_as_a_forest(): void
    {
        // Two unrelated roots in the same (unscoped) table — both
        // parent_id IS NULL. The detector groups by parent_id and
        // sees COUNT(NULL group) = 2, so the chain-fold bails and
        // the slow path repairs. If a regression accepted multiple
        // NULL-parent roots as a chain, the fold would walk them as
        // one strand and produce wrong values for the second root.
        $r1 = new Area(['name' => 'R1', 'tickets' => 10]);
        $r1->saveAsRoot();
        $r1Child = new Area(['name' => 'R1a', 'tickets' => 20]);
        $r1Child->appendToNode($r1->refresh())->save();

        $r2 = new Area(['name' => 'R2', 'tickets' => 100]);
        $r2->saveAsRoot();
        $r2Child = new Area(['name' => 'R2a', 'tickets' => 200]);
        $r2Child->appendToNode($r2->refresh())->save();

        // Drift every aggregate everywhere so the repair has to
        // recompute both chains.
        DB::table('areas')->update([
            'tickets_total' => 0, 'tickets_count_all' => 0,
            'tickets_avg' => null, 'tickets_min' => null, 'tickets_max' => null,
        ]);

        $result = Area::fixAggregates();
        $this->assertGreaterThan(0, $result->totalRowsUpdated);

        $r1 = Area::query()->where('name', 'R1')->firstOrFail();
        $r2 = Area::query()->where('name', 'R2')->firstOrFail();

        $this->assertSame(30, $this->asInt($r1->tickets_total), 'first chain repaired in isolation');
        $this->assertSame(300, $this->asInt($r2->tickets_total), 'second chain repaired in isolation');
        $this->assertFalse(Area::aggregatesAreBroken(), 'no cross-chain contamination');
    }

    public function test_chain_detector_handles_empty_scope_cleanly(): void
    {
        // No rows at all — the detector's GROUP BY returns zero rows
        // ("no parent has more than one child" is vacuously true),
        // so the fast path runs over zero rows. The repair must
        // exit without errors and report zero updates.
        $this->assertSame(0, Area::query()->count(), 'precondition: table is empty');

        $result = Area::fixAggregates();

        $this->assertSame(0, $result->totalRowsUpdated, 'empty table → zero updates');
        $this->assertFalse(Area::aggregatesAreBroken(), 'empty table has no drift');
    }

    public function test_chain_detector_handles_single_node_tree(): void
    {
        // Single row, parent_id IS NULL, COUNT(NULL group) = 1 →
        // detector returns true (a "chain of one"). The fold runs
        // over the one row and assigns its own source value as the
        // inclusive aggregate of its subtree.
        $solo = new Area(['name' => 'solo', 'tickets' => 42]);
        $solo->saveAsRoot();

        DB::table('areas')->update([
            'tickets_total' => 0, 'tickets_count_all' => 0,
            'tickets_avg' => null, 'tickets_min' => null, 'tickets_max' => null,
        ]);

        $result = Area::fixAggregates();
        $this->assertSame(1, $result->totalRowsUpdated, 'single drifted row repaired');

        $solo = Area::query()->where('name', 'solo')->firstOrFail();
        $this->assertSame(42, $this->asInt($solo->tickets_total));
        $this->assertSame(1, $this->asInt($solo->tickets_count_all));
        $this->assertSame(42, $this->asInt($solo->tickets_min));
        $this->assertSame(42, $this->asInt($solo->tickets_max));
    }
}
