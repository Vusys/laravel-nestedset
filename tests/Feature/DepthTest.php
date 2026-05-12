<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Depth is a stored, always-maintained column — these tests guarantee it
 * stays in sync with the physical tree shape across insert/move/restore.
 */
final class DepthTest extends TestCase
{
    public function test_depth_is_zero_for_root(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $this->assertSame(0, $root->refresh()->depth);
    }

    public function test_depth_increments_with_append(): void
    {
        $root = (new Category(['name' => 'Root']))->saveAsRoot() ? Category::query()->whereNull('parent_id')->firstOrFail() : null;
        $this->assertInstanceOf(Category::class, $root);

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root)->save();

        $aa = new Category(['name' => 'AA']);
        $aa->appendToNode($a->refresh())->save();

        $aaa = new Category(['name' => 'AAA']);
        $aaa->appendToNode($aa->refresh())->save();

        $this->assertSame(1, $a->refresh()->depth);
        $this->assertSame(2, $aa->refresh()->depth);
        $this->assertSame(3, $aaa->refresh()->depth);
    }

    public function test_depth_shifts_when_subtree_moves_under_different_parent(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root = $root->refresh();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root)->save();

        $b = new Category(['name' => 'B']);
        $b->appendToNode($root->refresh())->save();

        $aa = new Category(['name' => 'AA']);
        $aa->appendToNode($a->refresh())->save();

        // Move A under B — A becomes depth 2, AA becomes depth 3.
        $a->appendToNode($b->refresh())->save();

        $this->assertSame(2, $a->refresh()->depth);
        $this->assertSame(3, $aa->refresh()->depth);
    }

    public function test_depth_drops_to_zero_when_subtree_becomes_root(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();

        $a = new Category(['name' => 'A']);
        $a->appendToNode($root->refresh())->save();

        $aa = new Category(['name' => 'AA']);
        $aa->appendToNode($a->refresh())->save();

        $a->saveAsRoot();

        $this->assertSame(0, $a->refresh()->depth);
        $this->assertSame(1, $aa->refresh()->depth);
    }
}
