<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Testing;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;

/**
 * Test-time assertion helpers for models that use `NodeTrait`. Drop
 * into a PHPUnit `TestCase` to shorten the common boilerplate users
 * end up writing against tree models:
 *
 * ```php
 * use Vusys\NestedSet\Testing\InteractsWithTrees;
 *
 * final class CategoryTest extends TestCase
 * {
 *     use InteractsWithTrees;
 *
 *     public function test_category_tree_shape(): void
 *     {
 *         $root = Category::factory()->create();
 *         $child = (new Category)->appendToNode($root)->save();
 *
 *         $this->assertIsRoot($root);
 *         $this->assertIsChildOf($child, $root);
 *         $this->assertIsLeaf($child);
 *         $this->assertTreeIsIntact(Category::class);
 *     }
 * }
 * ```
 *
 * All assertions accept any `HasNestedSet` (the contract). Methods
 * that need Eloquent (counts, fresh-aggregate fetches) additionally
 * require the node to be a `Model` — runtime-narrowed inside each
 * assertion, with a clear failure message if it isn't.
 */
trait InteractsWithTrees
{
    // ----------------------------------------------------------------
    // Structural assertions
    // ----------------------------------------------------------------

    /**
     * Asserts that `$node` is a root — its `parent_id` is NULL.
     */
    public function assertIsRoot(HasNestedSet $node, string $message = ''): void
    {
        $this->assertNull(
            $node->getParentId(),
            $message !== '' ? $message : sprintf(
                'Expected node to be a root (parent_id NULL); parent_id is %d.',
                (int) $node->getParentId(),
            ),
        );
    }

    /**
     * Asserts that `$node` is a leaf — `rgt = lft + 1`. Equivalent
     * to "has no descendants".
     */
    public function assertIsLeaf(HasNestedSet $node, string $message = ''): void
    {
        $expected = $node->getLft() + 1;
        $this->assertSame(
            $expected,
            $node->getRgt(),
            $message !== '' ? $message : sprintf(
                'Expected node to be a leaf (rgt = lft + 1, %d); got rgt = %d.',
                $expected,
                $node->getRgt(),
            ),
        );
    }

    /**
     * Asserts that `$node` is *not* a leaf — it has at least one
     * descendant. Useful for ensuring a setup step actually built a
     * subtree.
     */
    public function assertIsNotLeaf(HasNestedSet $node, string $message = ''): void
    {
        $this->assertNotSame(
            $node->getLft() + 1,
            $node->getRgt(),
            $message !== '' ? $message : 'Expected node to have descendants (rgt > lft + 1); it is a leaf.',
        );
    }

    /**
     * Asserts that `$node` is a direct child of `$parent` — same
     * scope, parent_id matches, depth = parent.depth + 1.
     */
    public function assertIsChildOf(HasNestedSet $node, HasNestedSet&Model $parent, string $message = ''): void
    {
        $expectedParentId = self::keyAsInt($parent);
        $this->assertSame(
            $expectedParentId,
            $node->getParentId(),
            $message !== '' ? $message : sprintf(
                'Expected node parent_id to be %d; got %s.',
                $expectedParentId,
                $node->getParentId() === null ? 'NULL' : (string) $node->getParentId(),
            ),
        );
        $this->assertSame(
            $parent->getDepth() + 1,
            $node->getDepth(),
            'parent_id matched but depth disagrees — tree may be corrupted.',
        );
    }

    /**
     * Asserts that `$node` is a strict descendant of `$ancestor` —
     * `$ancestor.lft < $node.lft AND $node.rgt < $ancestor.rgt`,
     * within the same scope.
     */
    public function assertIsDescendantOf(HasNestedSet $node, HasNestedSet $ancestor, string $message = ''): void
    {
        $this->assertTrue(
            $ancestor->getBounds()->contains($node->getBounds()),
            $message !== '' ? $message : sprintf(
                'Expected node bounds (%d, %d) to be strictly inside ancestor bounds (%d, %d).',
                $node->getLft(),
                $node->getRgt(),
                $ancestor->getLft(),
                $ancestor->getRgt(),
            ),
        );
    }

    /**
     * Asserts that `$a` is a strict ancestor of `$b`. Symmetric
     * counterpart to {@see assertIsDescendantOf()}.
     */
    public function assertIsAncestorOf(HasNestedSet $a, HasNestedSet $b, string $message = ''): void
    {
        $this->assertTrue(
            $a->getBounds()->contains($b->getBounds()),
            $message !== '' ? $message : sprintf(
                'Expected (%d, %d) to strictly contain (%d, %d).',
                $a->getLft(),
                $a->getRgt(),
                $b->getLft(),
                $b->getRgt(),
            ),
        );
    }

    /**
     * Asserts that `$node` has exactly `$count` descendants in its
     * subtree (every node strictly below `$node`, at any depth).
     *
     * Counted from `(rgt - lft - 1) / 2` — derived from the nested-set
     * bounds without an extra query.
     */
    public function assertHasDescendants(HasNestedSet $node, int $count, string $message = ''): void
    {
        $actual = (int) (($node->getRgt() - $node->getLft() - 1) / 2);
        $this->assertSame(
            $count,
            $actual,
            $message !== '' ? $message : sprintf(
                'Expected node to have %d descendant(s); has %d.',
                $count,
                $actual,
            ),
        );
    }

