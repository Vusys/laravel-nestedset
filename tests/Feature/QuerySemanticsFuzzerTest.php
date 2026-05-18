<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * @phpstan-type Oracle array{
 *     descendants: array<int, list<int>>,
 *     ancestors:   array<int, list<int>>,
 *     siblings:    array<int, list<int>>,
 *     children:    array<int, list<int>>,
 *     parent:      array<int, int|null>,
 *     depth:       array<int, int>,
 *     height:      array<int, int>,
 * }
 *
 * Random trees + every public read-API method on every node.
 *
 * Existing tests assert query results on a fixed 5-node tree. Bugs
 * where a query returns wrong results for trees of unusual shape
 * (deep chain, wide fanout, single-leaf root, two-root forest) are
 * easy to miss with a single fixture.
 *
 * This fuzzer:
 *   1. Builds a deterministic random tree (seeded).
 *   2. For every node in the tree, queries every relation
 *      (`children`, `descendants`, `ancestors`, `parent`) and every
 *      inspection method (`isRoot`, `isLeaf`, `isChild`,
 *      `isDescendantOf`, `isAncestorOf`, `isSiblingOf`,
 *      `getDescendantCount`, `getNodeHeight`).
 *   3. Asserts each result against a PHP-side "oracle" computed
 *      directly from lft/rgt — the ground truth.
 *
 * The oracle deliberately uses a different code path (in-memory
 * scan over loaded rows) than the queries (DB SQL) so the test
 * actually catches divergence rather than checking SQL against
 * itself.
 */
final class QuerySemanticsFuzzerTest extends TestCase
{
    /**
     * @return iterable<string, array{seed: int, plantSize: int}>
     */
    public static function seedProvider(): iterable
    {
        yield 'seed 1, 12 nodes' => ['seed' => 1, 'plantSize' => 12];

        yield 'seed 42, 15 nodes' => ['seed' => 42, 'plantSize' => 15];

        yield 'seed 1337, 20 nodes' => ['seed' => 1337, 'plantSize' => 20];

        yield 'seed 9999, 25 nodes' => ['seed' => 9999, 'plantSize' => 25];

        yield 'seed 314159, 30 nodes' => ['seed' => 314159, 'plantSize' => 30];
    }

    #[DataProvider('seedProvider')]
    public function test_query_results_match_bounds_oracle(int $seed, int $plantSize): void
    {
        mt_srand($seed);

        // Plant a random tree.
        (new Category(['name' => 'root']))->saveAsRoot();
        for ($i = 1; $i < $plantSize; $i++) {
            $all = Category::query()->orderBy('lft')->get()->all();
            $parent = $all[mt_rand(0, count($all) - 1)];
            $method = ['appendToNode', 'prependToNode'][mt_rand(0, 1)];
            $node = new Category(['name' => "n{$i}"]);
            $node->{$method}($parent->refresh())->save();
        }

        /** @var list<Category> $all */
        $all = array_values(Category::query()->orderBy('lft')->get()->all());

        // Cross-product: for every node, exercise every relation
        // + every pairwise inspection method against every other.
        $oracle = $this->buildOracle($all);

        foreach ($all as $node) {
            $this->assertNodeMatchesOracle($node, $all, $oracle, $seed);
        }
    }

    /**
     * The PHP-side oracle: for every node id, the bounds-derived
     * descendant set, ancestor set, sibling set, and parent.
     *
     * Computed in-memory once per test; subsequent assertions look
     * up by id rather than rescanning.
     *
     * @param  list<Category>  $all
     * @return Oracle
     */
    private function buildOracle(array $all): array
    {
        $descendants = [];
        $ancestors = [];
        $siblings = [];
        $children = [];
        $parent = [];
        $depth = [];
        $height = [];

        foreach ($all as $node) {
            $id = (int) $node->id;
            $descendants[$id] = [];
            $ancestors[$id] = [];
            $siblings[$id] = [];
            $children[$id] = [];
            $parent[$id] = $node->parent_id === null ? null : (int) $node->parent_id;
            $depth[$id] = 0;
            $height[$id] = 0;
        }

        foreach ($all as $node) {
            $id = (int) $node->id;
            foreach ($all as $other) {
                if ($other->id === $node->id) {
                    continue;
                }
                $oid = (int) $other->id;

                // descendant: lft within node's bounds (strict).
                if ($other->lft > $node->lft && $other->rgt < $node->rgt) {
                    $descendants[$id][] = $oid;
                }
                // ancestor: node within other's bounds.
                if ($other->lft < $node->lft && $other->rgt > $node->rgt) {
                    $ancestors[$id][] = $oid;
                }
                // sibling: same parent_id (both non-null).
                if ($node->parent_id !== null
                    && $other->parent_id !== null
                    && (int) $node->parent_id === (int) $other->parent_id) {
                    $siblings[$id][] = $oid;
                }
                // child: other.parent_id === node.id.
                if ($other->parent_id !== null && (int) $other->parent_id === $id) {
                    $children[$id][] = $oid;
                }
            }

            $depth[$id] = count($ancestors[$id]);
        }

        // `getNodeHeight()` is documented as `rgt - lft + 1` (slot
        // count), NOT tree-theory height. Mirror that.
        foreach ($all as $node) {
            $id = (int) $node->id;
            $height[$id] = $node->rgt - $node->lft + 1;
        }

        return ['descendants' => $descendants, 'ancestors' => $ancestors, 'siblings' => $siblings, 'children' => $children, 'parent' => $parent, 'depth' => $depth, 'height' => $height];
    }

