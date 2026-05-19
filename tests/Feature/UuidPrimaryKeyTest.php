<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Vusys\NestedSet\Jobs\FixAggregatesJob;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\StuckCursorUuidTag;
use Vusys\NestedSet\Tests\Fixtures\Models\UuidMenu;
use Vusys\NestedSet\Tests\Fixtures\Models\UuidMenuItem;
use Vusys\NestedSet\Tests\Fixtures\Models\UuidTag;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Mirrors {@see CustomPrimaryKeyTest} for a UUID (string) primary key.
 * Covers the same code paths — mutation, repair, aggregate maintenance,
 * chunked repair cursor, bulk insert, job serialization — but with PK
 * values that never narrow to int.
 *
 * Laravel's {@see HasUuids}
 * generates UUIDv7 ids; the chunked-repair cursor walk relies on
 * monotonic insertion order, which UUIDv7 guarantees.
 */
final class UuidPrimaryKeyTest extends TestCase
{
    use InteractsWithTrees;

    protected function setUp(): void
    {
        parent::setUp();

        if (! DB::connection()->getSchemaBuilder()->hasTable('uuid_tags')) {
            $this->markTestSkipped('uuid_tags table not created — migration ordering issue.');
        }
    }

    // ----------------------------------------------------------------
    // Inserts and moves work
    // ----------------------------------------------------------------

    public function test_save_as_root_assigns_uuid(): void
    {
        $root = new UuidTag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $this->assertSame(36, strlen($root->id));
        $this->assertIsRoot($root);
        $this->assertIsLeaf($root);
        $this->assertTreeIsIntact(UuidTag::class);
    }

    public function test_append_to_node_resolves_via_uuid_pk(): void
    {
        $root = new UuidTag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $child = new UuidTag(['name' => 'Child', 'tickets' => 5]);
        $child->appendToNode($root)->save();
        $child = $child->refresh();
        $root = $root->refresh();

        $this->assertIsRoot($root);
        $this->assertIsLeaf($child);
        $this->assertIsChildOf($child, $root);
        $this->assertSame($root->id, $child->parent_id);
        $this->assertIsString($child->parent_id);
        $this->assertTreeIsIntact(UuidTag::class);
    }

    public function test_move_existing_node_via_uuid_pk_keeps_tree_intact(): void
    {
        $root = new UuidTag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();

        $a = new UuidTag(['name' => 'A', 'tickets' => 0]);
        $a->appendToNode($root->refresh())->save();
        $b = new UuidTag(['name' => 'B', 'tickets' => 0]);
        $b->appendToNode($root->refresh())->save();

        $aa = new UuidTag(['name' => 'AA', 'tickets' => 7]);
        $aa->appendToNode($a->refresh())->save();

        $aa->appendToNode($b->refresh())->save();
        $aa = $aa->refresh();
        $b = $b->refresh();

        $this->assertIsChildOf($aa, $b);
        $this->assertSame($b->id, $aa->parent_id);
        $this->assertTreeIsIntact(UuidTag::class);
    }

    // ----------------------------------------------------------------
    // Repair paths
    // ----------------------------------------------------------------

