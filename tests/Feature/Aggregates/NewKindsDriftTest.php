<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\TextJsonArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Drift detection + repair for the four non-numeric aggregate kinds.
 * Each test corrupts a stored aggregate column directly, asserts the
 * drift is reported, runs `fixAggregates`, and asserts the second run
 * is a no-op (idempotency).
 */
final class NewKindsDriftTest extends TestCase
{
    /**
     * The fixture tearDown integrity check fails after we've manually
     * corrupted a stored column — the `tearDown()` snapshot would
     * read the column we just wrote a wrong value into and refuse to
     * pass. Skip the check on these tests.
     */
    protected bool $allowBrokenTreeAtTearDown = true;

    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    private function buildFixture(): TextJsonArea
    {
        $root = new TextJsonArea(['name' => 'Root', 'owner' => 'Alice', 'tag' => 'red']);
        $root->saveAsRoot();

        $a = new TextJsonArea(['name' => 'A', 'owner' => 'Bob', 'tag' => 'red']);
        $a->appendToNode($root)->save();

        $b = new TextJsonArea(['name' => 'B', 'owner' => 'Carol', 'tag' => 'blue']);
        $b->appendToNode($root)->save();

        return $root->refresh();
    }

    public function test_distinct_count_drift_is_detected_and_repaired(): void
    {
        $root = $this->buildFixture();
        DB::table('text_json_areas')->where('id', $root->getKey())
            ->update(['distinct_owners' => 999]);

        $errors = TextJsonArea::aggregateErrors();
        $this->assertGreaterThan(0, $errors['distinct_owners'] ?? 0);

        TextJsonArea::fixAggregates();
        $root->refresh();
        $this->assertSame(3, (int) $root->distinct_owners);

        // Idempotent: second fix changes nothing.
        $result = TextJsonArea::fixAggregates();
        $this->assertSame(0, $result->totalRowsUpdated);
    }

    public function test_string_agg_drift_is_detected_and_repaired(): void
    {
        $root = $this->buildFixture();
        DB::table('text_json_areas')->where('id', $root->getKey())
            ->update(['child_names' => 'GARBAGE']);

        $errors = TextJsonArea::aggregateErrors();
        $this->assertGreaterThan(0, $errors['child_names'] ?? 0);

        TextJsonArea::fixAggregates();
        $root->refresh();
        $this->assertNotSame('GARBAGE', $root->child_names);
    }

    public function test_distinct_string_agg_with_reordered_values_does_not_report_drift(): void
    {
        $root = $this->buildFixture();
        // SQLite preserves insertion order; PG/MySQL would emit ORDER BY.
        // For the distinct stringAgg comparator we split + sort + compare,
        // so the *same* set in a different order is equivalent.
        $currentValue = $root->distinct_tags;
        $this->assertIsString($currentValue);
        $segments = preg_split('/,\s?/', $currentValue) ?: [];
        $reversed = implode(',', array_reverse($segments));

        DB::table('text_json_areas')->where('id', $root->getKey())
            ->update(['distinct_tags' => $reversed]);

        // Sorted-set compare → no drift.
        $errors = TextJsonArea::aggregateErrors();
        $this->assertSame(0, $errors['distinct_tags'] ?? 0);
    }

    public function test_json_agg_drift_is_detected_and_repaired(): void
    {
        $root = $this->buildFixture();
        DB::table('text_json_areas')->where('id', $root->getKey())
            ->update(['descendant_ids' => '[999, 998]']);

        $errors = TextJsonArea::aggregateErrors();
        $this->assertGreaterThan(0, $errors['descendant_ids'] ?? 0);

        TextJsonArea::fixAggregates();
        $root->refresh();
        $ids = $root->descendant_ids;
        $this->assertIsArray($ids);
        $this->assertNotContains(999, $ids);
    }

    public function test_json_agg_with_semantically_equal_but_byte_different_json_is_not_drift(): void
    {
        $root = $this->buildFixture();
        // Write the same logical value with extra whitespace — should still
        // match because the comparator decodes both sides.
        $currentValue = $root->descendant_ids;
        $this->assertIsArray($currentValue);
        $reformatted = json_encode($currentValue, JSON_PRETTY_PRINT);
        $this->assertIsString($reformatted);

        DB::table('text_json_areas')->where('id', $root->getKey())
            ->update(['descendant_ids' => $reformatted]);

        $errors = TextJsonArea::aggregateErrors();
        $this->assertSame(0, $errors['descendant_ids'] ?? 0);
    }

    public function test_json_object_agg_drift_is_detected_and_repaired(): void
    {
        $root = $this->buildFixture();
        DB::table('text_json_areas')->where('id', $root->getKey())
            ->update(['name_lookup' => '{"WRONG": "bad"}']);

        $errors = TextJsonArea::aggregateErrors();
        $this->assertGreaterThan(0, $errors['name_lookup'] ?? 0);

        TextJsonArea::fixAggregates();
        $root->refresh();
        $lookup = $root->name_lookup;
        $this->assertIsArray($lookup);
        $this->assertArrayNotHasKey('WRONG', $lookup);
        $this->assertArrayHasKey('Root', $lookup);
    }

    public function test_json_object_agg_with_reordered_keys_is_not_drift(): void
    {
        $root = $this->buildFixture();
        $currentValue = $root->name_lookup;
        $this->assertIsArray($currentValue);

        // Reverse the order of the keys but keep the same key→value pairs.
        $reorderedKeys = array_reverse($currentValue, preserve_keys: true);
        $reorderedJson = json_encode($reorderedKeys);
        $this->assertIsString($reorderedJson);

        DB::table('text_json_areas')->where('id', $root->getKey())
            ->update(['name_lookup' => $reorderedJson]);

        $errors = TextJsonArea::aggregateErrors();
        $this->assertSame(0, $errors['name_lookup'] ?? 0);
    }
}
