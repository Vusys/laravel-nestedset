<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Walker;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Walker\SubtreeWalker;
use Vusys\NestedSet\Walker\WalkFilter;

/**
 * Pure unit tests over a hand-built fixture — no DB, no service
 * provider, no Laravel container. The walker only reads `getKey`,
 * `getLft`, `getParentId`; we stub those via {@see StubNode}.
 *
 * Reference fixture used by most tests in this file:
 *
 *   1 root (id=1, lft=1)
 *   ├── A (id=2, lft=2)
 *   │   ├── X (id=4, lft=3)
 *   │   └── Y (id=5, lft=5)
 *   └── B (id=3, lft=7)
 *       └── Z (id=6, lft=8)
 */
final class SubtreeWalkerTest extends TestCase
{
    /**
     * @return array{root: StubNode, nodes: EloquentCollection<int, StubNode>}
     */
    private function fivePlusOneFixture(): array
    {
        $root = $this->node(1, name: 'root', lft: 1, rgt: 12, depth: 0, parentId: null);
        $a = $this->node(2, name: 'A', lft: 2, rgt: 6, depth: 1, parentId: 1);
        $x = $this->node(4, name: 'X', lft: 3, rgt: 4, depth: 2, parentId: 2);
        $y = $this->node(5, name: 'Y', lft: 5, rgt: 6, depth: 2, parentId: 2);
        $b = $this->node(3, name: 'B', lft: 7, rgt: 10, depth: 1, parentId: 1);
        $z = $this->node(6, name: 'Z', lft: 8, rgt: 9, depth: 2, parentId: 3);

        $collection = new EloquentCollection([$root, $a, $x, $y, $b, $z]);

        return ['root' => $root, 'nodes' => $collection];
    }

    #[Test]
    public function dfs_pre_order_visits_root_then_children_left_to_right(): void
    {
        ['root' => $root, 'nodes' => $nodes] = $this->fivePlusOneFixture();

        $walker = new SubtreeWalker($nodes, $root);

        $names = $this->collectNames($walker->dfs());

        $this->assertSame(['root', 'A', 'X', 'Y', 'B', 'Z'], $names);
    }

    #[Test]
    public function dfs_post_order_yields_children_before_their_parent_and_root_last(): void
    {
        ['root' => $root, 'nodes' => $nodes] = $this->fivePlusOneFixture();

        $walker = new SubtreeWalker($nodes, $root);

        $names = $this->collectNames($walker->dfsPostOrder());

        $this->assertSame(['X', 'Y', 'A', 'Z', 'B', 'root'], $names);
    }

    #[Test]
    public function bfs_visits_depth_zero_then_depth_one_then_depth_two(): void
    {
        ['root' => $root, 'nodes' => $nodes] = $this->fivePlusOneFixture();

        $walker = new SubtreeWalker($nodes, $root);

        $names = $this->collectNames($walker->bfs());

        // depth 0: root | depth 1: A, B | depth 2: X, Y, Z
        $this->assertSame(['root', 'A', 'B', 'X', 'Y', 'Z'], $names);
    }

    #[Test]
    public function root_only_subtree_yields_one_node_with_zero_depth(): void
    {
        $root = $this->node(1, name: 'root', lft: 1, rgt: 2, depth: 0, parentId: null);

        $walker = new SubtreeWalker(new EloquentCollection([$root]), $root);

        $this->assertCount(1, iterator_to_array($walker->dfs()));
        $this->assertSame(0, $walker->maxDepth());
        $this->assertSame(1, $walker->leafCount());
    }

    #[Test]
    public function descendants_only_collection_still_includes_root_anchor(): void
    {
        // Mirrors the HasTreeWalk fallback path: caller passes the
        // `descendants` relation, which never contains $this itself.
        // The walker must still treat the supplied root as the walk
        // origin, visit it, and walk its descendants from there.
        $root = $this->node(1, name: 'root', lft: 1, rgt: 6, depth: 0, parentId: null);
        $a = $this->node(2, name: 'A', lft: 2, rgt: 3, depth: 1, parentId: 1);
        $b = $this->node(3, name: 'B', lft: 4, rgt: 5, depth: 1, parentId: 1);

        $walker = new SubtreeWalker(new EloquentCollection([$a, $b]), $root);

        $this->assertSame(['root', 'A', 'B'], $this->collectNames($walker->dfs()));
    }

    #[Test]
    public function walker_resorts_children_by_lft_when_input_is_shuffled(): void
    {
        $root = $this->node(1, name: 'root', lft: 1, rgt: 8, depth: 0, parentId: null);
        $a = $this->node(2, name: 'A', lft: 2, rgt: 3, depth: 1, parentId: 1);
        $b = $this->node(3, name: 'B', lft: 4, rgt: 5, depth: 1, parentId: 1);
        $c = $this->node(4, name: 'C', lft: 6, rgt: 7, depth: 1, parentId: 1);

        // Deliberately shuffled — children list arrives B, C, A.
        $shuffled = new EloquentCollection([$root, $b, $c, $a]);

        $walker = new SubtreeWalker($shuffled, $root);

        $this->assertSame(['root', 'A', 'B', 'C'], $this->collectNames($walker->dfs()));
    }

    #[Test]
    public function orphans_with_parent_outside_collection_never_get_visited(): void
    {
        $root = $this->node(1, name: 'root', lft: 1, rgt: 4, depth: 0, parentId: null);
        $a = $this->node(2, name: 'A', lft: 2, rgt: 3, depth: 1, parentId: 1);
        // Orphan: parent_id 99 isn't in the collection.
        $orphan = $this->node(7, name: 'ORPHAN', lft: 100, rgt: 101, depth: 5, parentId: 99);

        $walker = new SubtreeWalker(new EloquentCollection([$root, $a, $orphan]), $root);

        $names = $this->collectNames($walker->dfs());
        $this->assertSame(['root', 'A'], $names);
    }

