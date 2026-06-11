<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Corruption;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * fixTree() on a *healthy* tree must preserve deliberate sibling order
 * (insertBeforeNode / up() / reorderChildren), not revert it to PK
 * order. corruption.md frames fixTree as the universal recovery, so an
 * order-destroying rebuild loses user intent on every run.
 */
final class FixTreePreservesOrderTest extends TestCase
{
    #[Test]
    public function fixtree_keeps_a_deliberate_sibling_order(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();
        $b = new Category(['name' => 'B']);
        $b->appendToNode($root->refresh())->save();
        $c = new Category(['name' => 'C']);
        $c->appendToNode($root->refresh())->save();

        // Deliberately reorder to C, A, B — not PK order.
        $root->refresh();
        $root->reorderChildren([$c->id, $a->id, $b->id]);

        $this->assertSame(['C', 'A', 'B'], $this->childNamesByLft());

        Category::fixTree();

        $this->assertSame(
            ['C', 'A', 'B'],
            $this->childNamesByLft(),
            'fixTree must not revert deliberate sibling order to PK order',
        );
    }

    /**
     * @return list<string>
     */
    private function childNamesByLft(): array
    {
        return Category::query()
            ->whereNotNull('parent_id')
            ->orderBy('lft')
            ->pluck('name')
            ->all();
    }
}
