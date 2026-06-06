<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\CustomColumnsBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Models that rename `lft` / `rgt` / `depth` / `parent_id` via the
 * trait's `getLftName()` etc. overrides must work end-to-end on
 * structural mutations AND aggregate maintenance. Any builder that
 * hardcoded the default column names would surface here.
 *
 * Each test owns one mutation kind so a regression points at the
 * specific code path that broke (a previous incarnation of this file
 * ran every mutation in one ~80-line test, which made diagnostic
 * output ambiguous).
 *
 * Tree shape used by `seedTree()`:
 *
 *   root (tickets=0, active=1)
 *   ├── A  (tickets=10, active=1)
 *   │   └── A1 (tickets=5, active=1)
 *   ├── B  (tickets=20, active=1)
 *   └── C  (tickets=30, active=0)
 */
final class CustomColumnsBranchTest extends TestCase
{
    /**
     * @return array{root: CustomColumnsBranch, a: CustomColumnsBranch, b: CustomColumnsBranch, c: CustomColumnsBranch, a1: CustomColumnsBranch}
     */
    private function seedTree(): array
    {
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

        return [
            'root' => $root->refresh(),
            'a' => $a->refresh(),
            'b' => $b->refresh(),
            'c' => $c->refresh(),
            'a1' => $a1->refresh(),
        ];
    }

    #[Test]
    public function initial_seed_aggregates_populate_correctly_on_renamed_columns(): void
    {
        $tree = $this->seedTree();
        $root = $tree['root'];

        // SUM/COUNT/MAX rolling up through the renamed bounds columns —
        // any builder ignoring getLftName/getRgtName would compute
        // wrong descendant sets here.
        $this->assertSame(65, $root->descendants_total, 'A + B + C + A1 = 65');
        $this->assertSame(4, $root->descendants_count);
        $this->assertSame(30, $root->descendants_max);
        $this->assertSame(65, $root->tickets_total);
        $this->assertSame(35, $root->active_tickets_total, 'A(10) + B(20) + A1(5); C inactive');

        // Renamed structural columns landed in the right slots.
        $a = $tree['a'];
        $this->assertGreaterThan(0, $a->tree_lft);
        $this->assertGreaterThan($a->tree_lft, $a->tree_rgt);
        $this->assertSame(1, $a->tree_depth);
        $this->assertSame((int) $root->id, $a->tree_parent_id);
    }

    #[Test]
    public function source_update_propagates_through_delta_path_on_renamed_columns(): void
    {
        $tree = $this->seedTree();
        $root = $tree['root'];

        // B.tickets 20 → 100. Delta path: ancestors gain 80.
        $b = CustomColumnsBranch::query()->where('name', 'B')->firstOrFail();
        $b->tickets = 100;
        $b->save();

        $root->refresh();
        $this->assertSame(145, $root->tickets_total, '0 + 10 + 100 + 30 + 5');
        $this->assertSame(115, $root->active_tickets_total, '10 + 100 + 5');
    }

    #[Test]
    public function intra_tree_move_relocates_subtree_aggregates_on_renamed_columns(): void
    {
        $tree = $this->seedTree();
        $root = $tree['root'];

        // Move A1 from under A to under C.
        $a1 = CustomColumnsBranch::query()->where('name', 'A1')->firstOrFail();
        $c = CustomColumnsBranch::query()->where('name', 'C')->firstOrFail();
        $a1->appendToNode($c)->save();

        $root->refresh();
        $a = CustomColumnsBranch::query()->where('name', 'A')->firstOrFail();
        $c = CustomColumnsBranch::query()->where('name', 'C')->firstOrFail();

        $this->assertSame(65, $root->tickets_total, 'root sum unchanged by intra-tree move');
        $this->assertSame(10, $a->tickets_total, 'A no longer has A1');
        $this->assertSame(35, $c->tickets_total, 'C now contains A1 (30 + 5)');
    }

    #[Test]
    public function force_delete_at_leaf_settles_ancestor_chain_on_renamed_columns(): void
    {
        $tree = $this->seedTree();
        $root = $tree['root'];

        // Force-delete A1 (active leaf). Drop 5 from ancestor sums.
        $a1 = CustomColumnsBranch::query()->where('name', 'A1')->firstOrFail();
        $a1->forceDelete();

        $root->refresh();
        $this->assertSame(60, $root->tickets_total, '65 - 5 = 60');
        $this->assertSame(3, $root->descendants_count);
        $this->assertSame(30, $root->active_tickets_total, 'A1 was active; dropped 5');

        // No drift surfaced by either tree- or aggregate-integrity check.
        $this->assertFalse(CustomColumnsBranch::aggregatesAreBroken());
        $this->assertFalse(CustomColumnsBranch::isBroken());
    }

    #[Test]
    public function fix_aggregates_repairs_drift_on_renamed_columns(): void
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
        DB::table('custom_column_branches')->where('name', 'root')->update([
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
