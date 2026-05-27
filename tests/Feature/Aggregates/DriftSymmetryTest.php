<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Branch;
use Vusys\NestedSet\Tests\Fixtures\Models\Monster;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Drift detection / repair symmetry — the three faces of correctness
 * must agree across a wide variety of induced drift scenarios.
 *
 * For every drift recipe in the provider:
 *   1. Build a clean tree.
 *   2. Inject drift via raw DB::table()->update().
 *   3. `aggregateErrors()` must report the column as drifted.
 *   4. `fixAggregates()` must write at least one row.
 *   5. After fix, `aggregateErrors()` is zero and `freshAggregate()`
 *      equals the stored value for every node.
 *
 * If any of these diverge, the package has a consistency hole — drift
 * detection that doesn't see what fix repairs, or vice versa, is worse
 * than no detection at all.
 */
final class DriftSymmetryTest extends TestCase
{
    /** drift is induced via raw DB writes, leaves the tree consistent. */
    protected bool $allowBrokenTreeAtTearDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    // ================================================================
    // SQL aggregate drift recipes
    // ================================================================

    /**
     * @return iterable<string, array{
     *     column: string,
     *     mutate: Closure(int): int
     * }>
     */
    public static function areaSqlDriftProvider(): iterable
    {
        // SUM: overstate by one off-by-one boundary
        yield 'tickets_total +1 off-by-one on root' => [
            'column' => 'tickets_total',
            'mutate' => fn (int $rootId): int => DB::table('areas')
                ->where('id', $rootId)
                ->update(['tickets_total' => DB::raw('tickets_total + 1')]),
        ];

        // SUM: zero out — common after a partial restore
        yield 'tickets_total cleared on root' => [
            'column' => 'tickets_total',
            'mutate' => fn (int $rootId): int => DB::table('areas')
                ->where('id', $rootId)
                ->update(['tickets_total' => 0]),
        ];

        // COUNT: undercount
        yield 'tickets_count_all -1 on root' => [
            'column' => 'tickets_count_all',
            'mutate' => fn (int $rootId): int => DB::table('areas')
                ->where('id', $rootId)
                ->update(['tickets_count_all' => DB::raw('tickets_count_all - 1')]),
        ];

        // AVG: corrupt the average
        yield 'tickets_avg set to fixed value on root' => [
            'column' => 'tickets_avg',
            'mutate' => fn (int $rootId): int => DB::table('areas')
                ->where('id', $rootId)
                ->update(['tickets_avg' => 9999.0]),
        ];

        // MIN: cleared to NULL — recompute must restore the actual min
        yield 'tickets_min nulled on root' => [
            'column' => 'tickets_min',
            'mutate' => fn (int $rootId): int => DB::table('areas')
                ->where('id', $rootId)
                ->update(['tickets_min' => null]),
        ];

        // MAX: overstated — recompute must lower it
        yield 'tickets_max overstated on root' => [
            'column' => 'tickets_max',
            'mutate' => fn (int $rootId): int => DB::table('areas')
                ->where('id', $rootId)
                ->update(['tickets_max' => 999999]),
        ];

        // Mid-tree drift — not just on the root, exercises the
        // ancestor-chain recompute path.
        yield 'tickets_total drifted on non-root ancestor' => [
            'column' => 'tickets_total',
            'mutate' => function (int $rootId): int {
                /** @var array<int, object{id: int}> $children */
                $children = DB::table('areas')
                    ->where('parent_id', $rootId)
                    ->limit(1)
                    ->get(['id'])
                    ->all();
                if ($children === []) {
                    return 0;
                }

                return DB::table('areas')
                    ->where('id', $children[0]->id)
                    ->update(['tickets_total' => DB::raw('tickets_total + 42')]);
            },
        ];
    }

