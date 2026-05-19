<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Tests\Fixtures\Models\Tag;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Pins that every package SQL path resolves the model's primary-key
 * column via `getKeyName()` rather than the hardcoded literal 'id'.
 * The Tag fixture uses `tag_id`; if any path leaks the literal, these
 * tests will diverge from the default-PK behaviour or error outright.
 *
 * Covers:
 *  - TreeMutationBuilder (`getPlainNodeData`, gap shifts during move).
 *  - TreeRepairBuilder (`countErrors` orphan join, `rebuildTree` /
 *    `rebuildSubtree`, the bulk `UPDATE ... CASE id WHEN ... END`
 *    writeback).
 *  - TreeAggregateBuilder (`fixAggregates`, `aggregateErrors`,
 *    chain-fold fast-path, grouped-aggregate query, bulk recompute
 *    writeback).
 *  - RecomputeMaintenance (`writeRecomputedValues`).
 *  - HasNestedSetAggregates (listener chain recompute, fix listener
 *    aggregates, chunked anchor lookup — covered indirectly through
 *    the SQL paths above; Tag declares no listener aggregates so the
 *    listener-only paths are exercised by future fixtures).
 */
final class CustomPrimaryKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! DB::connection()->getSchemaBuilder()->hasTable('tags')) {
            $this->markTestSkipped('tags table not created — migration ordering issue.');
        }
    }

    // ----------------------------------------------------------------
    // Inserts and moves work
    // ----------------------------------------------------------------

    public function test_save_as_root_uses_custom_primary_key(): void
    {
        $root = new Tag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $this->assertSame('Root', $root->name);
        $this->assertSame(1, $root->lft);
        $this->assertSame(2, $root->rgt);
        $this->assertGreaterThan(0, $root->tag_id);
    }

    public function test_append_to_node_resolves_via_custom_pk(): void
    {
        $root = new Tag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $child = new Tag(['name' => 'Child', 'tickets' => 5]);
        $child->appendToNode($root)->save();
        $child = $child->refresh();
        $root = $root->refresh();

        $this->assertSame(1, $root->lft);
        $this->assertSame(4, $root->rgt);
        $this->assertSame(2, $child->lft);
        $this->assertSame(3, $child->rgt);
        $this->assertSame($root->tag_id, $child->parent_id);
    }

    public function test_move_existing_node_via_custom_pk_keeps_tree_intact(): void
    {
        // Root > A > AA, plus Root > B. Move AA from under A to under B.
        $root = new Tag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $a = (new Tag(['name' => 'A', 'tickets' => 0]))->appendToNode($root->refresh())->save() ? Tag::query()->where('name', 'A')->firstOrFail() : null;
        $b = (new Tag(['name' => 'B', 'tickets' => 0]))->appendToNode($root->refresh())->save() ? Tag::query()->where('name', 'B')->firstOrFail() : null;
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $aa = new Tag(['name' => 'AA', 'tickets' => 7]);
        $aa->appendToNode($a->refresh())->save();
        $aa = $aa->refresh();

        $aa->appendToNode($b->refresh())->save();
        $aa = $aa->refresh();

        // AA should now be a descendant of B, not A.
        $this->assertSame($b->tag_id, $aa->parent_id);
        $this->assertFalse(Tag::isBroken());
    }

    // ----------------------------------------------------------------
    // Repair paths
    // ----------------------------------------------------------------

    public function test_fix_tree_repairs_via_custom_pk(): void
    {
        DB::table('tags')->insert([
            ['tag_id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 6, 'depth' => 0, 'parent_id' => null, 'tickets' => 0, 'tickets_total' => 0],
            ['tag_id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 1, 'tickets' => 5, 'tickets_total' => 5],
            ['tag_id' => 3, 'name' => 'B',    'lft' => 4, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1, 'tickets' => 3, 'tickets_total' => 3],
        ]);
        $this->syncSequence('tags', 'tag_id');

        // Corrupt the bounds on A — swap lft/rgt.
        DB::table('tags')->where('tag_id', 2)->update(['lft' => 3, 'rgt' => 2]);

        $errors = Tag::countErrors();
        $this->assertGreaterThan(0, $errors['invalid_bounds']);

        $result = Tag::fixTree();

        $this->assertSame(0, array_sum($result->errors));
        $this->assertSame(0, array_sum(Tag::countErrors()));
    }

    public function test_orphan_detection_uses_custom_pk(): void
    {
        // Insert a child whose parent_id points at a non-existent tag_id.
        // The orphan query joins parent.{pk} = child.parent_id; a hardcoded
        // 'id' would either find nothing (PG: error) or look at the wrong
        // column.
        DB::table('tags')->insert([
            ['tag_id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 4, 'depth' => 0, 'parent_id' => null, 'tickets' => 0, 'tickets_total' => 0],
            ['tag_id' => 2, 'name' => 'Orphan', 'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 999, 'tickets' => 0, 'tickets_total' => 0],
        ]);
        $this->syncSequence('tags', 'tag_id');

        $this->allowBrokenTreeAtTearDown = true;

        $errors = Tag::countErrors();
        $this->assertSame(1, $errors['orphans']);
    }

    // ----------------------------------------------------------------
    // Aggregate maintenance + repair
    // ----------------------------------------------------------------

    public function test_aggregate_delta_path_uses_custom_pk(): void
    {
        $root = new Tag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $child = new Tag(['name' => 'Child', 'tickets' => 5]);
        $child->appendToNode($root)->save();
        $root = $root->refresh();

        $this->assertSame(5, (int) $root->tickets_total);

        $child->tickets = 12;
        $child->save();
        $root = $root->refresh();

        $this->assertSame(12, (int) $root->tickets_total);
    }

    public function test_fix_aggregates_repairs_via_custom_pk(): void
    {
        $root = new Tag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $child = new Tag(['name' => 'Child', 'tickets' => 5]);
        $child->appendToNode($root)->save();

        // Drift via raw UPDATE — fixAggregates() does the SELECT + bulk
        // `CASE {pk} WHEN ? THEN ?` UPDATE that the hardcoded 'id'
        // would break.
        DB::table('tags')->where('tag_id', $child->tag_id)->update(['tickets' => 99]);

        $this->assertTrue(Tag::aggregatesAreBroken());

        $result = Tag::fixAggregates();
        $this->assertGreaterThan(0, $result->totalRowsUpdated);

        $root = $root->refresh();
        $this->assertSame(99, (int) $root->tickets_total);
        $this->assertFalse(Tag::aggregatesAreBroken());
    }

    public function test_fix_aggregates_chunked_path_uses_custom_pk(): void
    {
        // The chunked path issues `SELECT {pk} ... ORDER BY {pk} LIMIT N`
        // and feeds the returned ids back into the bulk UPDATE — a third
        // place the PK column has to be right.
        $root = new Tag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        for ($i = 0; $i < 5; $i++) {
            $child = new Tag(['name' => "C{$i}", 'tickets' => $i + 1]);
            $child->appendToNode($root->refresh())->save();
        }

        // Drift every child via raw UPDATE.
        DB::table('tags')->where('name', 'like', 'C%')->update(['tickets' => DB::raw('tickets + 10')]);
        $this->assertTrue(Tag::aggregatesAreBroken());

        $result = Tag::fixAggregates(chunkSize: 2);

        $this->assertFalse(Tag::aggregatesAreBroken());
        $this->assertGreaterThan(0, $result->totalRowsUpdated);
    }

    // ----------------------------------------------------------------
    // Hard-delete leaf compaction
    // ----------------------------------------------------------------

    public function test_hard_delete_leaf_closes_gap_via_custom_pk(): void
    {
        $root = new Tag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $a = new Tag(['name' => 'A', 'tickets' => 0]);
        $a->appendToNode($root->refresh())->save();
        $b = new Tag(['name' => 'B', 'tickets' => 0]);
        $b->appendToNode($root->refresh())->save();

        $a->refresh()->delete();

        $root = $root->refresh();
        $b = $b->refresh();
        // After the gap closes: Root(1,4), B(2,3).
        $this->assertSame(1, $root->lft);
        $this->assertSame(4, $root->rgt);
        $this->assertSame(2, $b->lft);
        $this->assertSame(3, $b->rgt);
        $this->assertFalse(Tag::isBroken());
    }
}
