<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\NodeBounds;

final class NodeBoundsTest extends TestCase
{
    #[DataProvider('heightCases')]
    #[Test]
    public function height_is_rgt_minus_lft_plus_one(NodeBounds $node, int $expected): void
    {
        $this->assertSame($expected, $node->height());
    }

    /**
     * @return iterable<string, array{0: NodeBounds, 1: int}>
     */
    public static function heightCases(): iterable
    {
        yield 'interior node spans its subtree' => [new NodeBounds(lft: 3, rgt: 8, depth: 1), 6];
        yield 'leaf node has height of two' => [new NodeBounds(lft: 3, rgt: 4, depth: 1), 2];
    }

    #[DataProvider('containmentCases')]
    #[Test]
    public function contains_uses_strict_bounds_containment(NodeBounds $outer, NodeBounds $inner, bool $expected): void
    {
        $this->assertSame($expected, $outer->contains($inner));
    }

    /**
     * @return iterable<string, array{0: NodeBounds, 1: NodeBounds, 2: bool}>
     */
    public static function containmentCases(): iterable
    {
        $root = new NodeBounds(lft: 1, rgt: 10, depth: 0);

        yield 'contains a direct child' => [$root, new NodeBounds(lft: 2, rgt: 3, depth: 1), true];
        yield 'contains a deep descendant' => [
            new NodeBounds(lft: 1, rgt: 20, depth: 0),
            new NodeBounds(lft: 8, rgt: 11, depth: 2),
            true,
        ];
        yield 'does not contain itself' => [$root, $root, false];
        yield 'does not contain a node with equal lft' => [$root, new NodeBounds(lft: 1, rgt: 5, depth: 1), false];
        yield 'does not contain a node with equal rgt' => [$root, new NodeBounds(lft: 5, rgt: 10, depth: 1), false];
        yield 'does not contain a sibling' => [$root, new NodeBounds(lft: 11, rgt: 14, depth: 1), false];
        yield 'child does not contain its parent' => [new NodeBounds(lft: 2, rgt: 5, depth: 1), $root, false];
        yield 'a leaf contains nothing' => [
            new NodeBounds(lft: 3, rgt: 4, depth: 2),
            new NodeBounds(lft: 5, rgt: 6, depth: 2),
            false,
        ];
    }

    #[DataProvider('depthDeltaCases')]
    #[Test]
    public function depth_delta_is_the_signed_depth_difference(NodeBounds $node, NodeBounds $other, int $expected): void
    {
        $this->assertSame($expected, $node->depthDelta($other));
    }

    /**
     * @return iterable<string, array{0: NodeBounds, 1: NodeBounds, 2: int}>
     */
    public static function depthDeltaCases(): iterable
    {
        $shallow = new NodeBounds(lft: 1, rgt: 10, depth: 0);
        $deep = new NodeBounds(lft: 2, rgt: 5, depth: 2);

        yield 'positive when other is deeper' => [$shallow, $deep, 2];
        yield 'negative when other is shallower' => [$deep, $shallow, -2];
        yield 'zero for same depth' => [
            new NodeBounds(lft: 1, rgt: 4, depth: 1),
            new NodeBounds(lft: 5, rgt: 8, depth: 1),
            0,
        ];
    }
}
