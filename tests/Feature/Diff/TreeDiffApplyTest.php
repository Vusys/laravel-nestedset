<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Diff;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * `TreeDiff::apply()` end-to-end against a real `Category` table.
 *
 * Each test seeds a known forest, snapshots it, mutates the snapshot
 * in the expected ways, and asserts that applying the diff transforms
 * the live table to match the post-snapshot.
 */
final class TreeDiffApplyTest extends TestCase
{
    #[Test]
    public function apply_inserts_added_rows_under_existing_parents(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();

        $before = [
            ['id' => $root->id, 'name' => 'Root', 'parent_id' => null],
        ];
        $after = [
            ['id' => $root->id, 'name' => 'Root', 'parent_id' => null],
            ['id' => 999, 'name' => 'Child', 'parent_id' => $root->id],
        ];

        $diff = TreeDiff::between($before, $after);
        $this->assertSame(1, $diff->summary()['added']);

        $diff->apply(Category::class);

        $this->assertSame(2, Category::query()->count());
        $child = Category::query()->where('name', 'Child')->firstOrFail();
        $this->assertSame($root->refresh()->id, $child->parent_id);
    }

    #[Test]
    public function apply_removes_rows(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();
        $child = new Category(['name' => 'Child']);
        $child->appendToNode($root)->save();

        $before = [
            ['id' => $root->id, 'name' => 'Root', 'parent_id' => null],
            ['id' => $child->id, 'name' => 'Child', 'parent_id' => $root->id],
        ];
        $after = [
            ['id' => $root->id, 'name' => 'Root', 'parent_id' => null],
        ];

        $diff = TreeDiff::between($before, $after);
        $diff->apply(Category::class);

        $this->assertNull(Category::query()->find($child->id));
        $this->assertNotNull(Category::query()->find($root->id));
    }

    #[Test]
    public function apply_modifies_columns_on_existing_rows(): void
    {
        $root = new Category(['name' => 'Original']);
        $root->makeRoot()->save();

        $before = [['id' => $root->id, 'name' => 'Original', 'parent_id' => null]];
        $after = [['id' => $root->id, 'name' => 'Updated', 'parent_id' => null]];

        TreeDiff::between($before, $after)->apply(Category::class);

        $this->assertSame('Updated', $root->refresh()->name);
    }

    #[Test]
    public function apply_moves_existing_row(): void
    {
        $a = new Category(['name' => 'A']);
        $a->makeRoot()->save();
        $b = new Category(['name' => 'B']);
        $b->makeRoot()->save();
        $x = new Category(['name' => 'X']);
        $x->appendToNode($a)->save();

        $before = [
            ['id' => $a->id, 'name' => 'A', 'parent_id' => null],
            ['id' => $b->id, 'name' => 'B', 'parent_id' => null],
            ['id' => $x->id, 'name' => 'X', 'parent_id' => $a->id],
        ];
        $after = [
            ['id' => $a->id, 'name' => 'A', 'parent_id' => null],
            ['id' => $b->id, 'name' => 'B', 'parent_id' => null],
            ['id' => $x->id, 'name' => 'X', 'parent_id' => $b->id],
        ];

        TreeDiff::between($before, $after)->apply(Category::class);

        $this->assertSame($b->id, $x->refresh()->parent_id);
    }

    #[Test]
    public function dry_run_does_not_mutate(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();

        $before = [['id' => $root->id, 'name' => 'Root', 'parent_id' => null]];
        $after = [['id' => $root->id, 'name' => 'Changed', 'parent_id' => null]];

        $diff = TreeDiff::between($before, $after);
        $result = $diff->apply(Category::class, dryRun: true);

        $this->assertTrue($result->dryRun);
        $this->assertNotSame([], $result->plannedStatements);
        $this->assertSame('Root', $root->refresh()->name);
    }
}