    #[DataProvider('areaSqlDriftProvider')]
    public function test_drift_detection_and_repair_are_consistent_for_sql_aggregates(
        string $column,
        Closure $mutate,
    ): void {
        $root = $this->seedMotivatingArea();

        // No drift before mutation.
        $beforeErrors = Area::aggregateErrors();
        $this->assertSame(0, $beforeErrors[$column] ?? -1, "{$column} reports pre-mutation drift");

        // Induce drift.
        $touched = ($mutate)((int) $root->id);
        if ($touched === 0) {
            $this->markTestSkipped('mutation found nothing to corrupt on this tree');
        }

        // 1. aggregateErrors reports the drift.
        $afterErrors = Area::aggregateErrors();
        $this->assertGreaterThan(
            0,
            $afterErrors[$column] ?? 0,
            "{$column} drift not detected by aggregateErrors()",
        );
        $this->assertTrue(Area::aggregatesAreBroken(), 'aggregatesAreBroken() should be true');

        // 2. fixAggregates writes rows.
        $fix = Area::fixAggregates();
        $this->assertGreaterThan(0, $fix->totalRowsUpdated, 'fixAggregates should have written rows');
        $this->assertGreaterThan(
            0,
            $fix->perColumn[$column] ?? 0,
            "fixAggregates per-column count for {$column} should be > 0",
        );

        // 3. Post-fix invariants: zero drift, stored == fresh on every node.
        $finalErrors = Area::aggregateErrors();
        foreach ($finalErrors as $col => $count) {
            $this->assertSame(0, $count, "{$col} still reports drift after fix");
        }
        $this->assertFalse(Area::aggregatesAreBroken(), 'tree should be clean after fix');

        foreach (Area::all() as $node) {
            foreach ($node->getAggregateDefinitions() as $definition) {
                $col = $definition->getColumn();
                $stored = $node->getAttribute($col);
                $fresh = $node->freshAggregate($col);
                $this->assertSameNormalised(
                    $fresh,
                    $stored,
                    "node #{$node->id} column {$col}: stored != fresh after fix",
                );
            }
        }
    }

    // ================================================================
    // Listener aggregate drift recipes
    // ================================================================

    /**
     * @return iterable<string, array{
     *     column: string,
     *     mutate: Closure(int): int
     * }>
     */
    public static function monsterListenerDriftProvider(): iterable
    {
        yield 'weighted_power +13 on root' => [
            'column' => 'weighted_power',
            'mutate' => fn (int $rootId): int => DB::table('monsters')
                ->where('id', $rootId)
                ->update(['weighted_power' => DB::raw('weighted_power + 13')]),
        ];

        yield 'weighted_power zeroed on root' => [
            'column' => 'weighted_power',
            'mutate' => fn (int $rootId): int => DB::table('monsters')
                ->where('id', $rootId)
                ->update(['weighted_power' => 0]),
        ];

        yield 'fire_count overstated on root' => [
            'column' => 'fire_count',
            'mutate' => fn (int $rootId): int => DB::table('monsters')
                ->where('id', $rootId)
                ->update(['fire_count' => 100]),
        ];

        yield 'weakest_level nulled on root' => [
            'column' => 'weakest_level',
            'mutate' => fn (int $rootId): int => DB::table('monsters')
                ->where('id', $rootId)
                ->update(['weakest_level' => null]),
        ];

        yield 'half_weighted_power off by fractional' => [
            'column' => 'half_weighted_power',
            'mutate' => fn (int $rootId): int => DB::table('monsters')
                ->where('id', $rootId)
                ->update(['half_weighted_power' => DB::raw('half_weighted_power + 0.5')]),
        ];

        yield 'weighted_avg corrupted on root' => [
            'column' => 'weighted_avg',
            'mutate' => fn (int $rootId): int => DB::table('monsters')
                ->where('id', $rootId)
                ->update(['weighted_avg' => 9999.99]),
        ];

        // weighted_power doubles as the AVG sum-companion (auto-promotion
        // sees the user-declared Sum on the same listener and skips
        // creating weighted_avg__sum). Drifting weighted_power should
        // cascade into a weighted_avg recompute too.
        yield 'weighted_power drift cascades into weighted_avg' => [
            'column' => 'weighted_power',
            'mutate' => fn (int $rootId): int => DB::table('monsters')
                ->where('id', $rootId)
                ->update(['weighted_power' => DB::raw('weighted_power + 11')]),
        ];
    }

