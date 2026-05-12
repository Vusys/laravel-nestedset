<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

final class BulkInsertTest extends TestCase
{
    /**
     * Covers Phase M's `bulkInsertTree` static. Categories is unscoped
     * (no Aggregate columns); Area is unscoped with aggregates;
     * MenuItem is scoped — used here only to confirm the
     * ScopeViolationException is thrown.
     */
    public function test_empty_input_returns_empty_array_and_is_noop(): void
    {
        $before = DB::table('categories')->count();

        $ids = Category::bulkInsertTree([]);

        $this->assertSame([], $ids);
        $this->assertSame($before, DB::table('categories')->count());
    }

    public function test_inserts_a_flat_forest_of_roots(): void
    {
        $ids = Category::bulkInsertTree([
            ['name' => 'A'],
            ['name' => 'B'],
            ['name' => 'C'],
        ]);

        $this->assertCount(3, $ids);
        $this->assertSame(['A', 'B', 'C'], Category::query()
            ->whereIn('id', $ids)->orderBy('id')->pluck('name')->all());

        foreach ($ids as $id) {
            $row = Category::query()->findOrFail($id);
            $this->assertNull($row->parent_id, 'root has no parent');
            $this->assertSame(0, (int) $row->depth);
            $this->assertSame((int) $row->lft + 1, (int) $row->rgt, 'leaf root has rgt = lft + 1');
        }

        $this->assertSame(0, array_sum(Category::countErrors()), 'tree integrity intact');
    }

    public function test_inserts_a_nested_tree_under_an_existing_parent(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root = $root->refresh();

        $ids = Category::bulkInsertTree([
            ['name' => 'A', 'children' => [
                ['name' => 'A1'],
                ['name' => 'A2', 'children' => [
                    ['name' => 'A2-1'],
                ]],
            ]],
            ['name' => 'B'],
        ], appendTo: $root);

        $this->assertCount(5, $ids);

        // Re-read the root post-gap.
        $root = $root->refresh();

        // Every inserted node is a descendant of the root.
        foreach ($ids as $id) {
            $row = Category::query()->findOrFail($id);
            $this->assertGreaterThan($root->lft, (int) $row->lft);
            $this->assertLessThan($root->rgt, (int) $row->rgt);
            $this->assertGreaterThanOrEqual(1, (int) $row->depth);
        }

        $this->assertSame(0, array_sum(Category::countErrors()));
    }

    public function test_aggregates_are_correct_after_insert(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root = $root->refresh();

        Area::bulkInsertTree([
            ['name' => 'a', 'tickets' => 5, 'children' => [
                ['name' => 'a1', 'tickets' => 3],
                ['name' => 'a2', 'tickets' => 7],
            ]],
            ['name' => 'b', 'tickets' => 11],
        ], appendTo: $root);

        $root = $root->refresh();

        // Root subtree contains: Root(0) + a(5) + a1(3) + a2(7) + b(11) = 26
        $this->assertSame(26, (int) $root->tickets_total);
        $this->assertSame(5, (int) $root->tickets_count_all);
        $this->assertSame(0, (int) $root->tickets_min);
        $this->assertSame(11, (int) $root->tickets_max);

        $a = Area::query()->where('name', 'a')->firstOrFail();
        $this->assertSame(15, (int) $a->tickets_total, 'a subtree: 5+3+7 = 15');
        $this->assertSame(3, (int) $a->tickets_count_all);
        $this->assertSame(3, (int) $a->tickets_min);
        $this->assertSame(7, (int) $a->tickets_max);

        // Drift check at the end.
        $this->assertFalse(Area::aggregatesAreBroken());
    }

    public function test_inserted_ids_are_in_dfs_preorder(): void
    {
        $ids = Category::bulkInsertTree([
            ['name' => 'first', 'children' => [
                ['name' => 'first-child'],
            ]],
            ['name' => 'second'],
        ]);

        // DFS pre-order: first → first-child → second. Resolve names in
        // exactly the order bulkInsertTree returned the ids (portable
        // across backends — no `FIELD()` or `ORDER BY CASE` needed).
        $byId = Category::query()->whereIn('id', $ids)->get()->keyBy('id');
        $names = [];
        foreach ($ids as $id) {
            $row = $byId[$id] ?? null;
            if (! $row instanceof Category) {
                $this->fail("Expected Category for id {$id}");
            }
            $names[] = (string) $row->name;
        }

        $this->assertSame(['first', 'first-child', 'second'], $names);
    }

    public function test_rejects_reserved_attributes_in_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"lft" is reserved');

        Category::bulkInsertTree([
            ['name' => 'bad', 'lft' => 999],
        ]);
    }

    public function test_rejects_explicit_primary_key_in_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"id" is reserved');

        Category::bulkInsertTree([
            ['id' => 1000, 'name' => 'bad'],
        ]);
    }

    public function test_throws_scope_violation_on_scoped_model(): void
    {
        $this->expectException(ScopeViolationException::class);

        MenuItem::bulkInsertTree([['name' => 'x']]);
    }

    public function test_transactional_rollback_on_failure(): void
    {
        $before = DB::table('categories')->count();

        try {
            // Two valid + one invalid (NULL name violates NOT NULL constraint).
            Category::bulkInsertTree([
                ['name' => 'first'],
                ['name' => null],
                ['name' => 'third'],
            ]);
            $this->fail('Expected an exception');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($before, DB::table('categories')->count(),
            'no rows should remain after the rollback');
    }

    /**
     * After a forest insert that creates new roots, the next single-node
     * Eloquent save() must not collide on id with what bulkInsertTree
     * just assigned. PG's sequence in particular is independent of
     * explicit-id inserts and needs the package's setval() sync.
     */
    public function test_next_eloquent_save_does_not_collide_on_id(): void
    {
        Category::bulkInsertTree([
            ['name' => 'r1'],
            ['name' => 'r2'],
            ['name' => 'r3'],
        ]);

        $extra = new Category(['name' => 'after-bulk']);
        $extra->saveAsRoot();

        $this->assertSame('after-bulk', Category::query()
            ->where('id', $extra->id)->firstOrFail()->name);
    }
}
