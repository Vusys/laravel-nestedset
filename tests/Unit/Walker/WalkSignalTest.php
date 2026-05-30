<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Walker;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Walker\SubtreeWalker;
use Vusys\NestedSet\Walker\WalkContext;
use Vusys\NestedSet\Walker\WalkSignal;

/**
 * Standard fixture for these tests:
 *
 *   root
 *   ├── A
 *   │   ├── X
 *   │   └── Y
 *   └── B
 *       └── Z
 */
final class WalkSignalTest extends TestCase
{
    /**
     * @return array{0: StubNode, 1: EloquentCollection<int, StubNode>}
     */
    private function fixture(): array
    {
        $root = $this->node(1, name: 'root', lft: 1, rgt: 12, depth: 0, parentId: null);
        $a = $this->node(2, name: 'A', lft: 2, rgt: 7, depth: 1, parentId: 1);
        $x = $this->node(4, name: 'X', lft: 3, rgt: 4, depth: 2, parentId: 2);
        $y = $this->node(5, name: 'Y', lft: 5, rgt: 6, depth: 2, parentId: 2);
        $b = $this->node(3, name: 'B', lft: 8, rgt: 11, depth: 1, parentId: 1);
        $z = $this->node(6, name: 'Z', lft: 9, rgt: 10, depth: 2, parentId: 3);

        return [$root, new EloquentCollection([$root, $a, $x, $y, $b, $z])];
    }

    public function test_skip_subtree_prevents_descent_in_pre_order(): void
    {
        [$root, $nodes] = $this->fixture();
        $walker = new SubtreeWalker($nodes, $root);

        $visited = [];
        $walker->walk(function (Model&HasNestedSet $node, WalkContext $_ctx) use (&$visited): ?WalkSignal {
            $name = $this->nameOf($node);
            $visited[] = $name;

            return $name === 'A' ? WalkSignal::SkipSubtree : null;
        });

        $this->assertSame(['root', 'A', 'B', 'Z'], $visited);
    }

    public function test_skip_subtree_prevents_descent_in_bfs(): void
    {
        [$root, $nodes] = $this->fixture();
        $walker = new SubtreeWalker($nodes, $root);

        $visited = [];
        $walker->walk(function (Model&HasNestedSet $node, WalkContext $_ctx) use (&$visited): ?WalkSignal {
            $name = $this->nameOf($node);
            $visited[] = $name;

            return $name === 'A' ? WalkSignal::SkipSubtree : null;
        }, strategy: 'bfs');

        $this->assertSame(['root', 'A', 'B', 'Z'], $visited);
    }

    public function test_skip_subtree_is_ignored_in_post_order_because_children_already_ran(): void
    {
        [$root, $nodes] = $this->fixture();
        $walker = new SubtreeWalker($nodes, $root);

        $visited = [];
        $walker->walk(function (Model&HasNestedSet $node, WalkContext $_ctx) use (&$visited): ?WalkSignal {
            $name = $this->nameOf($node);
            $visited[] = $name;

            return $name === 'A' ? WalkSignal::SkipSubtree : null;
        }, strategy: 'post');

        // Children of A (X, Y) were already visited before A in post-order;
        // SkipSubtree on A is a no-op at that point. Full traversal occurs.
        $this->assertSame(['X', 'Y', 'A', 'Z', 'B', 'root'], $visited);
    }

    public function test_stop_signal_halts_walk_immediately_no_further_visitors(): void
    {
        [$root, $nodes] = $this->fixture();
        $walker = new SubtreeWalker($nodes, $root);

        $visited = [];
        $walker->walk(function (Model&HasNestedSet $node, WalkContext $_ctx) use (&$visited): ?WalkSignal {
            $name = $this->nameOf($node);
            $visited[] = $name;

            return $name === 'X' ? WalkSignal::Stop : null;
        });

        // Pre-order: root, A, X — stop. Nothing after.
        $this->assertSame(['root', 'A', 'X'], $visited);
    }

    public function test_null_or_no_return_value_continues_normally(): void
    {
        [$root, $nodes] = $this->fixture();
        $walker = new SubtreeWalker($nodes, $root);

        $visited = [];
        $walker->walk(function (Model&HasNestedSet $node, WalkContext $_ctx) use (&$visited): ?WalkSignal {
            $visited[] = $this->nameOf($node);

            return null;
        });

        $this->assertSame(['root', 'A', 'X', 'Y', 'B', 'Z'], $visited);
    }

    /**
     * Pulls the test-fixture `name` column off a node as a string,
     * narrowing `Model::getAttribute()`'s mixed return for the static
     * analyser.
     */
    private function nameOf(Model $n): string
    {
        $v = $n->getAttribute('name');

        return is_scalar($v) ? (string) $v : '';
    }

    private function node(int $id, string $name, int $lft, int $rgt, int $depth, ?int $parentId): StubNode
    {
        $n = new StubNode([
            'id' => $id,
            'name' => $name,
            'lft' => $lft,
            'rgt' => $rgt,
            'depth' => $depth,
            'parent_id' => $parentId,
        ]);
        $n->exists = true;

        return $n;
    }
}
