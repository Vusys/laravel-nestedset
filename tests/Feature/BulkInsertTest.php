<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Phase M v2: `Model::bulkInsertTree($tree, ?HasNestedSet $appendTo)`.
 *
 * v2 keeps every Eloquent guarantee the per-row `appendToNode->save()`
 * loop has (events, mutators, casts, mass-assignment, hydrated
 * return) while collapsing the O(N²) gap-shift cost to a single
 * `makeGap` + one `fixAggregates`. Most of these tests assert the
 * Laravel-side behaviour, not the perf — the perf is covered by the
 * benchmark in `tests/Performance/`.
 */
final class BulkInsertTest extends TestCase
{
    // ----------------------------------------------------------------
    // Shape correctness
    // ----------------------------------------------------------------

    public function test_empty_input_returns_empty_array_and_does_no_writes(): void
    {
        DB::enableQueryLog();
        $result = Area::bulkInsertTree([]);

        $this->assertSame([], $result);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_inserts_flat_forest_when_no_anchor(): void
    {
        $models = Area::bulkInsertTree([
            ['name' => 'r1', 'tickets' => 10],
            ['name' => 'r2', 'tickets' => 20],
        ]);

        $this->assertCount(2, $models);
        $this->assertSame(['r1', 'r2'], array_map(fn (Area $a): string => $a->name, $models));
        $this->assertSame([0, 0], array_map(fn (Area $a): int => $a->depth, $models));
        $this->assertSame([null, null], array_map(fn (Area $a): ?int => $a->parent_id, $models));

        // Sequential bounds: r1=(1,2), r2=(3,4).
        $this->assertSame([1, 3], array_map(fn (Area $a): int => $a->lft, $models));
        $this->assertSame([2, 4], array_map(fn (Area $a): int => $a->rgt, $models));
    }

    public function test_inserts_nested_tree_under_existing_anchor(): void
    {
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $inserted = Area::bulkInsertTree([
            ['name' => 'a', 'tickets' => 1, 'children' => [
                ['name' => 'a1', 'tickets' => 2],
                ['name' => 'a2', 'tickets' => 3],
            ]],
            ['name' => 'b', 'tickets' => 4],
        ], appendTo: $root);

        $this->assertCount(4, $inserted);

        // DFS pre-order: a, a1, a2, b
        $this->assertSame(['a', 'a1', 'a2', 'b'], array_map(fn (Area $a): string => $a->name, $inserted));

        // Parent ids:
        //   a's parent = root.id
        //   a1/a2's parent = a.id
        //   b's parent = root.id
        [$a, $a1, $a2, $b] = $inserted;
        $this->assertSame($root->getKey(), $a->parent_id);
        $this->assertSame($a->getKey(), $a1->parent_id);
        $this->assertSame($a->getKey(), $a2->parent_id);
        $this->assertSame($root->getKey(), $b->parent_id);

        // Depth: root=0, a/b=1, a1/a2=2
        $this->assertSame(1, $a->depth);
        $this->assertSame(2, $a1->depth);
        $this->assertSame(2, $a2->depth);
        $this->assertSame(1, $b->depth);

        // Tree is internally consistent after the operation
        $root->refresh();
        $this->assertSame(10, $root->rgt);
        $this->assertSame(1, $root->lft);
    }

    public function test_returns_hydrated_models_with_ids_and_attributes(): void
    {
        $models = Area::bulkInsertTree([
            ['name' => 'one', 'tickets' => 5],
        ]);

        $only = $models[0];
        $this->assertInstanceOf(Area::class, $only);
        $this->assertTrue($only->exists);
        $this->assertTrue($only->wasRecentlyCreated);
        $this->assertNotNull($only->getKey());
        $this->assertSame('one', $only->name);
        $this->assertSame(5, $only->tickets);
    }

    // ----------------------------------------------------------------
    // Eloquent semantics — events, casts, mass-assignment
    // ----------------------------------------------------------------

    public function test_eloquent_events_fire_per_row(): void
    {
        Event::fake();

        Area::bulkInsertTree([
            ['name' => 'a', 'tickets' => 1, 'children' => [
                ['name' => 'a1', 'tickets' => 2],
            ]],
            ['name' => 'b', 'tickets' => 3],
        ]);

        // 3 nodes, so each event class should fire 3 times.
        Event::assertDispatchedTimes('eloquent.creating: '.Area::class, 3);
        Event::assertDispatchedTimes('eloquent.created: '.Area::class, 3);
        Event::assertDispatchedTimes('eloquent.saving: '.Area::class, 3);
        Event::assertDispatchedTimes('eloquent.saved: '.Area::class, 3);
    }

    public function test_casts_apply_to_tree_columns_on_returned_models(): void
    {
        // Area::$casts maps lft/rgt/depth/parent_id/tickets to integer.
        // If casts were bypassed, the underlying attributes (DB driver
        // returns strings on PostgreSQL) would not match the int values
        // we set, and the equality assertions would catch it.
        $models = Area::bulkInsertTree([
            ['name' => 'x', 'tickets' => 7],
        ]);
        $only = $models[0];

        $this->assertSame(1, $only->lft);
        $this->assertSame(2, $only->rgt);
        $this->assertSame(0, $only->depth);
        $this->assertSame(7, $only->tickets);
        $this->assertNull($only->parent_id);
    }

    public function test_mass_assignment_respects_fillable(): void
    {
        // Area::$fillable = ['name', 'tickets']. Anything else passed via
        // the constructor must be silently dropped before save() — this
        // is the standard Eloquent contract and bulkInsertTree must not
        // bypass it.
        $models = Area::bulkInsertTree([
            ['name' => 'fillable-test', 'tickets' => 1, 'not_a_real_column' => 'ignored'],
        ]);

        $this->assertCount(1, $models);
        $this->assertSame('fillable-test', $models[0]->name);
        // No exception thrown — the unknown attribute was dropped at
        // mass-assign time, never reached the INSERT.
    }

    // ----------------------------------------------------------------
    // Reserved-attribute rejection
    // ----------------------------------------------------------------

    /**
     * @return array<string, list<string>>
     */
    public static function reservedAttributeProvider(): array
    {
        return [
            'lft' => ['lft'],
            'rgt' => ['rgt'],
            'depth' => ['depth'],
            'parent_id' => ['parent_id'],
            'id' => ['id'],
        ];
    }

    #[DataProvider('reservedAttributeProvider')]
    public function test_rejects_reserved_attribute_keys(string $reserved): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reserved);

