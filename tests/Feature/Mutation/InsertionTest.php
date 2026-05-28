<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Mutation;

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
        // Pre: Root(1,2). After appending A: Root(1,4), A(2,3).
        // Prepending First under Root must place First at lft=2, rgt=3
        // and shift A right by 2 to lft=4, rgt=5. Asserts on absolute
        // bounds so an off-by-one in actPrependTo (e.g. inserting at
        // parent.lft instead of parent.lft + 1) is caught.
        $a = new Category(['name' => 'A']);
        $a->appendToNode($this->root)->save();

        $first = new Category(['name' => 'First']);
        $first->prependToNode($this->root->refresh())->save();

        $first = $first->refresh();
        $a = $a->refresh();
        $root = $this->root->refresh();

        $this->assertSame(1, $root->lft);
        $this->assertSame(6, $root->rgt);

        $this->assertSame(2, $first->lft, 'First lands at parent.lft + 1');
        $this->assertSame(3, $first->rgt);
        $this->assertSame(1, $first->depth);
        $this->assertSame($root->id, $first->parent_id);

        $this->assertSame(4, $a->lft, 'A shifts right by the gap width (2)');
        $this->assertSame(5, $a->rgt);
        $this->assertSame(1, $a->depth);
    }

    public function test_insert_before_node_places_new_sibling_to_the_left(): void
    {
        // Pre: Root(1,4) > A(2,3). Inserting Before to A's left should
        // produce Root(1,6) > Before(2,3) + A(4,5). Asserts the exact
        // shift so an off-by-one in actSibling — e.g. inserting at
        // sibling.rgt instead of sibling.lft — fails.
        $a = new Category(['name' => 'A']);
        $a->appendToNode($this->root)->save();
        $a = $a->refresh();

        $before = new Category(['name' => 'Before']);
        $before->insertBeforeNode($a)->save();

        $before = $before->refresh();
        $a = $a->refresh();
        $root = $this->root->refresh();

        $this->assertSame(2, $before->lft, 'Before lands at sibling.lft (the pre-insert value)');
        $this->assertSame(3, $before->rgt);
        $this->assertSame($a->parent_id, $before->parent_id);

        $this->assertSame(4, $a->lft, 'A shifts right by 2');
        $this->assertSame(5, $a->rgt);

        $this->assertSame(1, $root->lft);
        $this->assertSame(6, $root->rgt);
    }

    public function test_insert_after_node_places_new_sibling_to_the_right(): void
    {
        // Pre: Root(1,4) > A(2,3). Inserting After to A's right should
        // produce Root(1,6) > A(2,3) + After(4,5). Pinning A's bounds
        // (no shift) catches a regression that inserts at the wrong
        // side; pinning After's bounds catches an off-by-one in the
        // insertion position.
        $a = new Category(['name' => 'A']);
        $a->appendToNode($this->root)->save();
        $a = $a->refresh();

        $after = new Category(['name' => 'After']);
        $after->insertAfterNode($a)->save();

        $after = $after->refresh();
        $a = $a->refresh();
        $root = $this->root->refresh();

        $this->assertSame(2, $a->lft, 'A bounds do not shift — the gap opens to its right');
        $this->assertSame(3, $a->rgt);

        $this->assertSame(4, $after->lft, 'After lands at sibling.rgt + 1');
        $this->assertSame(5, $after->rgt);
        $this->assertSame($a->parent_id, $after->parent_id);

        $this->assertSame(1, $root->lft);
        $this->assertSame(6, $root->rgt);
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
