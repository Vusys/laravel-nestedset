<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Vusys\NestedSet\Tests\Fixtures\Models\CustomColumnsBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Smoke test for models that rename `lft` / `rgt` / `depth` /
 * `parent_id` via the trait's `getLftName` etc. overrides. Walks the
 * mutation surface end-to-end and asserts the aggregate columns stay
 * consistent — any SQL builder that hardcoded the default column
 * names will fail here.
 */
final class CustomColumnsBranchTest extends TestCase
{
    public function test_full_mutation_lifecycle_keeps_aggregates_consistent(): void
    {
        // Plant a small tree.
        $root = new CustomColumnsBranch(['name' => 'root', 'tickets' => 0, 'active' => 1]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $a = new CustomColumnsBranch(['name' => 'A', 'tickets' => 10, 'active' => 1]);
        $a->appendToNode($root)->save();

        $b = new CustomColumnsBranch(['name' => 'B', 'tickets' => 20, 'active' => 1]);
        $b->appendToNode($root->refresh())->save();

        $c = new CustomColumnsBranch(['name' => 'C', 'tickets' => 30, 'active' => 0]);
        $c->appendToNode($root->refresh())->save();

        $a1 = new CustomColumnsBranch(['name' => 'A1', 'tickets' => 5, 'active' => 1]);
        $a1->appendToNode($a->refresh())->save();

        $root->refresh();

        // Baseline assertions: A + B + C + A1 in descendants, sum = 65.
        $this->assertSame(65, $root->descendants_total);
        $this->assertSame(4, $root->descendants_count);
        $this->assertSame(30, $root->descendants_max);
        $this->assertSame(65, $root->tickets_total);
        $this->assertSame(35, $root->active_tickets_total, 'A(10) + B(20) + A1(5); C inactive');

        // Read every node back and verify structural columns landed in
        // the renamed slots — i.e. the model's getLftName /
        // getRgtName / getDepthName / getParentIdName overrides took
        // effect end-to-end.
        $a->refresh();
        $this->assertGreaterThan(0, $a->tree_lft);
        $this->assertGreaterThan($a->tree_lft, $a->tree_rgt);
        $this->assertSame(1, $a->tree_depth);
        $this->assertSame((int) $root->id, $a->tree_parent_id);

        // Mutate a source column → delta path.
        $b = CustomColumnsBranch::query()->where('name', 'B')->firstOrFail();
        $b->tickets = 100;
        $b->save();

        $root->refresh();
        $this->assertSame(145, $root->tickets_total, '0 + 10 + 100 + 30 + 5');
        $this->assertSame(115, $root->active_tickets_total, '10 + 100 + 5');

        // Move A1 from under A to under C.
        $a1 = CustomColumnsBranch::query()->where('name', 'A1')->firstOrFail();
        $c = CustomColumnsBranch::query()->where('name', 'C')->firstOrFail();
        $a1->appendToNode($c)->save();

        $root->refresh();
        $a = CustomColumnsBranch::query()->where('name', 'A')->firstOrFail();
        $c = CustomColumnsBranch::query()->where('name', 'C')->firstOrFail();

        $this->assertSame(145, $root->tickets_total, 'root sum unchanged by intra-tree move');
        $this->assertSame(10, $a->tickets_total, 'A no longer has A1');
        $this->assertSame(35, $c->tickets_total, 'C now contains A1 (30 + 5)');

        // Force-delete a leaf and verify the chain settles.
        $a1 = CustomColumnsBranch::query()->where('name', 'A1')->firstOrFail();
        $a1->forceDelete();

        $root->refresh();
        $this->assertSame(140, $root->tickets_total);
        $this->assertSame(3, $root->descendants_count);
        $this->assertSame(110, $root->active_tickets_total, 'A1 was active; dropped 5');

        // Aggregate drift detection over the renamed table.
        $this->assertFalse(CustomColumnsBranch::aggregatesAreBroken());

        // Tree integrity check on the renamed columns.
        $this->assertFalse(CustomColumnsBranch::isBroken());
    }

    public function test_fix_aggregates_repairs_drift_on_renamed_columns(): void
    {
        // Seed a small tree.
        $root = new CustomColumnsBranch(['name' => 'root', 'tickets' => 0, 'active' => 1]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $a = new CustomColumnsBranch(['name' => 'A', 'tickets' => 10, 'active' => 1]);
        $a->appendToNode($root)->save();

        $b = new CustomColumnsBranch(['name' => 'B', 'tickets' => 20, 'active' => 1]);
        $b->appendToNode($root->refresh())->save();

        // Corrupt the stored aggregate values directly to simulate drift.
        \DB::table('custom_column_branches')->where('name', 'root')->update([
            'tickets_total' => 9999,
            'descendants_count' => 42,
            'descendants_total' => 9999,
        ]);

        $this->assertTrue(CustomColumnsBranch::aggregatesAreBroken());

        $result = CustomColumnsBranch::fixAggregates();

        $this->assertSame(30, $root->refresh()->tickets_total);
        $this->assertSame(2, $root->descendants_count);
        $this->assertSame(30, $root->descendants_total);
        $this->assertFalse(CustomColumnsBranch::aggregatesAreBroken());
        $this->assertGreaterThan(0, $result->totalRowsUpdated);
    }
}
