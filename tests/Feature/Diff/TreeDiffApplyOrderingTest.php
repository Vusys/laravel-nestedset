<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Diff;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * apply() must reproduce the `after` snapshot exactly — sibling order
 * included (it always appended, discarding the recorded position) — and
 * must not delete a retained child before the same diff moves it out of
 * a removed parent (remove-before-move ordering).
 */
final class TreeDiffApplyOrderingTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function childNames(Category $parent): array
    {
        $names = [];
        foreach (
            Category::query()
                ->where('parent_id', $parent->getKey())
                ->orderBy('lft')
                ->get(['name']) as $row
        ) {
            $names[] = (string) $row->name;
        }

        return $names;
    }

    #[Test]
    public function apply_reproduces_sibling_order_on_a_pure_reorder(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();
        $b = new Category(['name' => 'B']);
        $b->appendToNode($root->refresh())->save();
        $c = new Category(['name' => 'C']);
        $c->appendToNode($root->refresh())->save();

        $rootId = $root->getKey();

        $before = [
            ['id' => $rootId, 'name' => 'Root', 'parent_id' => null],
            ['id' => $a->id, 'name' => 'A', 'parent_id' => $rootId],
            ['id' => $b->id, 'name' => 'B', 'parent_id' => $rootId],
            ['id' => $c->id, 'name' => 'C', 'parent_id' => $rootId],
        ];
        // Reorder to B, A, C.
        $after = [
            ['id' => $rootId, 'name' => 'Root', 'parent_id' => null],
            ['id' => $b->id, 'name' => 'B', 'parent_id' => $rootId],
            ['id' => $a->id, 'name' => 'A', 'parent_id' => $rootId],
            ['id' => $c->id, 'name' => 'C', 'parent_id' => $rootId],
        ];

        TreeDiff::between($before, $after)->apply(Category::class);

        $this->assertSame(['B', 'A', 'C'], $this->childNames($root->refresh()));
    }

    #[Test]
    public function apply_moves_a_retained_child_out_of_a_removed_parent(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();
        $p = new Category(['name' => 'P']);
        $p->appendToNode($root->refresh())->save();
        $c = new Category(['name' => 'C']);
        $c->appendToNode($p->refresh())->save();

        $rootId = $root->getKey();

        $before = [
            ['id' => $rootId, 'name' => 'Root', 'parent_id' => null],
            ['id' => $p->id, 'name' => 'P', 'parent_id' => $rootId],
            ['id' => $c->id, 'name' => 'C', 'parent_id' => $p->id],
        ];
        // P removed; C re-parented up to Root.
        $after = [
            ['id' => $rootId, 'name' => 'Root', 'parent_id' => null],
            ['id' => $c->id, 'name' => 'C', 'parent_id' => $rootId],
        ];

        TreeDiff::between($before, $after)->apply(Category::class);

        $this->assertNull(Category::query()->find($p->id), 'P removed');
        $survivor = Category::query()->findOrFail($c->id);
        $this->assertSame($rootId, $survivor->parent_id, 'C retained, re-parented to Root');
        $this->assertSame(['C'], $this->childNames($root->refresh()));
    }
}
