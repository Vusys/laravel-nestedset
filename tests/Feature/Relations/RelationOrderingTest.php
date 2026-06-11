<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Relations;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * The ancestors / descendants relations carry their own lft ordering, so
 * callers get the documented order (root-to-parent / DFS pre-order)
 * without adding ->orderBy('lft') themselves.
 */
final class RelationOrderingTest extends TestCase
{
    /**
     * @return array{0: Category, 1: Category}
     */
    private function seedChain(): array
    {
        // Root → A → B → Leaf
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();
        $b = new Category(['name' => 'B']);
        $b->appendToNode($a->refresh())->save();
        $leaf = new Category(['name' => 'Leaf']);
        $leaf->appendToNode($b->refresh())->save();

        return [$root->refresh(), $leaf->refresh()];
    }

    #[Test]
    public function ancestors_relation_is_root_to_parent_order(): void
    {
        [, $leaf] = $this->seedChain();

        // Lazy load — no explicit orderBy.
        $names = $leaf->ancestors()->get()->pluck('name')->all();
        $this->assertSame(['Root', 'A', 'B'], $names);

        // Eager load.
        $reloaded = Category::query()->with('ancestors')->whereKey($leaf->getKey())->firstOrFail();
        $this->assertSame(['Root', 'A', 'B'], $reloaded->ancestors->pluck('name')->all());
    }

    #[Test]
    public function descendants_relation_is_dfs_pre_order(): void
    {
        [$root] = $this->seedChain();

        $names = $root->descendants()->get()->pluck('name')->all();
        $this->assertSame(['A', 'B', 'Leaf'], $names);

        $reloaded = Category::query()->with('descendants')->whereKey($root->getKey())->firstOrFail();
        $this->assertSame(['A', 'B', 'Leaf'], $reloaded->descendants->pluck('name')->all());
    }
}
