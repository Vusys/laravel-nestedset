<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Functions;

use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\TopKArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Maintenance + fresh-read tests for the TopK aggregate kind.
 *
 * TopK is recompute-only (no signed delta) — every test verifies that
 * the maintained column converges to the same JSON `[id, revenue]`
 * pairs that the fresh-read path computes ad hoc.
 */
final class TopKMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    /**
     * Builds a tree where each node has a unique revenue value so the
     * Top-3 ordering is unambiguous on every backend:
     *
     *     Root (rev=100, category=active)
     *     ├── A   (rev=500, category=active)
     *     │   └── A1 (rev=700, category=inactive)
     *     ├── B   (rev=300, category=active)
     *     └── C   (rev=200, category=active)
     *
     * Top 3 by revenue across the full subtree: A1 (700), A (500), B (300).
     * Top 3 with `category = active`: A (500), B (300), C (200).
     */
    private function buildFixture(): TopKArea
    {
        $root = new TopKArea(['name' => 'Root', 'revenue' => 100, 'category' => 'active']);
        $root->saveAsRoot();

        $a = new TopKArea(['name' => 'A', 'revenue' => 500, 'category' => 'active']);
        $a->appendToNode($root)->save();

        $a1 = new TopKArea(['name' => 'A1', 'revenue' => 700, 'category' => 'inactive']);
        $a1->appendToNode($a)->save();

        $b = new TopKArea(['name' => 'B', 'revenue' => 300, 'category' => 'active']);
        $b->appendToNode($root->refresh())->save();

        $c = new TopKArea(['name' => 'C', 'revenue' => 200, 'category' => 'active']);
        $c->appendToNode($root->refresh())->save();

        return $root->refresh();
    }

    public function test_root_stores_top_three_descendants_by_revenue(): void
    {
        $root = $this->buildFixture();

        $top = $root->top_revenue_ids;
        $this->assertIsArray($top);
        $this->assertCount(3, $top);

        // Each entry is [id, revenue]. We pin the revenue ranking, not
        // the IDs (insertion order in PHP int autoincrement is enough to
        // make the IDs deterministic but it's the ranking that's the
        // public contract).
        $revenues = array_map(static fn (array $row): int => (int) $row[1], $top);
        $this->assertSame([700, 500, 300], $revenues);
    }

    public function test_intermediate_node_stores_its_own_subtree(): void
    {
        $this->buildFixture();

        $a = TopKArea::query()->where('name', 'A')->firstOrFail();
        $top = $a->top_revenue_ids;
        $this->assertIsArray($top);

        // A's subtree is {A=500, A1=700} → top 3 (only 2 available).
        $revenues = array_map(static fn (array $row): int => (int) $row[1], $top);
        $this->assertSame([700, 500], $revenues);
    }

    public function test_leaf_node_holds_its_own_singleton(): void
    {
        $this->buildFixture();

        $c = TopKArea::query()->where('name', 'C')->firstOrFail();
        $top = $c->top_revenue_ids;
        $this->assertIsArray($top);
        $this->assertCount(1, $top);
        $this->assertSame(200, (int) $top[0][1]);
    }

    public function test_inserting_higher_value_promotes_into_top_k(): void
    {
        $root = $this->buildFixture();

        $d = new TopKArea(['name' => 'D', 'revenue' => 999, 'category' => 'active']);
        $d->appendToNode($root)->save();

        $root->refresh();
        $top = $root->top_revenue_ids;
        $this->assertIsArray($top);
        $revenues = array_map(static fn (array $row): int => (int) $row[1], $top);

        // D (999) pushes B (300) out of the Top-3.
        $this->assertSame([999, 700, 500], $revenues);
    }

    public function test_deleting_top_entry_promotes_runner_up(): void
    {
        $root = $this->buildFixture();

        TopKArea::query()->where('name', 'A1')->firstOrFail()->delete();

        $root->refresh();
        $top = $root->top_revenue_ids;
        $this->assertIsArray($top);
        $revenues = array_map(static fn (array $row): int => (int) $row[1], $top);

        // A1 (700) is gone; remaining ranking is A (500), B (300), C (200).
        $this->assertSame([500, 300, 200], $revenues);
    }

    public function test_updating_revenue_re_ranks(): void
    {
        $root = $this->buildFixture();

        // Pump C from 200 → 800; it should now sit above A.
        TopKArea::query()->where('name', 'C')->firstOrFail()->update(['revenue' => 800]);

        $root->refresh();
        $top = $root->top_revenue_ids;
        $this->assertIsArray($top);
        $revenues = array_map(static fn (array $row): int => (int) $row[1], $top);

        $this->assertSame([800, 700, 500], $revenues);
    }

    public function test_null_revenue_rows_are_excluded(): void
    {
        $root = $this->buildFixture();

        // Add a node with NULL revenue — it must not enter the Top-3.
        $blank = new TopKArea(['name' => 'Blank', 'revenue' => null, 'category' => 'active']);
        $blank->appendToNode($root)->save();

        $root->refresh();
        $top = $root->top_revenue_ids;
        $this->assertIsArray($top);
        $revenues = array_map(static fn (array $row): int => (int) $row[1], $top);

        // Still the original Top-3 of A1 (700), A (500), B (300).
        $this->assertSame([700, 500, 300], $revenues);
    }

    public function test_filtered_topk_respects_predicate(): void
    {
        $root = $this->buildFixture();

        $top = $root->top_active_ids;
        $this->assertIsArray($top);

        // category=active excludes A1 (700, inactive); top 3 = A, B, C.
        $revenues = array_map(static fn (array $row): int => (int) $row[1], $top);
        $this->assertSame([500, 300, 200], $revenues);
    }

    public function test_fresh_aggregate_matches_stored_value(): void
    {
        $root = $this->buildFixture();

        $fresh = $root->freshAggregate('top_revenue_ids');
        // The freshAggregate call returns the raw JSON value (string on some
        // backends, array on others depending on driver coercion); normalise
        // through the model accessor for comparison.
        $rootAgain = TopKArea::query()->find($root->getKey());
        $this->assertInstanceOf(TopKArea::class, $rootAgain);

        $storedRevenues = self::revenuesFrom($rootAgain->getAttribute('top_revenue_ids'));
        $freshRevenues = self::revenuesFrom($fresh);

        $this->assertSame($storedRevenues, $freshRevenues);
    }

    public function test_with_fresh_aggregates_for_ad_hoc_top_k(): void
    {
        $this->buildFixture();

        $rows = TopKArea::query()
            ->where('name', 'Root')
            ->withFreshAggregates(['top2' => Aggregate::topK('id', 2, 'revenue')])
            ->get();

        $this->assertCount(1, $rows);
        $first = $rows->first();
        $this->assertInstanceOf(TopKArea::class, $first);

        $top = self::decodeJson($first->getAttribute('top2'));
        $this->assertIsArray($top);
        $this->assertCount(2, $top);

        $revenues = self::revenuesFromArray($top);
        $this->assertSame([700, 500], $revenues);
    }

    /**
     * Normalises a stored / fresh TopK value to a `list<int>` of the
     * `by` column ranking. Accepts the raw value the driver returned
     * (string JSON on some backends, decoded array on others).
     *
     * @return list<int>
     */
    private static function revenuesFrom(mixed $value): array
    {
        $decoded = self::decodeJson($value);
        if (! is_array($decoded)) {
            return [];
        }

        return self::revenuesFromArray($decoded);
    }

    /**
     * @param  array<int|string, mixed>  $decoded
     * @return list<int>
     */
    private static function revenuesFromArray(array $decoded): array
    {
        $result = [];
        foreach ($decoded as $row) {
            if (! is_array($row) || ! array_key_exists(1, $row)) {
                continue;
            }
            $result[] = (int) $row[1];
        }

        return $result;
    }

    private static function decodeJson(mixed $value): mixed
    {
        if (is_string($value)) {
            return json_decode($value, associative: true);
        }

        return $value;
    }
}
