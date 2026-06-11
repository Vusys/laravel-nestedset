<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Diff;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * apply() must survive legal diffs that the naive add→move→remove order
 * mishandled: a flat parent/child swap (moves in row order threw
 * CyclicMoveException) and an added node ranked after a moved-in sibling
 * (sibling placement ran before the sibling existed → position out of
 * range). Moves are now topologically sorted and sibling placement is a
 * single trailing pass.
 */
final class TreeDiffApplyRobustnessTest extends TestCase
{
    use InteractsWithTrees;

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
    public function applies_a_flat_parent_child_swap_without_cycling(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();
        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();
        $b = new Category(['name' => 'B']);
        $b->appendToNode($a->refresh())->save();

        $before = [
            ['id' => $root->id, 'name' => 'Root', 'parent_id' => null],
            ['id' => $a->id, 'name' => 'A', 'parent_id' => $root->id],
            ['id' => $b->id, 'name' => 'B', 'parent_id' => $a->id],
        ];
        // Swap A and B: B becomes the child of Root, A the child of B.
        // A is listed before B so the child's move is encountered first —
        // the order that, without topological sorting, cycles.
        $after = [
            ['id' => $root->id, 'name' => 'Root', 'parent_id' => null],
            ['id' => $a->id, 'name' => 'A', 'parent_id' => $b->id],
            ['id' => $b->id, 'name' => 'B', 'parent_id' => $root->id],
        ];

        TreeDiff::between($before, $after)->apply(Category::class);

        $this->assertSame($root->id, $b->refresh()->parent_id);
        $this->assertSame($b->id, $a->refresh()->parent_id);
        $this->assertTreeIsIntact(Category::class);
    }

    #[Test]
    public function adds_a_node_ranked_after_a_moved_in_sibling(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();
        $x = new Category(['name' => 'X']);
        $x->appendToNode($root->refresh())->save();
        $m = new Category(['name' => 'M']);
        $m->makeRoot()->save();

        $before = [
            ['id' => $root->id, 'name' => 'Root', 'parent_id' => null],
            ['id' => $x->id, 'name' => 'X', 'parent_id' => $root->id],
            ['id' => $m->id, 'name' => 'M', 'parent_id' => null],
        ];
        // M moves under Root at the front; New is added at the tail.
        $after = [
            ['id' => $root->id, 'name' => 'Root', 'parent_id' => null],
            ['id' => $m->id, 'name' => 'M', 'parent_id' => $root->id],
            ['id' => $x->id, 'name' => 'X', 'parent_id' => $root->id],
            ['id' => 9001, 'name' => 'New', 'parent_id' => $root->id],
        ];

        TreeDiff::between($before, $after)->apply(Category::class);

        $this->assertSame(['M', 'X', 'New'], $this->childNames($root->refresh()));
        $this->assertTreeIsIntact(Category::class);
    }
}