    #[DataProvider('monsterListenerDriftProvider')]
    public function test_drift_detection_and_repair_are_consistent_for_listener_aggregates(
        string $column,
        Closure $mutate,
    ): void {
        $root = $this->seedMonsterTree();

        ($mutate)((int) $root->id);

        // 1. Detection — at least one user-facing column reports drift.
        //    (Per-column may vary: some companion drifts manifest only
        //    in the AVG display column, but the visible-drift report
        //    is by user-facing column only. So we assert on the
        //    aggregate total here and check the per-column self-
        //    consistency at the end of the test.)
        $errors = Monster::aggregateErrors();
        $this->assertGreaterThan(
            0,
            array_sum($errors),
            'aggregateErrors() should have surfaced at least one drifted column',
        );

        // 2. Fix writes at least one row.
        $fix = Monster::fixAggregates();
        $this->assertGreaterThan(0, $fix->totalRowsUpdated, 'fixAggregates should have written rows');

        // 3. Post-fix: no errors anywhere.
        $finalErrors = Monster::aggregateErrors();
        foreach ($finalErrors as $col => $count) {
            $this->assertSame(0, $count, "{$col} still reports drift after fix");
        }

        // 4. Post-fix: stored == fresh on every node, every user-facing column.
        foreach (Monster::all() as $node) {
            foreach ($node->getAggregateDefinitions() as $definition) {
                $col = $definition->getColumn();
                $stored = $node->getAttribute($col);
                $fresh = $node->freshAggregate($col);
                $this->assertSameNormalised(
                    $fresh,
                    $stored,
                    "node #{$node->id} column {$col}: stored != fresh after fix",
                );
            }
        }

        // Self-consistency: the detection report must agree with what
        // fix actually wrote — if errors said drift in this column, fix
        // must have written at least one row for it.
        if (($errors[$column] ?? 0) > 0) {
            $this->assertGreaterThan(
                0,
                $fix->perColumn[$column] ?? 0,
                "{$column} was reported drifted but fix wrote 0 rows for it",
            );
        }
    }

    // ================================================================
    // Filtered / exclusive / raw — Branch drift symmetry
    // ================================================================

    /**
     * @return iterable<string, array{
     *     column: string,
     *     mutate: Closure(int): int
     * }>
     */
    public static function branchDriftProvider(): iterable
    {
        yield 'exclusive descendants_total drifted on root' => [
            'column' => 'descendants_total',
            'mutate' => fn (int $rootId): int => DB::table('branches')
                ->where('id', $rootId)
                ->update(['descendants_total' => 9999]),
        ];

        yield 'exclusive descendants_count drifted on root' => [
            'column' => 'descendants_count',
            'mutate' => fn (int $rootId): int => DB::table('branches')
                ->where('id', $rootId)
                ->update(['descendants_count' => 0]),
        ];

        yield 'exclusive descendants_max nulled on root' => [
            'column' => 'descendants_max',
            'mutate' => fn (int $rootId): int => DB::table('branches')
                ->where('id', $rootId)
                ->update(['descendants_max' => null]),
        ];

        yield 'raw-filter active_tickets_total inflated on root' => [
            'column' => 'active_tickets_total',
            'mutate' => fn (int $rootId): int => DB::table('branches')
                ->where('id', $rootId)
                ->update(['active_tickets_total' => 9999]),
        ];
    }

