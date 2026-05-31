<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Diff;

use Closure;
use LogicException;
use Vusys\NestedSet\Diff\TreeChange\Modified;
use Vusys\NestedSet\Diff\TreeChange\Moved;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Exceptions\MissingParentException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Edge paths on `TreeDiff::apply()`: empty diff, dry-run shape,
 * schema-mismatch refusal, identity-not-found (Modified and Moved),
 * resolver-returning-null, and the no-op early return.
 */
final class TreeDiffApplyEdgeCasesTest extends TestCase
{
    public function test_apply_on_empty_diff_short_circuits(): void
    {
        $diff = TreeDiff::between([], []);

        $result = $diff->apply(Category::class);

        $this->assertSame([], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame([], $result->moved);
        $this->assertSame([], $result->modified);
        $this->assertFalse($result->dryRun);
    }

    public function test_dry_run_reports_planned_statements_for_every_category(): void
    {
        $a = new Category(['name' => 'A']);
        $a->makeRoot()->save();
        $b = new Category(['name' => 'B']);
        $b->makeRoot()->save();

        $before = [
            ['id' => $a->id, 'name' => 'A', 'parent_id' => null],
            ['id' => $b->id, 'name' => 'B', 'parent_id' => null],
        ];
        $after = [
            ['id' => $a->id, 'name' => 'A renamed', 'parent_id' => null],
            ['id' => 999, 'name' => 'C', 'parent_id' => $a->id],
        ];

        $result = TreeDiff::between($before, $after)->apply(Category::class, dryRun: true);

        $this->assertTrue($result->dryRun);
        $this->assertContains('insert+gap', array_column($result->plannedStatements, 'statement'));
        $this->assertContains('delete', array_column($result->plannedStatements, 'statement'));
        $this->assertContains('update', array_column($result->plannedStatements, 'statement'));

        // Nothing actually wrote.
        $this->assertSame('A', $a->refresh()->name);
        $this->assertNull(Category::query()->where('name', 'C')->first());
    }

    public function test_schema_mismatch_throws_logic_exception_with_offender_list(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();

        $before = [['id' => $root->id, 'name' => 'Root', 'parent_id' => null]];
        $after = [['id' => $root->id, 'name' => 'Root', 'parent_id' => null, 'no_such_column' => 'oops']];

        $diff = TreeDiff::between($before, $after);
        $this->assertCount(1, $diff->modified);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/no_such_column/');
        $diff->apply(Category::class);
    }

    public function test_modify_throws_when_resolver_returns_null_for_modified_key(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();

        $diff = new TreeDiff(
            added: [],
            removed: [],
            moved: [],
            modified: [new Modified(key: 'nope', before: ['name' => 'a'], after: ['name' => 'b'])],
            on: 'slug',
            ignoreColumns: [],
        );

        $this->expectException(MissingParentException::class);
        $diff->apply(Category::class, resolver: static fn (mixed $i): null => null);
    }

    public function test_added_root_via_apply_writes_at_top_level(): void
    {
        $diff = TreeDiff::between(
            [],
            [['id' => 1, 'name' => 'NewRoot', 'parent_id' => null]],
        );

        $diff->apply(Category::class);

        $row = Category::query()->where('name', 'NewRoot')->firstOrFail();
        $this->assertNull($row->parent_id);
        $this->assertSame(0, $row->depth);
    }

    public function test_move_throws_missing_parent_when_to_parent_does_not_resolve(): void
    {
        $a = new Category(['name' => 'A']);
        $a->makeRoot()->save();

        $diff = new TreeDiff(
            added: [],
            removed: [],
            moved: [new Moved(key: $a->id, fromParent: null, toParent: 99999, toSiblingPosition: 0)],
            modified: [],
            on: 'id',
            ignoreColumns: [],
        );

        $this->expectException(MissingParentException::class);
        $diff->apply(Category::class);
    }

    public function test_dry_run_with_all_change_types_lists_every_statement_kind(): void
    {
        $a = new Category(['name' => 'A']);
        $a->makeRoot()->save();
        $b = new Category(['name' => 'B']);
        $b->makeRoot()->save();
        $kept = new Category(['name' => 'kept']);
        $kept->appendToNode($a)->save();
        $toRemove = new Category(['name' => 'gone']);
        $toRemove->makeRoot()->save();

        $before = [
            ['id' => $a->id, 'name' => 'A', 'parent_id' => null],
            ['id' => $b->id, 'name' => 'B', 'parent_id' => null],
            ['id' => $kept->id, 'name' => 'kept', 'parent_id' => $a->id],
            ['id' => $toRemove->id, 'name' => 'gone', 'parent_id' => null],
        ];
        $after = [
            ['id' => $a->id, 'name' => 'A renamed', 'parent_id' => null],
            ['id' => $b->id, 'name' => 'B', 'parent_id' => null],
            ['id' => $kept->id, 'name' => 'kept', 'parent_id' => $b->id],
            ['id' => 7777, 'name' => 'newleaf', 'parent_id' => $a->id],
        ];

        $result = TreeDiff::between($before, $after)->apply(Category::class, dryRun: true);

        $kinds = array_column($result->plannedStatements, 'statement');
        $this->assertContains('delete', $kinds);
        $this->assertContains('insert+gap', $kinds);
        $this->assertContains('move', $kinds);
        $this->assertContains('update', $kinds);
    }

    public function test_move_throws_missing_parent_when_custom_resolver_returns_null_for_destination(): void
    {
        $row = new Category(['name' => 'row']);
        $row->makeRoot()->save();

        $diff = new TreeDiff(
            added: [],
            removed: [],
            moved: [new Moved(key: 'row-slug', fromParent: null, toParent: 'missing-slug', toSiblingPosition: 0)],
            modified: [],
            on: 'slug',
            ignoreColumns: [],
        );

        $this->expectException(MissingParentException::class);
        $diff->apply(
            Category::class,
            resolver: static fn (mixed $identity): ?int => $identity === 'row-slug' ? $row->id : null,
        );
    }

    public function test_move_throws_when_source_row_no_longer_exists_at_apply_time(): void
    {
        $diff = new TreeDiff(
            added: [],
            removed: [],
            moved: [new Moved(key: 99999, fromParent: null, toParent: null, toSiblingPosition: 0)],
            modified: [],
            on: 'id',
            ignoreColumns: [],
        );

        $this->expectException(MissingParentException::class);
        $this->expectExceptionMessageMatches('/no longer exists/');
        $diff->apply(Category::class);
    }

    public function test_closure_based_on_uses_custom_resolver(): void
    {
        $root = new Category(['name' => 'Slugged']);
        $root->makeRoot()->save();

        $identity = static fn (array|object $row): string => is_array($row) ? (string) ($row['name'] ?? '') : '';

        $before = [['id' => $root->id, 'name' => 'Slugged', 'parent_id' => null]];
        $after = [['id' => $root->id, 'name' => 'Slugged', 'parent_id' => null, 'title' => 'fresh']];

        $diff = TreeDiff::between($before, $after, on: Closure::fromCallable($identity));
        $this->assertSame(1, $diff->summary()['modified']);

        $diff->apply(
            Category::class,
            resolver: static fn (mixed $i) => $i === 'Slugged' ? $root->id : null,
        );

        $this->assertSame('fresh', $root->refresh()->title);
    }
}