        Area::bulkInsertTree([
            ['name' => 'x', 'tickets' => 0, $reserved => 999],
        ]);
    }

    public function test_rejects_non_array_branch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Area::bulkInsertTree(['this is not an array']); /** @phpstan-ignore-line */
    }

    public function test_rejects_non_array_children(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Area::bulkInsertTree([
            ['name' => 'x', 'tickets' => 0, 'children' => 'not-an-array'],
        ]);
    }

    // ----------------------------------------------------------------
    // Aggregate correctness end-to-end
    // ----------------------------------------------------------------

    public function test_aggregate_columns_are_correct_after_bulk_insert(): void
    {
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Area::bulkInsertTree([
            ['name' => 'a', 'tickets' => 10, 'children' => [
                ['name' => 'a1', 'tickets' => 5],
            ]],
            ['name' => 'b', 'tickets' => 20],
        ], appendTo: $root);

        $root->refresh();

        // tickets_total = SUM over subtree (self + descendants):
        //   root contains 0 + 10 + 5 + 20 = 35
        $this->assertSame(35, $root->tickets_total);
        $this->assertSame(4, $root->tickets_count_all);
        $this->assertSame(20, $root->tickets_max);
        $this->assertSame(0, $root->tickets_min);

        $this->assertFalse(Area::aggregatesAreBroken());
    }

    public function test_aggregates_on_ancestors_above_the_anchor_are_refreshed(): void
    {
        // Regression: the post-bulk fixAggregates pass needs to cover
        // the anchor's ancestors, not just the inserted subtree. If it
        // only fixes the subtree rooted at $appendTo, ancestors keep
        // their stale stored aggregates (descendant count + sums) and
        // we silently drift.
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        // Mid is the anchor — it sits between the new rows and the root.
        $mid = new Area(['name' => 'mid', 'tickets' => 0]);
        $mid->appendToNode($root)->save();
        $mid->refresh();

        Area::bulkInsertTree([
            ['name' => 'x', 'tickets' => 7],
            ['name' => 'y', 'tickets' => 13],
        ], appendTo: $mid);

        $root->refresh();
        $mid->refresh();

        // Root contains: root(0) + mid(0) + x(7) + y(13) = 20, count 4.
        $this->assertSame(20, $root->tickets_total);
        $this->assertSame(4, $root->tickets_count_all);
        $this->assertSame(13, $root->tickets_max);

        // Mid contains: mid(0) + x(7) + y(13) = 20, count 3.
        $this->assertSame(20, $mid->tickets_total);
        $this->assertSame(3, $mid->tickets_count_all);

        $this->assertFalse(Area::aggregatesAreBroken());
    }

    // ----------------------------------------------------------------
    // Transactional rollback
    // ----------------------------------------------------------------

    public function test_failure_inside_save_rolls_back_the_whole_operation(): void
    {
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        // The third row's `creating` listener throws — bulkInsertTree
        // wraps the loop in a transaction, so the first two saved rows
        // must be rolled back and the gap must be closed again.
        Area::creating(static function (Area $area): void {
            if ($area->name === 'boom') {
                throw new RuntimeException('halt');
            }
        });

        try {
            Area::bulkInsertTree([
                ['name' => 'ok-1', 'tickets' => 1],
                ['name' => 'ok-2', 'tickets' => 2],
                ['name' => 'boom', 'tickets' => 3],
            ], appendTo: $root);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            // Expected.
        } finally {
            // Drop just the listener we registered — tearDown's
            // tree-integrity check on later tests would otherwise
            // re-trigger our throw.
            Event::forget('eloquent.creating: '.Area::class);
        }

        $root->refresh();
        $this->assertSame(2, $root->rgt); // gap was closed on rollback
        $this->assertSame(0, Area::query()->whereIn('name', ['ok-1', 'ok-2', 'boom'])->count());
    }

    // ----------------------------------------------------------------
    // Scope handling
    // ----------------------------------------------------------------

    public function test_scoped_model_without_anchor_throws(): void
    {
        $this->expectException(ScopeViolationException::class);

        MenuItem::bulkInsertTree([
            ['name' => 'orphan'],
        ]);
    }

    public function test_scoped_model_with_valid_anchor_inserts_under_anchor(): void
    {
        // Pins the happy-path inverse of the no-anchor case above. The
        // scope guard at the top of bulkInsertTree should NOT trip
        // when the anchor is a same-class persisted node — without
        // this case, mutating the `instanceof HasNestedSet` check to
        // its always-false form (always-throw) escapes, because the
        // no-anchor and wrong-class tests also expect a throw and
        // can't tell apart "throw for the right reason" from "throw
        // for the wrong reason".
        $menu = Menu::create(['name' => 'Sidebar']);
        $root = new MenuItem(['name' => 'Root', 'menu_id' => $menu->id]);
        $root->saveAsRoot();
        $root = $root->refresh();

        $inserted = MenuItem::bulkInsertTree([
            ['name' => 'a'],
            ['name' => 'b'],
        ], appendTo: $root);

        $this->assertCount(2, $inserted);
        $this->assertSame(['a', 'b'], array_map(fn (MenuItem $m): string => $m->name, $inserted));
        foreach ($inserted as $item) {
            $this->assertSame($menu->id, $item->menu_id);
            $this->assertSame($root->getKey(), $item->parent_id);
        }
    }

    public function test_unsaved_anchor_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('persisted');

        Area::bulkInsertTree(
            [['name' => 'x', 'tickets' => 0]],
            appendTo: new Area(['name' => 'unsaved', 'tickets' => 0]),
        );
    }

    public function test_bulk_insert_with_stale_anchor_uses_in_memory_bounds(): void
    {
        // Footgun: bulkInsertTree reads $appendTo->getRgt() from the
        // in-memory model and trusts it. When the caller's reference
        // to the anchor predates a separate save() that shifted the
        // anchor's bounds, the bulk insert anchors at the stale slot.
        // Tree integrity is preserved by makeGap + relative bounds
        // arithmetic, but new children land in pre-existing-children
        // sibling order rather than after them.
        //
        // Pins the current behaviour so the contract change (always
        // refresh before bulkInsertTree, or have the package do it
        // internally) is made deliberately.
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root->refresh();

        // Append a sibling — root's DB rgt shifts to 4, but the
        // caller's $root in-memory still holds rgt=2.
        (new Area(['name' => 'existing', 'tickets' => 1]))
            ->appendToNode($root)
            ->save();

        $this->assertSame(2, $root->rgt, 'sanity: in-memory anchor is stale');

        Area::bulkInsertTree(
            [
                ['name' => 'bulk1', 'tickets' => 10],
                ['name' => 'bulk2', 'tickets' => 20],
            ],
            appendTo: $root,
        );

        $children = Area::query()
            ->where('parent_id', $root->id)
            ->orderBy('lft')
            ->pluck('name')
            ->all();

        // Current behaviour: bulk children land BEFORE 'existing' in
        // lft order because the stale rgt was used as the gap position.
        $this->assertSame(['bulk1', 'bulk2', 'existing'], $children,
            'stale anchor places bulk inserts at the stashed lft/rgt slot — workaround is $root->refresh() before bulkInsertTree',
        );

        $this->assertFalse(Area::isBroken(), 'tree remains integral despite the stale anchor');
    }

    public function test_bulk_insert_with_refreshed_anchor_appends_after_existing_children(): void
    {
        // Mirror image of the stale-anchor test: when the caller
        // refreshes the anchor, bulkInsertTree picks up the live rgt
        // and bulk children land after any earlier siblings.
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root->refresh();

        (new Area(['name' => 'existing', 'tickets' => 1]))
            ->appendToNode($root)
            ->save();

        $root->refresh(); // the documented workaround

        Area::bulkInsertTree(
            [
                ['name' => 'bulk1', 'tickets' => 10],
                ['name' => 'bulk2', 'tickets' => 20],
            ],
            appendTo: $root,
        );

        $children = Area::query()
            ->where('parent_id', $root->id)
            ->orderBy('lft')
            ->pluck('name')
            ->all();

        $this->assertSame(['existing', 'bulk1', 'bulk2'], $children,
            'refreshed anchor appends bulk children after pre-existing siblings',
        );
        $this->assertFalse(Area::isBroken());
    }

    public function test_cross_class_anchor_rejected(): void
    {
        // Persist a non-Area anchor so it passes the `exists` gate.
        // Without the class guard, bulkInsertTree would read this Category's
        // bounds and copy them onto rows in the `areas` table — silently
        // anchoring inserts against the wrong table.
        $category = new Category(['name' => 'root']);
        $category->saveAsRoot();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an instance of');

        Area::bulkInsertTree(
            [['name' => 'x', 'tickets' => 0]],
            appendTo: $category,
        );
    }

    // ----------------------------------------------------------------
    // Composition with withoutEvents — the documented escape hatch
    // for users who want events off.
    // ----------------------------------------------------------------

    public function test_handles_deeply_nested_input_without_blowing_the_stack(): void
    {
        // Build a 2,000-level chain in the input array. The
        // previously-recursive plan walker would exhaust PHP's
        // default xdebug.max_nesting_level (256) on this shape. The
        // iterative walker uses a heap-allocated stack and is bounded
        // only by available memory.
        $depth = 2_000;
        $tree = ['name' => "n{$depth}", 'tickets' => 1];
        for ($i = $depth - 1; $i >= 0; $i--) {
            $tree = ['name' => "n{$i}", 'tickets' => 1, 'children' => [$tree]];
        }

        $saved = Area::bulkInsertTree([$tree]);

        $this->assertCount($depth + 1, $saved);
        $this->assertFalse(Area::isBroken());
    }

    public function test_without_events_wrapper_suppresses_events_but_still_inserts(): void
    {
        Event::fake();

        Model::withoutEvents(function (): void {
            Area::bulkInsertTree([
                ['name' => 'silent-1', 'tickets' => 1],
                ['name' => 'silent-2', 'tickets' => 2],
            ]);
        });

        Event::assertNotDispatched('eloquent.created: '.Area::class);
        $this->assertSame(2, Area::query()->whereIn('name', ['silent-1', 'silent-2'])->count());
    }
}