    /**
     * Asserts that `$node` has exactly `$count` direct children
     * (parent_id pointing at it). Requires the node to be a
     * persisted Eloquent model; emits a clear failure if it isn't.
     */
    public function assertHasChildren(HasNestedSet&Model $node, int $count, string $message = ''): void
    {
        $modelClass = $node::class;
        $actual = $modelClass::query()
            ->where($node->getParentIdName(), self::keyAsInt($node))
            ->count();

        $this->assertSame(
            $count,
            (int) $actual,
            $message !== '' ? $message : sprintf(
                'Expected node to have %d direct child(ren); has %d.',
                $count,
                (int) $actual,
            ),
        );
    }

    // ----------------------------------------------------------------
    // Aggregate assertions
    // ----------------------------------------------------------------

    /**
     * Asserts that the stored aggregate column on `$node` matches the
     * freshly-computed value for the same node. Useful for catching
     * drift introduced by tests that mutate aggregate inputs without
     * going through Eloquent.
     */
    public function assertAggregateMatchesFresh(HasNestedSet&Model $node, string $column, string $message = ''): void
    {
        if (! method_exists($node, 'freshAggregate')) {
            $this->fail(sprintf(
                'assertAggregateMatchesFresh: %s does not declare aggregate columns (use NodeTrait + #[NestedSetAggregate]).',
                $node::class,
            ));
        }

        $stored = $node->getAttribute($column);
        $fresh = $node->freshAggregate($column);

        // Tolerant numeric comparison so a decimal:4 AVG stored as "56.2500" matches a float 56.25.
        if (is_numeric($stored) && is_numeric($fresh)) {
            $this->assertEqualsWithDelta(
                (float) $fresh,
                (float) $stored,
                0.0001,
                $message !== '' ? $message : sprintf(
                    'Aggregate "%s" drifted: stored %s, fresh %s.',
                    $column,
                    (string) $stored,
                    (string) $fresh,
                ),
            );

            return;
        }

        $this->assertSame(
            $fresh,
            $stored,
            $message !== '' ? $message : sprintf('Aggregate "%s" drifted.', $column),
        );
    }

    // ----------------------------------------------------------------
    // Integrity assertions
    // ----------------------------------------------------------------

    /**
     * Asserts that the tree on the given model class has no
     * structural integrity errors (no invalid bounds, no duplicate
     * lft/rgt, no orphans). On scoped models, pass an anchor.
     *
     * @param  class-string  $modelClass
     */
    public function assertTreeIsIntact(string $modelClass, ?HasNestedSet $anchor = null, string $message = ''): void
    {
        if (! method_exists($modelClass, 'isBroken') || ! method_exists($modelClass, 'countErrors')) {
            $this->fail(sprintf(
                'assertTreeIsIntact: %s does not use NodeTrait (or HasTreeRepair).',
                $modelClass,
            ));
        }

        if ($modelClass::isBroken($anchor)) {
            $errors = $modelClass::countErrors($anchor);
            $this->fail($message !== '' ? $message : sprintf(
                'Tree on %s has structural errors: %s.',
                $modelClass,
                self::summariseCounts($errors),
            ));
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Asserts that the stored aggregate columns on the given model
     * class have no drift against the freshly-computed values. On
     * scoped models, pass an anchor.
     *
     * @param  class-string  $modelClass
     */
    public function assertAggregatesAreIntact(string $modelClass, ?HasNestedSet $anchor = null, string $message = ''): void
    {
        if (! method_exists($modelClass, 'aggregatesAreBroken') || ! method_exists($modelClass, 'aggregateErrors')) {
            $this->fail(sprintf(
                'assertAggregatesAreIntact: %s does not declare aggregate columns (use NodeTrait + #[NestedSetAggregate]).',
                $modelClass,
            ));
        }

        // `aggregateErrors()` keys the result by user-facing aggregate
        // column name. An empty array means the model declares no
        // aggregates — passing silently would mislead the user who
        // expected to be asserting drift. Fail with a clear message.
        $errors = $modelClass::aggregateErrors($anchor);
        if ($errors === []) {
            $this->fail(sprintf(
                'assertAggregatesAreIntact: %s declares no aggregate columns. Add #[NestedSetAggregate(...)] declarations or use assertTreeIsIntact() for structural checks only.',
                $modelClass,
            ));
        }

        if ($modelClass::aggregatesAreBroken($anchor)) {
            $this->fail($message !== '' ? $message : sprintf(
                'Stored aggregates on %s disagree with fresh computation: %s.',
                $modelClass,
                self::summariseCounts($errors),
            ));
        }

        $this->addToAssertionCount(1);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * Narrows the mixed return of Eloquent's `getKey()` to int.
     * The package requires integer primary keys on nested-set models.
     */
    private static function keyAsInt(Model $node): int
    {
        $key = $node->getKey();
        if (! is_numeric($key)) {
            throw new \LogicException(sprintf(
                'NestedSet testing: expected integer primary key, got %s.',
                get_debug_type($key),
            ));
        }

        return (int) $key;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private static function summariseCounts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $key => $value) {
            if ($value > 0) {
                $parts[] = "{$key}={$value}";
            }
        }

        return $parts === [] ? '(zero across all categories)' : implode(', ', $parts);
    }
}
