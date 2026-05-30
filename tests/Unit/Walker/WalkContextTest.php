<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Walker;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Walker\SubtreeWalker;
use Vusys\NestedSet\Walker\WalkContext;

final class WalkContextTest extends TestCase
{
    public function test_depth_is_relative_to_walk_root_not_the_absolute_depth_column(): void
    {
        // Build a fragment of a larger tree: root sits at absolute depth
        // 5 in the stored column, but the walker treats it as depth 0.
        $root = $this->node(1, name: 'root', lft: 100, rgt: 105, depth: 5, parentId: 99);
        $child = $this->node(2, name: 'child', lft: 101, rgt: 102, depth: 6, parentId: 1);

        $walker = new SubtreeWalker(new EloquentCollection([$root, $child]), $root);

        $observed = [];
        $walker->walk(function (Model&HasNestedSet $node, WalkContext $ctx) use (&$observed): void {
            $name = $this->nameOf($node);
            $observed[$name] = ['relative' => $ctx->depth, 'absolute' => $node->getDepth()];
        });

        $this->assertSame(0, $observed['root']['relative']);
        $this->assertSame(5, $observed['root']['absolute']);
        $this->assertSame(1, $observed['child']['relative']);
        $this->assertSame(6, $observed['child']['absolute']);
    }

    public function test_parent_is_null_at_the_walk_root_and_the_hydrated_parent_elsewhere(): void
    {
        $root = $this->node(1, name: 'root', lft: 1, rgt: 4, depth: 0, parentId: null);
        $child = $this->node(2, name: 'child', lft: 2, rgt: 3, depth: 1, parentId: 1);

        $walker = new SubtreeWalker(new EloquentCollection([$root, $child]), $root);

        /** @var array<string, ?Model> $parents */
        $parents = [];
        $walker->walk(function (Model&HasNestedSet $node, WalkContext $ctx) use (&$parents): void {
            $parents[$this->nameOf($node)] = $ctx->parent;
        });

        $this->assertNull($parents['root']);
        $this->assertSame($root, $parents['child']);
    }

    public function test_sibling_index_is_zero_based_and_count_matches_sibling_set(): void
    {
        $root = $this->node(1, name: 'root', lft: 1, rgt: 8, depth: 0, parentId: null);
        $a = $this->node(2, name: 'A', lft: 2, rgt: 3, depth: 1, parentId: 1);
        $b = $this->node(3, name: 'B', lft: 4, rgt: 5, depth: 1, parentId: 1);
        $c = $this->node(4, name: 'C', lft: 6, rgt: 7, depth: 1, parentId: 1);

        $walker = new SubtreeWalker(new EloquentCollection([$root, $a, $b, $c]), $root);

        $observed = [];
        $walker->walk(function (Model&HasNestedSet $node, WalkContext $ctx) use (&$observed): void {
            $observed[$this->nameOf($node)] = [$ctx->siblingIndex, $ctx->siblingCount];
        });

        $this->assertSame([0, 3], $observed['A']);
        $this->assertSame([1, 3], $observed['B']);
        $this->assertSame([2, 3], $observed['C']);
    }

    public function test_first_and_last_sibling_flags_are_derived_correctly_including_only_child(): void
    {
        $root = $this->node(1, name: 'root', lft: 1, rgt: 8, depth: 0, parentId: null);
        $a = $this->node(2, name: 'A', lft: 2, rgt: 3, depth: 1, parentId: 1);
        $b = $this->node(3, name: 'B', lft: 4, rgt: 5, depth: 1, parentId: 1);
        $only = $this->node(4, name: 'only', lft: 6, rgt: 7, depth: 1, parentId: 1);
        // 'only' is third sibling — to test the single-child case let's use a different tree:
        $rootSolo = $this->node(10, name: 'soloRoot', lft: 1, rgt: 4, depth: 0, parentId: null);
        $soloChild = $this->node(11, name: 'soloChild', lft: 2, rgt: 3, depth: 1, parentId: 10);

        $walkerMulti = new SubtreeWalker(new EloquentCollection([$root, $a, $b, $only]), $root);
        $flagsMulti = [];
        $walkerMulti->walk(function (Model&HasNestedSet $node, WalkContext $ctx) use (&$flagsMulti): void {
            $flagsMulti[$this->nameOf($node)] = [$ctx->isFirstSibling, $ctx->isLastSibling];
        });

        $this->assertSame([true, false], $flagsMulti['A']);
        $this->assertSame([false, false], $flagsMulti['B']);
        $this->assertSame([false, true], $flagsMulti['only']);

        $walkerSolo = new SubtreeWalker(new EloquentCollection([$rootSolo, $soloChild]), $rootSolo);
        $flagsSolo = [];
        $walkerSolo->walk(function (Model&HasNestedSet $node, WalkContext $ctx) use (&$flagsSolo): void {
            $flagsSolo[$this->nameOf($node)] = [$ctx->isFirstSibling, $ctx->isLastSibling];
        });

        // The walk root is its own "only" sibling.
        $this->assertSame([true, true], $flagsSolo['soloRoot']);
        $this->assertSame([true, true], $flagsSolo['soloChild']);
    }

    public function test_path_to_root_is_empty_at_the_walk_root_and_the_ancestor_chain_below(): void
    {
        // Root → A → X → Y (a chain so pathToRoot has multiple entries).
        $root = $this->node(1, name: 'root', lft: 1, rgt: 8, depth: 0, parentId: null);
        $a = $this->node(2, name: 'A', lft: 2, rgt: 7, depth: 1, parentId: 1);
        $x = $this->node(3, name: 'X', lft: 3, rgt: 6, depth: 2, parentId: 2);
        $y = $this->node(4, name: 'Y', lft: 4, rgt: 5, depth: 3, parentId: 3);

        $walker = new SubtreeWalker(new EloquentCollection([$root, $a, $x, $y]), $root);

        /** @var array<string, list<string>> $paths */
        $paths = [];
        $walker->walk(function (Model&HasNestedSet $node, WalkContext $ctx) use (&$paths): void {
            $paths[$this->nameOf($node)] = array_map(
                $this->nameOf(...),
                $ctx->pathToRoot(),
            );
        });

        $this->assertSame([], $paths['root']);
        // pathToRoot stops BEFORE the walk root — at A (depth 1), nothing
        // sits above except the root itself, which is excluded.
        $this->assertSame([], $paths['A']);
        $this->assertSame(['A'], $paths['X']);
        $this->assertSame(['X', 'A'], $paths['Y']);
    }

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