    public function test_fix_tree_rebuilds_uuid_keyed_tree(): void
    {
        $rootId = (string) Str::uuid7();
        $aId = (string) Str::uuid7();
        $bId = (string) Str::uuid7();

        DB::table('uuid_tags')->insert([
            ['id' => $rootId, 'name' => 'Root', 'lft' => 1, 'rgt' => 6, 'depth' => 0, 'parent_id' => null, 'tickets' => 0, 'tickets_total' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $aId,    'name' => 'A',    'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => $rootId, 'tickets' => 5, 'tickets_total' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $bId,    'name' => 'B',    'lft' => 4, 'rgt' => 5, 'depth' => 1, 'parent_id' => $rootId, 'tickets' => 3, 'tickets_total' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Corrupt A's bounds — swap lft/rgt.
        DB::table('uuid_tags')->where('id', $aId)->update(['lft' => 3, 'rgt' => 2]);

        $errors = UuidTag::countErrors();
        $this->assertGreaterThan(0, $errors['invalid_bounds']);

        $result = UuidTag::fixTree();

        $this->assertSame(0, array_sum($result->errors));
        $this->assertSame(0, array_sum(UuidTag::countErrors()));
    }

    public function test_orphan_detection_uses_uuid_pk(): void
    {
        $rootId = (string) Str::uuid7();
        $orphanId = (string) Str::uuid7();
        $missingParentId = (string) Str::uuid7();

        DB::table('uuid_tags')->insert([
            ['id' => $rootId,   'name' => 'Root',   'lft' => 1, 'rgt' => 4, 'depth' => 0, 'parent_id' => null,              'tickets' => 0, 'tickets_total' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $orphanId, 'name' => 'Orphan', 'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => $missingParentId,  'tickets' => 0, 'tickets_total' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->allowBrokenTreeAtTearDown = true;

        $errors = UuidTag::countErrors();
        $this->assertSame(1, $errors['orphans']);
    }

    // ----------------------------------------------------------------
    // Aggregate maintenance + repair
    // ----------------------------------------------------------------

    public function test_aggregate_delta_path_uses_uuid_pk(): void
    {
        $root = new UuidTag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $child = new UuidTag(['name' => 'Child', 'tickets' => 5]);
        $child->appendToNode($root)->save();
        $root = $root->refresh();

        $this->assertSame(5, $root->tickets_total);

        $child->tickets = 12;
        $child->save();
        $root = $root->refresh();

        $this->assertSame(12, $root->tickets_total);
    }

    public function test_fix_aggregates_resolves_uuid_anchor(): void
    {
        $root = new UuidTag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();

        $child = new UuidTag(['name' => 'Child', 'tickets' => 5]);
        $child->appendToNode($root->refresh())->save();

        // Drift via raw UPDATE — the CASE {pk} WHEN ? THEN ? path
        // must bind the UUID parameter, not coerce to int.
        DB::table('uuid_tags')->where('id', $child->id)->update(['tickets' => 99]);

        $this->assertTrue(UuidTag::aggregatesAreBroken());

        $result = UuidTag::fixAggregates();
        $this->assertGreaterThan(0, $result->totalRowsUpdated);

        $root = $root->refresh();
        $this->assertSame(99, $root->tickets_total);
        $this->assertFalse(UuidTag::aggregatesAreBroken());
    }

    public function test_fix_aggregates_chunked_walks_uuid_cursor(): void
    {
        // `fixAggregatesChunk` issues `WHERE id > ? ORDER BY id LIMIT N`.
        // UUIDv7 is monotonic, so the lexicographic ordering matches
        // insertion order and the cursor walk visits every row.
        $root = new UuidTag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();

        for ($i = 0; $i < 5; $i++) {
            $child = new UuidTag(['name' => "C{$i}", 'tickets' => $i + 1]);
            $child->appendToNode($root->refresh())->save();
        }

        DB::table('uuid_tags')->where('name', 'like', 'C%')->update(['tickets' => DB::raw('tickets + 10')]);
        $this->assertTrue(UuidTag::aggregatesAreBroken());

        $result = UuidTag::fixAggregates(chunkSize: 2);
        $this->assertGreaterThan(0, $result->totalRowsUpdated);
        $this->assertFalse(UuidTag::aggregatesAreBroken());
    }

    // ----------------------------------------------------------------
    // Bulk insert
    // ----------------------------------------------------------------

    public function test_bulk_insert_tree_under_uuid_anchor(): void
    {
        $root = new UuidTag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $saved = UuidTag::bulkInsertTree([
            ['name' => 'A', 'tickets' => 1, 'children' => [
                ['name' => 'AA', 'tickets' => 2],
            ]],
            ['name' => 'B', 'tickets' => 3],
        ], appendTo: $root);

        $this->assertCount(3, $saved);
        $root = $root->refresh();
        $this->assertSame(6, $root->tickets_total);
        $this->assertFalse(UuidTag::isBroken());
        $this->assertFalse(UuidTag::aggregatesAreBroken());

        // Every inserted row's parent_id (when non-null) is a UUID string.
        foreach ($saved as $node) {
            $node = $node->refresh();
            if ($node->parent_id !== null) {
                $this->assertIsString($node->parent_id);
                $this->assertSame(36, strlen($node->parent_id));
            }
        }
    }

    // ----------------------------------------------------------------
    // Job serialization
    // ----------------------------------------------------------------

    public function test_fix_aggregates_job_serialises_uuid_anchor(): void
    {
        $anchorId = (string) Str::uuid7();
        $cursorId = (string) Str::uuid7();

        $job = new FixAggregatesJob(
            modelClass: UuidTag::class,
            anchorId: $anchorId,
            chunkSize: 100,
            cursorAfterId: $cursorId,
        );

        /** @var FixAggregatesJob $unserialized */
        $unserialized = unserialize(serialize($job));

        $this->assertSame($anchorId, $unserialized->anchorId);
        $this->assertSame($cursorId, $unserialized->cursorAfterId);
        $this->assertSame(100, $unserialized->chunkSize);
        $this->assertSame(UuidTag::class, $unserialized->modelClass);
    }

    // ----------------------------------------------------------------
    // Scoped UUID model — both PK and scope column are UUID-typed
    // ----------------------------------------------------------------

    public function test_scoped_uuid_model_isolates_trees_per_menu(): void
    {
        $menuA = UuidMenu::create(['name' => 'A']);
        $menuB = UuidMenu::create(['name' => 'B']);

        $rootA = new UuidMenuItem(['name' => 'A-root', 'menu_id' => $menuA->id]);
        $rootA->saveAsRoot();
        $childA = new UuidMenuItem(['name' => 'A-child', 'menu_id' => $menuA->id]);
        $childA->appendToNode($rootA->refresh())->save();

        $rootB = new UuidMenuItem(['name' => 'B-root', 'menu_id' => $menuB->id]);
        $rootB->saveAsRoot();

        // Each scope starts its own coordinate space — both roots
        // are roots in their respective menus.
        $this->assertIsRoot($rootA->refresh());
        $this->assertIsRoot($rootB->refresh());
        $this->assertSame($menuA->id, $rootA->refresh()->menu_id);
        $this->assertSame($menuB->id, $rootB->refresh()->menu_id);
        $this->assertTreeIsIntact(UuidMenuItem::class, $rootA);
        $this->assertTreeIsIntact(UuidMenuItem::class, $rootB);
    }

    // ----------------------------------------------------------------
    // Listener aggregate path with UUID keys
    // ----------------------------------------------------------------

    public function test_chunked_listener_repair_walks_uuid_outer_ids(): void
    {
        // Pins the previous bug where `fixListenerAggregatesPhp` cast
        // each outer node's key to int before its in_array membership
        // check against the chunk's outer-ids list — every UUID key
        // collapsed to 0 and listener rows were skipped.
        $root = new UuidTag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();

        for ($i = 0; $i < 5; $i++) {
            $child = new UuidTag(['name' => "Child{$i}", 'tickets' => $i]);
            $child->appendToNode($root->refresh())->save();
        }

        // Force drift on the listener column so the chunked repair has
        // real work to do. Listener aggregates aren't built from a
        // simple SUM(source) so we drift them via raw UPDATE.
        DB::table('uuid_tags')->where('name', 'like', 'Child%')->update(['name_length_total' => 0]);
        DB::table('uuid_tags')->where('name', 'Root')->update(['name_length_total' => 0]);

        $this->assertTrue(UuidTag::aggregatesAreBroken());

        $result = UuidTag::fixAggregates(chunkSize: 2);
        $this->assertGreaterThan(0, $result->totalRowsUpdated);
        $this->assertFalse(UuidTag::aggregatesAreBroken());

        // Root's name_length_total = sum of strlen($name) across the
        // subtree = strlen('Root') + sum(strlen('ChildN')) = 4 + 5*6 = 34.
        $root = $root->refresh();
        $this->assertSame(34, $root->name_length_total);
    }

    // ----------------------------------------------------------------
    // fixTree / fixAggregates reject unsaved anchors
    // ----------------------------------------------------------------

    public function test_fix_tree_rejects_unsaved_anchor(): void
    {
        $unsaved = new UuidTag(['name' => 'Unsaved', 'tickets' => 0]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$anchor has no primary key');

        UuidTag::fixTree($unsaved);
    }

    public function test_fix_aggregates_rejects_unsaved_anchor(): void
    {
        $unsaved = new UuidTag(['name' => 'Unsaved', 'tickets' => 0]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$anchor has no primary key');

        UuidTag::fixAggregates($unsaved);
    }

    // ----------------------------------------------------------------
    // Stuck-cursor detection in the chunked repair loop
    // ----------------------------------------------------------------

    public function test_chunked_repair_aborts_when_cursor_does_not_advance(): void
    {
        // A buggy backend (or corrupted index) could return the same
        // `nextAfterId` forever. The chunk loop now detects the
        // non-progress and aborts; this test pins that behaviour by
        // substituting a `fixAggregatesChunk` that always returns the
        // same cursor.
        $root = new StuckCursorUuidTag(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cursor stuck');

        StuckCursorUuidTag::fixAggregates(chunkSize: 1);
    }
}
