<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Walker;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Walker\SubtreeWalker;
use Vusys\NestedSet\Walker\WalkContext;
use Vusys\NestedSet\Walker\WalkFilter;

final class WalkFilterTest extends TestCase
{
    public function test_depth_filter_visits_root_plus_n_levels_and_skips_deeper_subtrees(): void
    {
        $root = $this->node(1, name: 'root', lft: 1, rgt: 8, depth: 0, parentId: null);
        $a = $this->node(2, name: 'A', lft: 2, rgt: 7, depth: 1, parentId: 1);
        $x = $this->node(3, name: 'X', lft: 3, rgt: 6, depth: 2, parentId: 2);
        $y = $this->node(4, name: 'Y', lft: 4, rgt: 5, depth: 3, parentId: 3);

        $walker = new SubtreeWalker(new EloquentCollection([$root, $a, $x, $y]), $root);

        $names = $this->namesOf($walker->dfs(WalkFilter::depth(2)));

        // depth=2 visits root, A, X (relative depths 0,1,2); Y at depth 3 is skipped.
        $this->assertSame(['root', 'A', 'X'], $names);
    }

    public function test_where_filter_skips_nodes_and_their_subtree_when_predicate_returns_false(): void
    {
        $root = $this->node(1, name: 'root', lft: 1, rgt: 12, depth: 0, parentId: null);
        $keep = $this->node(2, name: 'keep', lft: 2, rgt: 5, depth: 1, parentId: 1);
        $keepChild = $this->node(3, name: 'keep-child', lft: 3, rgt: 4, depth: 2, parentId: 2);
        $drop = $this->node(4, name: 'drop', lft: 6, rgt: 9, depth: 1, parentId: 1);
        $dropChild = $this->node(5, name: 'drop-child', lft: 7, rgt: 8, depth: 2, parentId: 4);

        $walker = new SubtreeWalker(
            new EloquentCollection([$root, $keep, $keepChild, $drop, $dropChild]),
            $root,
        );

        $filter = WalkFilter::where(
            static fn (Model&HasNestedSet $n): bool => self::nameOf($n) !== 'drop',
        );

        $names = $this->namesOf($walker->dfs($filter));

        $this->assertSame(['root', 'keep', 'keep-child'], $names);
    }

    public function test_compose_ands_predicates_and_takes_the_stricter_depth(): void
    {
        $root = $this->node(1, name: 'root', lft: 1, rgt: 8, depth: 0, parentId: null);
        $a = $this->node(2, name: 'A', lft: 2, rgt: 7, depth: 1, parentId: 1);
        $x = $this->node(3, name: 'X', lft: 3, rgt: 6, depth: 2, parentId: 2);
        $y = $this->node(4, name: 'Y', lft: 4, rgt: 5, depth: 3, parentId: 3);

        $walker = new SubtreeWalker(new EloquentCollection([$root, $a, $x, $y]), $root);

        $shallower = WalkFilter::depth(1);
        $deeper = WalkFilter::depth(3);
        $composedDepth = WalkFilter::compose($shallower, $deeper);

        $names = $this->namesOf($walker->dfs($composedDepth));
        // Stricter (1) wins — root + A only.
        $this->assertSame(['root', 'A'], $names);

        $notRoot = WalkFilter::where(
            static fn (Model&HasNestedSet $n): bool => self::nameOf($n) !== 'root',
        );
        $notX = WalkFilter::where(
            static fn (Model&HasNestedSet $n): bool => self::nameOf($n) !== 'X',
        );
        $composedWhere = WalkFilter::compose($notRoot, $notX);

        $names = $this->namesOf($walker->dfs($composedWhere));
        // Root excluded → walk yields nothing because filter rejects the
        // walk anchor itself and skips its subtree.
        $this->assertSame([], $names);

        // Verify the instance-form helper produces an equivalent filter.
        $this->assertSame(
            iterator_to_array($walker->dfs(WalkFilter::compose($shallower, $deeper))),
            iterator_to_array($walker->dfs($shallower->andThen($deeper))),
        );
    }

