<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Testing;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Testing\TreeBuilderShape;

/**
 * Pure-logic tests for shape normalisation: the uniform / array /
 * closure branching variants all collapse to the same nested-array
 * skeleton that `treeFromShape` consumes.
 */
final class ShapeNormalisationTest extends TestCase
{
    public function test_uniform_int_branching_expands_to_expected_subtree_counts(): void
    {
        $shape = $this->uniform(depth: 3, branching: 2);
        $normalised = $shape->normalise();

        $this->assertCount(1, $normalised, 'A single root is requested.');
        $this->assertSame(15, $this->totalNodes($normalised));
    }

    public function test_uniform_zero_depth_zero_branching_is_single_root(): void
    {
        $shape = $this->uniform(depth: 0, branching: 0);
        $normalised = $shape->normalise();

        $this->assertSame(1, $this->totalNodes($normalised));
        $this->assertSame([], $normalised[0][TreeBuilderShape::CHILDREN_KEY]);
    }

    public function test_branching_array_produces_per_depth_fan_out(): void
    {
        $shape = $this->uniform(depth: 3, branching: [5, 2, 1]);
        $normalised = $shape->normalise();

        $this->assertSame(26, $this->totalNodes($normalised));
        $depthChildren = $this->childrenOf($normalised[0]);
        $this->assertCount(5, $depthChildren);
        $this->assertCount(2, $this->childrenOf($depthChildren[0]));
        $this->assertCount(1, $this->childrenOf($this->childrenOf($depthChildren[0])[0]));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private function childrenOf(array $node): array
    {
        $children = $node[TreeBuilderShape::CHILDREN_KEY] ?? [];
        if (! is_array($children)) {
            return [];
        }

        /** @var list<array<string, mixed>> $children */
        return $children;
    }

    public function test_branching_closure_receives_parent_depth(): void
    {
        $seenDepths = [];
        $shape = $this->uniform(
            depth: 2,
            branching: function (int $parentDepth) use (&$seenDepths): int {
                $seenDepths[] = $parentDepth;

                return $parentDepth === 0 ? 3 : 1;
            },
        );

        $normalised = $shape->normalise();

        $this->assertSame(7, $this->totalNodes($normalised));
        $this->assertContains(0, $seenDepths);
        $this->assertContains(1, $seenDepths);
    }

    public function test_branching_closure_returning_non_int_throws(): void
    {
        $bogus = $this->bogusBranchingClosure();
        $shape = $this->uniform(depth: 1, branching: $bogus);

        $this->expectException(InvalidArgumentException::class);
        $shape->normalise();
    }

    /**
     * Returns a closure typed `Closure(int): int` for the call site, but
     * which secretly returns a non-int at runtime — the only way to
     * exercise the runtime validator without PHPStan refusing the call
     * (and the only place in the suite we deliberately violate the
     * declared closure return type).
     *
     * @return Closure(int): int
     */
    private function bogusBranchingClosure(): Closure
    {
        /** @var Closure(int): int $closure */
        $closure = Closure::fromCallable(static fn (int $d): float => 1.5);

        return $closure;
    }

    public function test_branching_closure_returning_negative_throws(): void
    {
        $shape = $this->uniform(
            depth: 1,
            branching: fn (int $d): int => -1,
        );

        $this->expectException(InvalidArgumentException::class);
        $shape->normalise();
    }

    public function test_explicit_shape_walks_in_dfs_pre_order(): void
    {
        $shape = new TreeBuilderShape(
            kind: TreeBuilderShape::KIND_EXPLICIT,
            depth: 0,
            branching: 0,
            explicitShape: [
                ['name' => 'A', 'children' => [
                    ['name' => 'A1'],
                    ['name' => 'A2'],
                ]],
                ['name' => 'B'],
            ],
            parent: null,
            labelColumn: null,
            per: null,
            afterCreating: true,
        );

        $normalised = $shape->normalise();
        $names = [];
        foreach (TreeBuilderShape::walkDfs($normalised) as $node) {
            $names[] = $node['attributes']['name'] ?? null;
        }

        $this->assertSame(['A', 'A1', 'A2', 'B'], $names);
    }

    public function test_explicit_shape_empty_row_passes_through(): void
    {
        $shape = new TreeBuilderShape(
            kind: TreeBuilderShape::KIND_EXPLICIT,
            depth: 0,
            branching: 0,
            explicitShape: [[]],
            parent: null,
            labelColumn: null,
            per: null,
            afterCreating: true,
        );

        $normalised = $shape->normalise();

        $this->assertSame(1, $this->totalNodes($normalised));
        $node = $normalised[0];
        unset($node[TreeBuilderShape::CHILDREN_KEY], $node['__meta']);
        $this->assertSame([], $node, 'Empty entry carries no model attributes — only structural metadata.');
    }

    public function test_explicit_shape_only_children_key_resolves_root_attrs_to_empty(): void
    {
        $shape = new TreeBuilderShape(
            kind: TreeBuilderShape::KIND_EXPLICIT,
            depth: 0,
            branching: 0,
            explicitShape: [['children' => [['name' => 'x']]]],
            parent: null,
            labelColumn: null,
            per: null,
            afterCreating: true,
        );

        $normalised = $shape->normalise();
        $this->assertSame(2, $this->totalNodes($normalised));

        $root = $normalised[0];
        unset($root[TreeBuilderShape::CHILDREN_KEY], $root['__meta']);
        $this->assertSame([], $root);
    }

    public function test_explicit_shape_non_array_children_throws(): void
    {
        $shape = new TreeBuilderShape(
            kind: TreeBuilderShape::KIND_EXPLICIT,
            depth: 0,
            branching: 0,
            explicitShape: [['name' => 'x', 'children' => 'not-an-array']],
            parent: null,
            labelColumn: null,
            per: null,
            afterCreating: true,
        );

        $this->expectException(InvalidArgumentException::class);
        $shape->normalise();
    }

    public function test_sibling_index_metadata_resets_per_branch(): void
    {
        $shape = $this->uniform(depth: 2, branching: 2);
        $normalised = $shape->normalise();

        $indices = [];
        foreach (TreeBuilderShape::walkDfs($normalised) as $node) {
            $indices[$node['depth']][] = $node['siblingIndex'];
        }

        $this->assertSame([0], $indices[0]);
        $this->assertSame([0, 1], $indices[1]);
        $this->assertSame([0, 1, 0, 1], $indices[2], 'Sibling index resets per-parent.');
    }

    /**
     * @param  int|list<int>|(Closure(int): int)  $branching
     */
    private function uniform(int $depth, int|array|Closure $branching): TreeBuilderShape
    {
        return new TreeBuilderShape(
            kind: TreeBuilderShape::KIND_UNIFORM,
            depth: $depth,
            branching: $branching,
            explicitShape: [],
            parent: null,
            labelColumn: 'name',
            per: null,
            afterCreating: true,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function totalNodes(array $nodes): int
    {
        $count = 0;
        foreach (TreeBuilderShape::walkDfs($nodes) as $_) {
            $count++;
        }

        return $count;
    }
}