    #[DataProvider('branchDriftProvider')]
    public function test_drift_detection_and_repair_are_consistent_for_branch(
        string $column,
        Closure $mutate,
    ): void {
        $root = $this->seedBranchTree();

        ($mutate)((int) $root->id);

        $errors = Branch::aggregateErrors();
        $this->assertGreaterThan(
            0,
            $errors[$column] ?? 0,
            "{$column} drift not detected by aggregateErrors()",
        );

        $fix = Branch::fixAggregates();
        $this->assertGreaterThan(0, $fix->totalRowsUpdated, 'fixAggregates should have written rows');

        $finalErrors = Branch::aggregateErrors();
        foreach ($finalErrors as $col => $count) {
            $this->assertSame(0, $count, "{$col} still reports drift after fix");
        }

        foreach (Branch::all() as $node) {
            foreach ($node->getAggregateDefinitions() as $definition) {
                $col = $definition->getColumn();
                $stored = $node->getAttribute($col);
                $fresh = $node->freshAggregate($col);
                $this->assertSameNormalised(
                    $fresh,
                    $stored,
                    "node #{$node->id} column {$col}: stored != fresh after fix",
                );
            }
        }
    }

    // ================================================================
    // Seeds — small, deterministic, exercise every column
    // ================================================================

    private function seedMotivatingArea(): Area
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();
        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();
        $a1 = new Area(['name' => 'A1', 'tickets' => 50]);
        $a1->appendToNode($a->refresh())->save();
        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();

        /** @var Area $fresh */
        $fresh = $root->fresh();

        return $fresh;
    }

    private function seedMonsterTree(): Monster
    {
        $root = new Monster(['name' => 'Root', 'type' => 'fire', 'base_power' => 5, 'level' => 4]);
        $root->saveAsRoot();
        $a = new Monster(['name' => 'A', 'type' => 'water', 'base_power' => 3, 'level' => 2]);
        $a->appendToNode($root)->save();
        $a1 = new Monster(['name' => 'A1', 'type' => 'fire', 'base_power' => 2, 'level' => 1]);
        $a1->appendToNode($a->refresh())->save();
        $b = new Monster(['name' => 'B', 'type' => 'fire', 'base_power' => 6, 'level' => 3]);
        $b->appendToNode($root->refresh())->save();

        /** @var Monster $fresh */
        $fresh = $root->fresh();

        return $fresh;
    }

    private function seedBranchTree(): Branch
    {
        $root = new Branch(['name' => 'Root', 'tickets' => 10, 'active' => 1]);
        $root->saveAsRoot();
        $a = new Branch(['name' => 'A', 'tickets' => 20, 'active' => 0]);
        $a->appendToNode($root)->save();
        $b = new Branch(['name' => 'B', 'tickets' => 40, 'active' => 1]);
        $b->appendToNode($root->refresh())->save();

        // Branch's exclusive + raw-filter columns are not maintained
        // incrementally on create — bring them to truth so the
        // drift-injection step starts from a known-clean baseline.
        Branch::fixAggregates();

        /** @var Branch $fresh */
        $fresh = $root->fresh();

        return $fresh;
    }

    /**
     * Normalised equality: strings that parse as numbers compare as
     * numbers; floats use a delta tolerance. Mirrors the maintenance
     * code's `numericPreserveType` behaviour at the assertion seam.
     */
    private function assertSameNormalised(mixed $expected, mixed $actual, string $message): void
    {
        $expectedIsFloat = is_float($expected) || (is_string($expected) && str_contains($expected, '.'));
        $actualIsFloat = is_float($actual) || (is_string($actual) && str_contains($actual, '.'));

        if ($expectedIsFloat || $actualIsFloat) {
            $expectedFloat = $expected === null ? 0.0 : (is_numeric($expected) ? (float) $expected : 0.0);
            $actualFloat = $actual === null ? 0.0 : (is_numeric($actual) ? (float) $actual : 0.0);
            $this->assertEqualsWithDelta($expectedFloat, $actualFloat, 0.0001, $message);

            return;
        }

        $expectedNorm = $expected === null ? null : (is_numeric($expected) ? (int) $expected : $expected);
        $actualNorm = $actual === null ? null : (is_numeric($actual) ? (int) $actual : $actual);

        $this->assertSame($expectedNorm, $actualNorm, $message);
    }
}
