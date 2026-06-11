<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Query;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\CustomColumnsBranch;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Read-layer coverage for per-model column-name overrides.
 *
 * `CustomColumnsBranch` renames every structural column via
 * getLftName/getRgtName/getDepthName/getParentIdName. The aggregate
 * path was already exercised; this pins the query layer
 * (TreeQueryBuilder scopes + custom relations + withDepth), which used
 * to read global config only and so emitted SQL against the default
 * column names — a hard SQL error on this fixture.
 */
final class CustomColumnsQueryLayerTest extends TestCase
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
    public function where_descendant_of_uses_renamed_bounds_columns(): void
    {
        $tree = $this->seedTree();

        $names = CustomColumnsBranch::query()
            ->whereDescendantOf($tree['root']->getBounds())
            ->defaultOrder()
            ->pluck('name')
            ->all();

        $this->assertSame(['A', 'A1', 'B', 'C'], $names);
    }

    #[Test]
    public function where_ancestor_of_uses_renamed_bounds_columns(): void
    {
        $tree = $this->seedTree();

        $names = CustomColumnsBranch::query()
            ->whereAncestorOf($tree['a1']->getBounds())
            ->defaultOrder()
            ->pluck('name')
            ->all();

        $this->assertSame(['root', 'A'], $names);
    }

    #[Test]
    public function where_is_root_and_leaf_use_renamed_columns(): void
    {
        $this->seedTree();

        $this->assertSame(['root'], CustomColumnsBranch::query()->whereIsRoot()->pluck('name')->all());

        $leaves = CustomColumnsBranch::query()->whereIsLeaf()->defaultOrder()->pluck('name')->all();
        $this->assertSame(['A1', 'B', 'C'], $leaves);
    }

    #[Test]
    public function with_depth_projects_renamed_depth_column(): void
    {
        $this->seedTree();

        $a1 = CustomColumnsBranch::query()
            ->withDepth('d')
            ->where('name', 'A1')
            ->firstOrFail();

        $this->assertEquals(2, $a1->getAttribute('d'));
    }

    #[Test]
    public function descendants_and_ancestors_relations_use_renamed_columns(): void
    {
        $tree = $this->seedTree();

        $descendants = $tree['root']->descendants()->orderBy('tree_lft')->pluck('name')->all();
        $this->assertSame(['A', 'A1', 'B', 'C'], $descendants);

        $ancestors = $tree['a1']->ancestors()->orderBy('tree_lft')->pluck('name')->all();
        $this->assertSame(['root', 'A'], $ancestors);
    }

    #[Test]
    public function children_relation_eager_loads_on_renamed_columns(): void
    {
        $this->seedTree();

        $root = CustomColumnsBranch::query()
            ->with('children')
            ->where('name', 'root')
            ->firstOrFail();

        $this->assertSame(
            ['A', 'B', 'C'],
            $root->children->sortBy('tree_lft')->pluck('name')->values()->all(),
        );
    }
}
