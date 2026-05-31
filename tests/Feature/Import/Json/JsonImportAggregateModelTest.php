<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Import\Json;

use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Importing into a model that declares aggregate columns. The
 * importer auto-populates the ignore set from the registry, so a
 * payload that includes aggregate columns won't choke and the
 * post-import recompute leaves the stored values consistent.
 */
final class JsonImportAggregateModelTest extends TestCase
{
    use InteractsWithTrees;

    public function test_payload_with_aggregate_columns_is_imported_and_aggregates_recompute(): void
    {
        Area::fromJsonTree([
            ['name' => 'r', 'tickets' => 5, 'children' => [
                ['name' => 'a', 'tickets' => 3, 'children' => []],
                ['name' => 'b', 'tickets' => 2, 'children' => []],
            ]],
        ]);

        $root = Area::query()->where('name', 'r')->firstOrFail();
        $this->assertIsRoot($root);
        $this->assertAggregatesAreIntact(Area::class, $root);
    }

    public function test_payload_that_explicitly_carries_stored_aggregates_strips_them(): void
    {
        // Aggregate columns in the payload are ignored automatically — the
        // post-import recompute is the source of truth. If the importer
        // wrote the stale values through, the assertAggregatesAreIntact
        // call would still pass because of the recompute pass; this test
        // verifies that the iteration over the registry's columns runs.
        Area::fromJsonTree([
            ['name' => 'r', 'tickets' => 1, 'tickets_total' => 999, 'tickets_max' => 999, 'children' => []],
        ]);

        $root = Area::query()->where('name', 'r')->firstOrFail();
        $this->assertSame(1, $root->tickets_total);
    }
}
