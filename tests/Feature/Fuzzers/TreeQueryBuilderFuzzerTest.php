<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Fuzzers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Support\FuzzerConfig;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Coverage for `TreeQueryBuilder` methods not exercised by
 * {@see QuerySemanticsFuzzerTest} (which goes after relations and
 * predicates on the node itself). This file targets the *builder*
 * surface: `whereDescendantOf(..., andSelf: true)`,
 * `whereAncestorOf(..., andSelf: true)`, `whereIsAfter`,
 * `whereIsBefore`, `withoutRoot`, `withDepth`, `root()`,
 * `defaultOrder` / `reversed`, plus a couple of chained compositions.
 *
 * Strategy: plant a random tree, build a pure-PHP oracle, then
 * compare each builder method's id-set against the oracle.
 */
#[Group('fuzzer')]
final class TreeQueryBuilderFuzzerTest extends TestCase
{
    /**
     * @return iterable<string, array{seed: int, size: int}>
     */
    public static function seedProvider(): iterable
    {
        $seeds = FuzzerConfig::seeds([1, 42, 1337, 9999]);
        $size = FuzzerConfig::steps(15);

        foreach ($seeds as $seed) {
            yield "seed {$seed}, {$size} nodes" => ['seed' => $seed, 'size' => $size];
        }
    }

