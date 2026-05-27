<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Strategy\RecomputeMaintenance;
use Vusys\NestedSet\Tests\Fixtures\Models\TextJsonArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Standard lifecycle matrix for the four collection-aggregate kinds:
 * create / move / delete / withFreshAggregates parity.
 *
 * All four go through {@see RecomputeMaintenance}
 * (delta is unsupported); these tests verify the stored columns
 * converge to the SQL-fresh values after each operation.
 */
final class CollectionAggregatesMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    /**
     * Builds a small fixture tree:
     *
     *   Root (Alice / tag=red)
     *   ├── A   (Bob   / tag=red)
     *   │   └── A1 (Alice / tag=blue)
     *   └── B   (Carol / tag=green)
     */
    private function buildFixture(): TextJsonArea
    {
        $root = new TextJsonArea(['name' => 'Root', 'owner' => 'Alice', 'tag' => 'red']);
        $root->saveAsRoot();

        $a = new TextJsonArea(['name' => 'A', 'owner' => 'Bob', 'tag' => 'red']);
        $a->appendToNode($root)->save();

        $a1 = new TextJsonArea(['name' => 'A1', 'owner' => 'Alice', 'tag' => 'blue']);
        $a1->appendToNode($a)->save();

        $b = new TextJsonArea(['name' => 'B', 'owner' => 'Carol', 'tag' => 'green']);
        $b->appendToNode($root)->save();

        return $root->refresh();
    }

    public function test_distinct_count_after_create(): void
    {
        $root = $this->buildFixture();

        // Subtree owners: Alice (Root), Bob (A), Alice (A1), Carol (B) = 3 distinct.
        $this->assertSame(3, (int) $root->distinct_owners);
    }

    public function test_string_agg_concatenates_descendant_names(): void
    {
        $root = $this->buildFixture();

        // Inclusive: includes Root itself; output ordering is backend-defined
        // on SQLite (GROUP_CONCAT ignores ORDER BY), so check membership.
        $names = explode(', ', (string) $root->child_names);
        sort($names);
        $this->assertSame(['A', 'A1', 'B', 'Root'], $names);
    }

    public function test_string_agg_distinct_collapses_duplicates(): void
    {
        $root = $this->buildFixture();

        // Tags: red (root), red (A), blue (A1), green (B). Three distinct,
        // collapsed via DISTINCT — on SQLite the separator collapses to no
        // space (see AggregateSqlEmitter caveat). Split on comma to compare.
        $tags = preg_split('/,\s?/', (string) $root->distinct_tags) ?: [];
        sort($tags);
        $this->assertSame(['blue', 'green', 'red'], $tags);
    }

    public function test_json_agg_scalar_form_array_of_ids(): void
    {
        $root = $this->buildFixture();

        $ids = $root->descendant_ids;
        $this->assertIsArray($ids);
        $this->assertCount(4, $ids);
    }

    public function test_json_agg_multi_column_array_of_objects(): void
    {
        $root = $this->buildFixture();

        $summary = $root->descendant_summary;
        $this->assertIsArray($summary);
        $this->assertCount(4, $summary);

        // Each entry must have id and name keys.
        foreach ($summary as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('name', $row);
        }
    }

    public function test_json_object_agg_builds_lookup_map(): void
    {
        $root = $this->buildFixture();

        $lookup = $root->name_lookup;
        $this->assertIsArray($lookup);

        // Every descendant's name → tag should appear (4 unique names → 4 entries).
        $this->assertArrayHasKey('Root', $lookup);
        $this->assertArrayHasKey('A', $lookup);
        $this->assertArrayHasKey('A1', $lookup);
        $this->assertArrayHasKey('B', $lookup);
        $this->assertSame('red', $lookup['A']);
        $this->assertSame('green', $lookup['B']);
    }

    public function test_delete_recomputes_descendant_aggregates_on_ancestors(): void
    {
        $root = $this->buildFixture();
        $b = TextJsonArea::query()->where('name', 'B')->firstOrFail();
        $b->delete();

        $root->refresh();
        // Owners left: Alice (root), Bob (A), Alice (A1). 2 distinct.
        $this->assertSame(2, (int) $root->distinct_owners);

        $names = explode(', ', (string) $root->child_names);
        sort($names);
        $this->assertSame(['A', 'A1', 'Root'], $names);
    }

    public function test_move_recomputes_aggregates_on_old_and_new_chain(): void
    {
        $this->buildFixture();
        $a = TextJsonArea::query()->where('name', 'A')->firstOrFail();
        $b = TextJsonArea::query()->where('name', 'B')->firstOrFail();

        // Move A under B. Now Root holds 4 descendants total (no change in set),
        // but the structural shape changes. distinct_owners under B itself
        // should now include Bob + Alice (A1).
        $a->appendToNode($b->refresh())->save();

        $b->refresh();
        $this->assertSame(3, (int) $b->distinct_owners); // Carol + Bob + Alice
    }

    public function test_with_fresh_aggregates_matches_stored_columns(): void
    {
        $root = $this->buildFixture();

        $fresh = TextJsonArea::query()
            ->whereKey($root->getKey())
            ->withFreshAggregates([
                'distinct_owners',
                'child_names',
                'distinct_tags',
                'descendant_ids',
                'descendant_summary',
                'name_lookup',
            ])
            ->firstOrFail();

        $freshOwners = $fresh->getAttribute('distinct_owners');
        $this->assertSame(
            (int) $root->distinct_owners,
            is_numeric($freshOwners) ? (int) $freshOwners : -1,
        );
        $this->assertSame($root->child_names, $fresh->getAttribute('child_names'));
        $this->assertSame($root->distinct_tags, $fresh->getAttribute('distinct_tags'));
    }
}
