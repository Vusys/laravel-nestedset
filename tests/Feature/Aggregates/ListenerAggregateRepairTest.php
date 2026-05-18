<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\Pokemon;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Phase 9 repair tests: fixAggregates(), aggregateErrors(), freshAggregate(),
 * and replicate() handle listener aggregate columns (weighted_power, fire_count)
 * correctly.
 */
final class ListenerAggregateRepairTest extends TestCase
{
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

    /**
     * Build a simple root + children tree via Eloquent (lifecycle hooks fire
     * so all aggregates are correct), then corrupt a column via raw SQL.
     *
     * @return array{root: Pokemon, children: list<Pokemon>}
     */
    private function buildTreeWithChildren(int $childCount = 2): array
    {
        $root = new Pokemon(['name' => 'Root', 'type' => 'fire', 'base_power' => 10, 'level' => 2]);
        $root->saveAsRoot();
        $root->refresh();

        $children = [];
        for ($i = 0; $i < $childCount; $i++) {
            $child = new Pokemon([
                'name' => "Child{$i}",
                'type' => 'fire',
                'base_power' => 5,
                'level' => 3,
            ]);
            $child->appendToNode($root)->save();
            $children[] = $child->refresh();
        }

        $root->refresh();

        return ['root' => $root, 'children' => $children];
    }

    // ----------------------------------------------------------------
    // 1. fixAggregates repairs drifted listener SUM (weighted_power)
    // ----------------------------------------------------------------

    public function test_fix_aggregates_repairs_drifted_listener_sum_column(): void
    {
        ['root' => $root, 'children' => $children] = $this->buildTreeWithChildren(2);

        // Corrupt the root's weighted_power to a wrong value.
        DB::table('pokemon')->where('id', $root->id)->update(['weighted_power' => 999]);

        $root->refresh();
        $this->assertSame(999, $this->asInt($root->weighted_power));

        Pokemon::fixAggregates();

        $root->refresh();

        // root: 10*2=20, child0: 5*3=15, child1: 5*3=15 → total = 50
        $this->assertSame(50, $this->asInt($root->weighted_power));
    }

    // ----------------------------------------------------------------
    // 2. fixAggregates repairs drifted listener SUM (fire_count)
    // ----------------------------------------------------------------

    public function test_fix_aggregates_repairs_drifted_listener_count_column(): void
    {
        ['root' => $root, 'children' => $children] = $this->buildTreeWithChildren(2);

        // Corrupt fire_count on root.
        DB::table('pokemon')->where('id', $root->id)->update(['fire_count' => 42]);

        $root->refresh();
        $this->assertSame(42, $this->asInt($root->fire_count));

        Pokemon::fixAggregates();

        $root->refresh();

        // root (fire) + 2 children (fire) → fire_count = 3
        $this->assertSame(3, $this->asInt($root->fire_count));
    }

    // ----------------------------------------------------------------
    // 3. fixAggregates returns correct perColumn counts
    // ----------------------------------------------------------------

    public function test_fix_aggregates_returns_correct_per_column_counts(): void
    {
        ['root' => $root, 'children' => $children] = $this->buildTreeWithChildren(3);

        // Drift weighted_power on root + child0 + child1 (3 rows)
        DB::table('pokemon')
            ->whereIn('id', [$root->id, $children[0]->id, $children[1]->id])
            ->update(['weighted_power' => 999]);

        // Drift fire_count on root + child0 (2 rows)
        DB::table('pokemon')
            ->whereIn('id', [$root->id, $children[0]->id])
            ->update(['fire_count' => 77]);

        $result = Pokemon::fixAggregates();

        $this->assertSame(3, $result->perColumn['weighted_power'] ?? null);
        $this->assertSame(2, $result->perColumn['fire_count'] ?? null);
    }

    // ----------------------------------------------------------------
    // 4. aggregateErrors counts listener column disagreements
    // ----------------------------------------------------------------

    public function test_aggregate_errors_counts_listener_column_disagreements(): void
    {
        ['root' => $root, 'children' => $children] = $this->buildTreeWithChildren(2);

        // Drift weighted_power on 2 rows
        DB::table('pokemon')
            ->whereIn('id', [$root->id, $children[0]->id])
            ->update(['weighted_power' => 0]);

        $errors = Pokemon::aggregateErrors();

        $this->assertGreaterThanOrEqual(2, $errors['weighted_power'] ?? 0);
    }

    // ----------------------------------------------------------------
    // 5. aggregateErrors returns zero for correct tree
    // ----------------------------------------------------------------

    public function test_aggregate_errors_returns_zero_for_correct_tree(): void
    {
        $this->buildTreeWithChildren(3);

        $errors = Pokemon::aggregateErrors();

        $this->assertSame(0, $errors['weighted_power'] ?? 1);
        $this->assertSame(0, $errors['fire_count'] ?? 1);
    }

    // ----------------------------------------------------------------
    // 6. freshAggregate returns correct value for listener column
    // ----------------------------------------------------------------

    public function test_fresh_aggregate_returns_correct_value_for_listener_column(): void
    {
        $root = new Pokemon(['name' => 'Root', 'type' => 'fire', 'base_power' => 4, 'level' => 5]);
        $root->saveAsRoot();
        $root->refresh();

        $child = new Pokemon(['name' => 'Child', 'type' => 'water', 'base_power' => 3, 'level' => 2]);
        $child->appendToNode($root)->save();
        $root->refresh();

        // Corrupt stored value
        DB::table('pokemon')->where('id', $root->id)->update(['weighted_power' => 0]);
        $root->refresh();

        // freshAggregate should re-compute in PHP: root(4*5=20) + child(3*2=6) = 26
        $fresh = $root->freshAggregate('weighted_power');

        $this->assertSame(26, $this->asInt($fresh));
    }

    // ----------------------------------------------------------------
    // 7. replicate resets listener aggregate columns to zero
    // ----------------------------------------------------------------

    public function test_replicate_resets_listener_aggregate_columns(): void
    {
        $root = new Pokemon(['name' => 'Root', 'type' => 'fire', 'base_power' => 5, 'level' => 3]);
        $root->saveAsRoot();
        $root->refresh();

        // Manually inject known aggregate values
        DB::table('pokemon')->where('id', $root->id)->update([
            'weighted_power' => 42,
            'fire_count' => 3,
        ]);
        $root->refresh();

        $this->assertSame(42, $this->asInt($root->weighted_power));
        $this->assertSame(3, $this->asInt($root->fire_count));

        $clone = $root->replicate();

        $this->assertSame(0, $this->asInt($clone->getAttribute('weighted_power')));
        $this->assertSame(0, $this->asInt($clone->getAttribute('fire_count')));
    }

    // ----------------------------------------------------------------
    // 8. fixAggregates is a no-op when values are correct
    // ----------------------------------------------------------------

    public function test_fix_aggregates_no_op_when_values_correct(): void
    {
        $this->buildTreeWithChildren(3);

        $result = Pokemon::fixAggregates();

        $this->assertSame(0, $result->totalRowsUpdated);
    }
}
