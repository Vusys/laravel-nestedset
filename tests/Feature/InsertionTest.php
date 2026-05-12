<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

final class InsertionTest extends TestCase
{
    private Category $root;

    protected function setUp(): void
    {
        parent::setUp();

        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $this->root = $root->refresh();
    }

    public function test_save_as_root_assigns_lft_rgt_depth(): void
    {
        $this->assertSame(1, $this->root->lft);
        $this->assertSame(2, $this->root->rgt);
        $this->assertSame(0, $this->root->depth);
        $this->assertNull($this->root->parent_id);
    }

    public function test_append_to_node_inserts_after_existing_children(): void
    {
        $a = new Category(['name' => 'A']);
        $a->appendToNode($this->root)->save();

        $b = new Category(['name' => 'B']);
        $b->appendToNode($this->root->refresh())->save();

        $root = $this->root->refresh();
        $a = $a->refresh();
        $b = $b->refresh();

        $this->assertSame(1, $root->lft);
        $this->assertSame(6, $root->rgt);

        $this->assertSame(2, $a->lft);
        $this->assertSame(3, $a->rgt);
        $this->assertSame(1, $a->depth);
        $this->assertSame($root->id, $a->parent_id);

        $this->assertSame(4, $b->lft);
        $this->assertSame(5, $b->rgt);
        $this->assertSame(1, $b->depth);
        $this->assertSame($root->id, $b->parent_id);
    }

    public function test_prepend_to_node_inserts_before_existing_children(): void
    {
        $a = new Category(['name' => 'A']);
        $a->appendToNode($this->root)->save();

        $first = new Category(['name' => 'First']);
        $first->prependToNode($this->root->refresh())->save();

        $first = $first->refresh();
        $a = $a->refresh();

        // First should be before A.
        $this->assertLessThan($a->lft, $first->lft);
        $this->assertSame(1, $first->depth);
        $this->assertSame(1, $a->depth);
    }

    public function test_insert_before_node_places_new_sibling_to_the_left(): void
    {
        $a = new Category(['name' => 'A']);
        $a->appendToNode($this->root)->save();
        $a = $a->refresh();

        $before = new Category(['name' => 'Before']);
        $before->insertBeforeNode($a)->save();

        $before = $before->refresh();
        $a = $a->refresh();

        $this->assertLessThan($a->lft, $before->lft);
        $this->assertSame($a->parent_id, $before->parent_id);
    }

    public function test_insert_after_node_places_new_sibling_to_the_right(): void
    {
        $a = new Category(['name' => 'A']);
        $a->appendToNode($this->root)->save();
        $a = $a->refresh();

        $after = new Category(['name' => 'After']);
        $after->insertAfterNode($a)->save();

        $after = $after->refresh();
        $a = $a->refresh();

        $this->assertGreaterThan($a->rgt, $after->lft);
        $this->assertSame($a->parent_id, $after->parent_id);
    }

    public function test_deep_nesting_maintains_correct_bounds(): void
    {
        $a = new Category(['name' => 'A']);
        $a->appendToNode($this->root)->save();

        $aa = new Category(['name' => 'AA']);
        $aa->appendToNode($a->refresh())->save();

        $aaa = new Category(['name' => 'AAA']);
        $aaa->appendToNode($aa->refresh())->save();

        $root = $this->root->refresh();
        $a = $a->refresh();
        $aa = $aa->refresh();
        $aaa = $aaa->refresh();

        // Each level wraps the next.
        $this->assertLessThan($a->lft, $root->lft);
        $this->assertGreaterThan($a->rgt, $root->rgt);

        $this->assertLessThan($aa->lft, $a->lft);
        $this->assertGreaterThan($aa->rgt, $a->rgt);

        $this->assertLessThan($aaa->lft, $aa->lft);
        $this->assertGreaterThan($aaa->rgt, $aa->rgt);

        $this->assertSame(0, $root->depth);
        $this->assertSame(1, $a->depth);
        $this->assertSame(2, $aa->depth);
        $this->assertSame(3, $aaa->depth);

        $this->assertFalse(Category::isBroken());
    }
}