    /**
     * @param  list<Category>  $all
     * @param  Oracle  $oracle
     */
    private function assertNodeMatchesOracle(Category $node, array $all, array $oracle, int $seed): void
    {
        $id = (int) $node->id;
        $tag = "[seed={$seed}] node #{$id}";

        // children
        $expectedChildren = $oracle['children'][$id];
        sort($expectedChildren);
        $actualChildren = $this->toIntList($node->children()->pluck('id')->all());
        sort($actualChildren);
        $this->assertSame($expectedChildren, $actualChildren, "{$tag}: children() mismatch");

        // descendants
        $expectedDescendants = $oracle['descendants'][$id];
        sort($expectedDescendants);
        $actualDescendants = $this->toIntList($node->descendants()->pluck('id')->all());
        sort($actualDescendants);
        $this->assertSame($expectedDescendants, $actualDescendants, "{$tag}: descendants() mismatch");

        // ancestors
        $expectedAncestors = $oracle['ancestors'][$id];
        sort($expectedAncestors);
        $actualAncestors = $this->toIntList($node->ancestors()->pluck('id')->all());
        sort($actualAncestors);
        $this->assertSame($expectedAncestors, $actualAncestors, "{$tag}: ancestors() mismatch");

        // parent
        $expectedParent = $oracle['parent'][$id];
        $actualParent = $node->parent;
        $this->assertSame(
            $expectedParent,
            $actualParent === null ? null : (int) $actualParent->id,
            "{$tag}: parent() mismatch",
        );

        // isRoot / isLeaf / isChild
        $this->assertSame($expectedParent === null, $node->isRoot(), "{$tag}: isRoot mismatch");
        $this->assertSame($oracle['children'][$id] === [], $node->isLeaf(), "{$tag}: isLeaf mismatch");
        $this->assertSame($expectedParent !== null, $node->isChild(), "{$tag}: isChild mismatch");

        // getDescendantCount
        $this->assertSame(
            count($oracle['descendants'][$id]),
            $node->getDescendantCount(),
            "{$tag}: getDescendantCount mismatch",
        );

        // getNodeHeight
        $this->assertSame(
            $oracle['height'][$id],
            $node->getNodeHeight(),
            "{$tag}: getNodeHeight mismatch",
        );

        // Pairwise: isDescendantOf / isAncestorOf / isSiblingOf
        foreach ($all as $other) {
            if ($other->id === $node->id) {
                continue;
            }
            $oid = (int) $other->id;
            $pairTag = "{$tag} vs #{$oid}";

            $this->assertSame(
                in_array($oid, $oracle['ancestors'][$id], true),
                $node->isDescendantOf($other),
                "{$pairTag}: isDescendantOf mismatch",
            );
            $this->assertSame(
                in_array($oid, $oracle['descendants'][$id], true),
                $node->isAncestorOf($other),
                "{$pairTag}: isAncestorOf mismatch",
            );
            $this->assertSame(
                in_array($oid, $oracle['siblings'][$id], true),
                $node->isSiblingOf($other),
                "{$pairTag}: isSiblingOf mismatch",
            );
        }
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private function toIntList(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            if (! is_numeric($v)) {
                $this->fail('expected numeric id, got '.get_debug_type($v));
            }
            $out[] = (int) $v;
        }

        return $out;
    }
}
