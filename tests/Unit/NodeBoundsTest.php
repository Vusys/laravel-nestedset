<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\NodeBounds;

final class NodeBoundsTest extends TestCase
{
    public function test_height_is_rgt_minus_lft_plus_one(): void
    {
        $this->assertSame(6, (new NodeBounds(lft: 3, rgt: 8, depth: 1))->height());
    }

    public function test_leaf_node_has_height_of_two(): void
    {
        $this->assertSame(2, (new NodeBounds(lft: 3, rgt: 4, depth: 1))->height());
    }

    public function test_contains_a_direct_child(): void
    {
        $parent = new NodeBounds(lft: 1, rgt: 10, depth: 0);
        $child = new NodeBounds(lft: 2, rgt: 3, depth: 1);

        $this->assertTrue($parent->contains($child));
    }

    public function test_contains_a_deep_descendant(): void
    {
        $root = new NodeBounds(lft: 1, rgt: 20, depth: 0);
        $grandchild = new NodeBounds(lft: 8, rgt: 11, depth: 2);

        $this->assertTrue($root->contains($grandchild));
    }

    public function test_does_not_contain_self(): void
    {
        $node = new NodeBounds(lft: 1, rgt: 10, depth: 0);

        $this->assertFalse($node->contains($node));
    }

    public function test_does_not_contain_node_with_equal_lft(): void
    {
        $outer = new NodeBounds(lft: 1, rgt: 10, depth: 0);
        $inner = new NodeBounds(lft: 1, rgt: 5, depth: 1);

        $this->assertFalse($outer->contains($inner));
    }

    public function test_does_not_contain_node_with_equal_rgt(): void
    {
        $outer = new NodeBounds(lft: 1, rgt: 10, depth: 0);
        $inner = new NodeBounds(lft: 5, rgt: 10, depth: 1);

        $this->assertFalse($outer->contains($inner));
    }

    public function test_does_not_contain_sibling(): void
    {
        $parent = new NodeBounds(lft: 1, rgt: 10, depth: 0);
        $sibling = new NodeBounds(lft: 11, rgt: 14, depth: 1);

        $this->assertFalse($parent->contains($sibling));
    }

    public function test_does_not_contain_parent(): void
    {
        $parent = new NodeBounds(lft: 1, rgt: 10, depth: 0);
        $child = new NodeBounds(lft: 2, rgt: 5, depth: 1);

        $this->assertFalse($child->contains($parent));
    }

    public function test_leaf_contains_nothing(): void
    {
        $leaf = new NodeBounds(lft: 3, rgt: 4, depth: 2);
        $other = new NodeBounds(lft: 5, rgt: 6, depth: 2);

        $this->assertFalse($leaf->contains($other));
    }

    public function test_depth_delta_is_positive_when_other_is_deeper(): void
    {
        $parent = new NodeBounds(lft: 1, rgt: 10, depth: 0);
        $child = new NodeBounds(lft: 2, rgt: 5, depth: 2);

        $this->assertSame(2, $parent->depthDelta($child));
    }

    public function test_depth_delta_is_negative_when_other_is_shallower(): void
    {
        $child = new NodeBounds(lft: 2, rgt: 5, depth: 2);
        $parent = new NodeBounds(lft: 1, rgt: 10, depth: 0);

        $this->assertSame(-2, $child->depthDelta($parent));
    }

    public function test_depth_delta_is_zero_for_same_depth(): void
    {
        $a = new NodeBounds(lft: 1, rgt: 4, depth: 1);
        $b = new NodeBounds(lft: 5, rgt: 8, depth: 1);

        $this->assertSame(0, $a->depthDelta($b));
    }
}