    public function test_include_root_false_visits_descendants_only_keeping_depth_relative(): void
    {
        $root = $this->node(1, name: 'root', lft: 1, rgt: 6, depth: 0, parentId: null);
        $a = $this->node(2, name: 'A', lft: 2, rgt: 3, depth: 1, parentId: 1);
        $b = $this->node(3, name: 'B', lft: 4, rgt: 5, depth: 1, parentId: 1);

        $walker = new SubtreeWalker(new EloquentCollection([$root, $a, $b]), $root);

        $filter = new WalkFilter(includeRoot: false);

        $observed = [];
        $walker->walk(function (Model&HasNestedSet $node, WalkContext $ctx) use (&$observed): void {
            $observed[self::nameOf($node)] = $ctx->depth;
        }, filter: $filter);

        $this->assertArrayNotHasKey('root', $observed);
        $this->assertSame(1, $observed['A']);
        $this->assertSame(1, $observed['B']);
    }

    public function test_where_callback_receives_context_with_sibling_and_depth_data(): void
    {
        $root = $this->node(1, name: 'root', lft: 1, rgt: 6, depth: 0, parentId: null);
        $a = $this->node(2, name: 'A', lft: 2, rgt: 3, depth: 1, parentId: 1);
        $b = $this->node(3, name: 'B', lft: 4, rgt: 5, depth: 1, parentId: 1);

        $walker = new SubtreeWalker(new EloquentCollection([$root, $a, $b]), $root);

        /** @var array<string, array{depth: int, idx: int, count: int}> $seen */
        $seen = [];
        $filter = WalkFilter::where(function (Model&HasNestedSet $node, WalkContext $ctx) use (&$seen): bool {
            $seen[self::nameOf($node)] = [
                'depth' => $ctx->depth,
                'idx' => $ctx->siblingIndex,
                'count' => $ctx->siblingCount,
            ];

            return true;
        });

        iterator_to_array($walker->dfs($filter));

        $this->assertSame(['depth' => 0, 'idx' => 0, 'count' => 1], $seen['root']);
        $this->assertSame(['depth' => 1, 'idx' => 0, 'count' => 2], $seen['A']);
        $this->assertSame(['depth' => 1, 'idx' => 1, 'count' => 2], $seen['B']);
    }

    public function test_depth_rejects_negative_input_with_actionable_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // The catch is "negative depth silently rejects every node" —
        // the message should make that explicit.
        $this->expectExceptionMessageMatches('/maxDepth.*>= 0.*-1/s');

        WalkFilter::depth(-1);
    }

    public function test_depth_zero_is_a_valid_root_only_walk(): void
    {
        // Boundary: depth(0) means "visit the walk root only" — a
        // legitimate use case, not an error.
        $filter = WalkFilter::depth(0);

        $this->assertSame(0, $filter->maxDepth);
    }

    public function test_compose_handles_null_inputs_gracefully(): void
    {
        $f = WalkFilter::depth(3);

        $this->assertSame($f, WalkFilter::compose($f, null));
        $this->assertSame($f, WalkFilter::compose(null, $f));

        $bothNull = WalkFilter::compose(null, null);
        $this->assertNull($bothNull->maxDepth);
        $this->assertNull($bothNull->visitable);
        $this->assertTrue($bothNull->includeRoot);
    }

    public function test_compose_keeps_the_single_predicate_when_only_one_side_carries_it(): void
    {
        // When one input has a `where` and the other only carries
        // `maxDepth`, the result should keep the predicate verbatim
        // (rather than building an AND with a no-op).
        $depthOnly = WalkFilter::depth(5);
        $whereOnly = WalkFilter::where(
            static fn (Model&HasNestedSet $n): bool => self::nameOf($n) === 'keep',
        );

        $a = WalkFilter::compose($depthOnly, $whereOnly);
        $this->assertSame($whereOnly->visitable, $a->visitable);

        $b = WalkFilter::compose($whereOnly, $depthOnly);
        $this->assertSame($whereOnly->visitable, $b->visitable);
    }

    /**
     * @param  iterable<Model&HasNestedSet>  $iter
     * @return list<string>
     */
    private function namesOf(iterable $iter): array
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