    #[Test]
    public function node_with_missing_children_in_input_is_treated_as_a_leaf(): void
    {
        // Caller eager-loaded only depth 0 + 1; A is in the collection but
        // its child X is not. The walker treats A as a leaf and continues
        // with A's sibling rather than failing.
        $root = $this->node(1, name: 'root', lft: 1, rgt: 8, depth: 0, parentId: null);
        $a = $this->node(2, name: 'A', lft: 2, rgt: 5, depth: 1, parentId: 1);
        $b = $this->node(3, name: 'B', lft: 6, rgt: 7, depth: 1, parentId: 1);

        $walker = new SubtreeWalker(new EloquentCollection([$root, $a, $b]), $root);

        $this->assertSame(['root', 'A', 'B'], $this->collectNames($walker->dfs()));
    }

    #[Test]
    public function countable_returns_reachable_node_count_not_loaded_row_count(): void
    {
        ['root' => $root, 'nodes' => $nodes] = $this->fivePlusOneFixture();
        // Add an unreachable orphan to inflate the loaded count.
        $nodes->push($this->node(99, name: 'OUTSIDE', lft: 100, rgt: 101, depth: 9, parentId: 88));

        $walker = new SubtreeWalker($nodes, $root);

        $this->assertSame(6, $walker->count());     // root + 5 descendants
        $this->assertSame(7, $nodes->count());       // includes the orphan
    }

    #[Test]
    public function max_depth_is_relative_to_walk_root(): void
    {
        ['root' => $root, 'nodes' => $nodes] = $this->fivePlusOneFixture();

        $walker = new SubtreeWalker($nodes, $root);

        // root at relative depth 0, deepest (X/Y/Z) at relative depth 2.
        $this->assertSame(2, $walker->maxDepth());
    }

    #[Test]
    public function leaf_count_only_counts_reachable_leaves(): void
    {
        ['root' => $root, 'nodes' => $nodes] = $this->fivePlusOneFixture();

        $walker = new SubtreeWalker($nodes, $root);

        // Leaves are X, Y, Z.
        $this->assertSame(3, $walker->leafCount());
    }

    #[Test]
    public function count_methods_memoise_so_repeated_calls_share_one_pass(): void
    {
        ['root' => $root, 'nodes' => $nodes] = $this->fivePlusOneFixture();

        $walker = new SubtreeWalker($nodes, $root);

        $first = $walker->count();
        $second = $walker->count();

        $this->assertSame($first, $second);
    }

    #[Test]
    public function filter_prunes_post_order_walk_at_entry_phase(): void
    {
        // Post-order skips the subtree at the *enter* phase — by the
        // time the parent would otherwise fire on exit, the rejected
        // subtree's exit frames were never pushed.
        ['root' => $root, 'nodes' => $nodes] = $this->fivePlusOneFixture();
        $walker = new SubtreeWalker($nodes, $root);

        $filter = WalkFilter::where(
            static fn (Model&HasNestedSet $n): bool => self::nameOf($n) !== 'A',
        );

        $names = $this->collectNames($walker->dfsPostOrder($filter));

        // A and its children (X, Y) are pruned; only B's subtree + root remain.
        $this->assertSame(['Z', 'B', 'root'], $names);
    }

    #[Test]
    public function filter_prunes_bfs_walk_skipping_descent_into_rejected_node(): void
    {
        ['root' => $root, 'nodes' => $nodes] = $this->fivePlusOneFixture();
        $walker = new SubtreeWalker($nodes, $root);

        $filter = WalkFilter::where(
            static fn (Model&HasNestedSet $n): bool => self::nameOf($n) !== 'A',
        );

        $names = $this->collectNames($walker->bfs($filter));

        // root visits, A is rejected (no visit, no descent), B visits at
        // depth 1, then Z at depth 2. X and Y never appear because A's
        // children were never queued.
        $this->assertSame(['root', 'B', 'Z'], $names);
    }

    #[Test]
    public function assert_strategy_throws_invalid_argument_for_unknown_label(): void
    {
        // The phpdoc union prevents bad strategies at static-analysis
        // time; reflection lets the test exercise the runtime guard
        // directly without weakening the production type.
        ['root' => $root, 'nodes' => $nodes] = $this->fivePlusOneFixture();
        $walker = new SubtreeWalker($nodes, $root);

        $ref = new \ReflectionMethod($walker, 'assertStrategy');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/strategy.*"level".*pre, post, bfs/s');

        $ref->invoke($walker, 'level');
    }

    #[Test]
    public function flatten_returns_collection_in_chosen_strategys_order(): void
    {
        ['root' => $root, 'nodes' => $nodes] = $this->fivePlusOneFixture();

        $walker = new SubtreeWalker($nodes, $root);

        $stringify = static fn (Model&HasNestedSet $n): string => self::nameOf($n);

        $pre = $walker->flatten('pre')->map($stringify)->all();
        $post = $walker->flatten('post')->map($stringify)->all();
        $bfs = $walker->flatten('bfs')->map($stringify)->all();

        $this->assertSame(['root', 'A', 'X', 'Y', 'B', 'Z'], $pre);
        $this->assertSame(['X', 'Y', 'A', 'Z', 'B', 'root'], $post);
        $this->assertSame(['root', 'A', 'B', 'X', 'Y', 'Z'], $bfs);
    }

    /**
     * @param  iterable<Model&HasNestedSet>  $iter
     * @return list<string>
     */
    private function collectNames(iterable $iter): array
    {
        $out = [];
        foreach ($iter as $node) {
            $out[] = self::nameOf($node);
        }

        return $out;
    }

    private static function nameOf(Model $n): string
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