    #[DataProvider('seedProvider')]
    #[Test]
    public function builder_methods_match_oracle(int $seed, int $size): void
    {
        mt_srand($seed);

        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();

        for ($i = 1; $i < $size; $i++) {
            $parents = Category::query()->get()->all();
            $parent = $parents[mt_rand(0, count($parents) - 1)];
            $node = new Category(['name' => "n{$i}"]);
            $node->appendToNode($parent->refresh())->save();
        }

        /** @var list<Category> $all */
        $all = Category::query()->orderBy('lft')->get()->all();

        // PHP oracle keyed by id.
        /** @var array<int, array{lft: int, rgt: int, depth: int, parent_id: int|null}> $byId */
        $byId = [];
        foreach ($all as $n) {
            $byId[(int) $n->id] = [
                'lft' => $n->lft,
                'rgt' => $n->rgt,
                'depth' => $n->depth,
                'parent_id' => $n->parent_id,
            ];
        }

        $tag = "[seed={$seed}]";

        // whereIsRoot: every parent_id IS NULL.
        $expectedRoots = $this->idsWhere($byId, fn (array $row): bool => $row['parent_id'] === null);
        $actualRoots = Category::query()->whereIsRoot()->pluck('id')->all();
        $this->assertSameIdSet($expectedRoots, $actualRoots, "{$tag} whereIsRoot");

        // root() — single root (we only planted one).
        $singleRoot = Category::query()->root();
        $this->assertNotNull($singleRoot, "{$tag} root() returned null");
        $this->assertContains((int) $singleRoot->id, $expectedRoots, "{$tag} root() returned non-root");

        // whereIsLeaf: rgt - lft == 1.
        $expectedLeaves = $this->idsWhere($byId, fn (array $row): bool => $row['rgt'] - $row['lft'] === 1);
        $actualLeaves = Category::query()->whereIsLeaf()->pluck('id')->all();
        $this->assertSameIdSet($expectedLeaves, $actualLeaves, "{$tag} whereIsLeaf");
        // leaves() is an alias.
        $aliasLeaves = Category::query()->leaves()->pluck('id')->all();
        $this->assertSameIdSet($expectedLeaves, $aliasLeaves, "{$tag} leaves() alias");

        // withoutRoot: parent_id IS NOT NULL.
        $expectedNonRoot = $this->idsWhere($byId, fn (array $row): bool => $row['parent_id'] !== null);
        $actualNonRoot = Category::query()->withoutRoot()->pluck('id')->all();
        $this->assertSameIdSet($expectedNonRoot, $actualNonRoot, "{$tag} withoutRoot");

        // For every node, exercise the bounds-based methods.
        foreach ($all as $node) {
            $bounds = new NodeBounds($node->lft, $node->rgt, $node->depth);
            $id = (int) $node->id;
            $nodeTag = "{$tag} node #{$id}";

            // whereDescendantOf — strict
            $expectedDesc = $this->idsWhere(
                $byId,
                fn (array $row, int $rowId): bool => $row['lft'] > $node->lft && $row['rgt'] < $node->rgt && $rowId !== $id,
            );
            $actualDesc = Category::query()->whereDescendantOf($bounds)->pluck('id')->all();
            $this->assertSameIdSet($expectedDesc, $actualDesc, "{$nodeTag} whereDescendantOf");

            // whereDescendantOrSelf
            $expectedDescOrSelf = [...$expectedDesc, $id];
            $actualDescOrSelf = Category::query()->whereDescendantOrSelf($bounds)->pluck('id')->all();
            $this->assertSameIdSet($expectedDescOrSelf, $actualDescOrSelf, "{$nodeTag} whereDescendantOrSelf");

            // whereAncestorOf — strict
            $expectedAnc = $this->idsWhere(
                $byId,
                fn (array $row, int $rowId): bool => $row['lft'] < $node->lft && $row['rgt'] > $node->rgt && $rowId !== $id,
            );
            $actualAnc = Category::query()->whereAncestorOf($bounds)->pluck('id')->all();
            $this->assertSameIdSet($expectedAnc, $actualAnc, "{$nodeTag} whereAncestorOf");

            // whereAncestorOrSelf
            $expectedAncOrSelf = [...$expectedAnc, $id];
            $actualAncOrSelf = Category::query()->whereAncestorOrSelf($bounds)->pluck('id')->all();
            $this->assertSameIdSet($expectedAncOrSelf, $actualAncOrSelf, "{$nodeTag} whereAncestorOrSelf");

            // whereIsAfter: lft > $bounds->rgt
            $expectedAfter = $this->idsWhere(
                $byId,
                fn (array $row): bool => $row['lft'] > $node->rgt,
            );
            $actualAfter = Category::query()->whereIsAfter($bounds)->pluck('id')->all();
            $this->assertSameIdSet($expectedAfter, $actualAfter, "{$nodeTag} whereIsAfter");

            // whereIsBefore: rgt < $bounds->lft
            $expectedBefore = $this->idsWhere(
                $byId,
                fn (array $row): bool => $row['rgt'] < $node->lft,
            );
            $actualBefore = Category::query()->whereIsBefore($bounds)->pluck('id')->all();
            $this->assertSameIdSet($expectedBefore, $actualBefore, "{$nodeTag} whereIsBefore");
        }

        // defaultOrder / reversed: simple sanity — strictly monotonic by lft.
        $ordered = Category::query()->defaultOrder()->pluck('lft')->all();
        $expectedAsc = $ordered;
        sort($expectedAsc);
        $this->assertSame($expectedAsc, $ordered, "{$tag} defaultOrder is ASC by lft");

        $reversed = Category::query()->reversed()->pluck('lft')->all();
        $expectedDesc = $reversed;
        rsort($expectedDesc);
        $this->assertSame($expectedDesc, $reversed, "{$tag} reversed is DESC by lft");

        // withDepth: alias must match the underlying depth column.
        $withDepth = Category::query()->withDepth('my_depth')->get();
        foreach ($withDepth as $row) {
            $this->assertSame(
                $row->getAttribute('depth'),
                $row->getAttribute('my_depth'),
                "{$tag} withDepth alias mismatch",
            );
        }

        // Chaining: leaves of a specific subtree.
        $randomNode = $all[mt_rand(0, count($all) - 1)];
        $randomBounds = new NodeBounds($randomNode->lft, $randomNode->rgt, $randomNode->depth);

        $expectedLeavesInSubtree = $this->idsWhere(
            $byId,
            fn (array $row, int $rowId): bool => $row['lft'] >= $randomNode->lft
                && $row['rgt'] <= $randomNode->rgt
                && $row['rgt'] - $row['lft'] === 1,
        );
        $actualLeavesInSubtree = Category::query()
            ->whereDescendantOrSelf($randomBounds)
            ->whereIsLeaf()
            ->pluck('id')
            ->all();
        $this->assertSameIdSet(
            $expectedLeavesInSubtree,
            $actualLeavesInSubtree,
            "{$tag} chained whereDescendantOrSelf + whereIsLeaf",
        );
    }

    /**
     * @param  array<int, array{lft: int, rgt: int, depth: int, parent_id: int|null}>  $byId
     * @param  callable(array{lft: int, rgt: int, depth: int, parent_id: int|null}, int): bool  $predicate
     * @return list<int>
     */
    private function idsWhere(array $byId, callable $predicate): array
    {
        $out = [];
        foreach ($byId as $id => $row) {
            if ($predicate($row, $id)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $expected
     * @param  array<int, mixed>  $actual
     */
    private function assertSameIdSet(array $expected, array $actual, string $tag): void
    {
        $expectedSorted = $expected;
        sort($expectedSorted);

        $actualInts = [];
        foreach ($actual as $value) {
            if (! is_numeric($value)) {
                $this->fail("{$tag}: non-numeric id ".var_export($value, true));
            }
            $actualInts[] = (int) $value;
        }
        sort($actualInts);

        $this->assertSame($expectedSorted, $actualInts, "{$tag}: id set mismatch");
    }
}
