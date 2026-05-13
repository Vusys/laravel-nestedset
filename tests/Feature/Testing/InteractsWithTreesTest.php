<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Self-tests for the `InteractsWithTrees` helper trait. Each
 * assertion has at least one positive case (passes when it should)
 * and at least one negative case (fails when it should).
 *
 * Negative cases use the AssertionFailedError catch pattern:
 * PHPUnit's `assert*` methods throw that exception on failure, so
 * catching it lets us verify the assertion fired its diagnostic.
 */
final class InteractsWithTreesTest extends TestCase
{
    use InteractsWithTrees;

    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    /**
     * Tree shape used throughout:
     *
     *   Root(100)  lft=1  rgt=8  depth=0
     *   ├── A(50)  lft=2  rgt=5  depth=1
     *   │   └── A1(50)  lft=3  rgt=4  depth=2
     *   └── B(25)  lft=6  rgt=7  depth=1
     */
    private function seedMotivatingTree(): void
    {
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();

        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root)->save();

        $a1 = new Area(['name' => 'A1', 'tickets' => 50]);
        $a1->appendToNode($a->refresh())->save();

        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();
    }

    /**
     * Captures the AssertionFailedError thrown by a failing assertion.
     * Lets us assert that a *negative* case actually fails (rather
     * than silently passing).
     */
    private function expectFailure(callable $callable): AssertionFailedError
    {
        try {
            $callable();
        } catch (AssertionFailedError $e) {
            return $e;
        }

        $this->fail('Expected an AssertionFailedError to be thrown, but none was.');
    }

    // ----------------------------------------------------------------
    // assertIsRoot / assertIsLeaf / assertIsNotLeaf
    // ----------------------------------------------------------------

    public function test_assert_is_root_passes_for_root_node(): void
    {
        $this->seedMotivatingTree();
        $root = Area::query()->where('name', 'Root')->firstOrFail();

        $this->assertIsRoot($root);
    }

    public function test_assert_is_root_fails_for_non_root(): void
    {
        $this->seedMotivatingTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();

        $error = $this->expectFailure(fn () => $this->assertIsRoot($a));
        $this->assertStringContainsString('root', $error->getMessage());
    }

    public function test_assert_is_leaf_passes_for_leaf(): void
    {
        $this->seedMotivatingTree();
        $b = Area::query()->where('name', 'B')->firstOrFail();

        $this->assertIsLeaf($b);
    }

    public function test_assert_is_leaf_fails_for_internal_node(): void
    {
        $this->seedMotivatingTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();

        $this->expectFailure(fn () => $this->assertIsLeaf($a));
    }

    public function test_assert_is_not_leaf_passes_for_internal_node(): void
    {
        $this->seedMotivatingTree();
        $root = Area::query()->where('name', 'Root')->firstOrFail();

        $this->assertIsNotLeaf($root);
    }

    public function test_assert_is_not_leaf_fails_for_leaf(): void
    {
        $this->seedMotivatingTree();
        $b = Area::query()->where('name', 'B')->firstOrFail();

        $this->expectFailure(fn () => $this->assertIsNotLeaf($b));
    }

    // ----------------------------------------------------------------
    // assertIsChildOf / assertIsDescendantOf / assertIsAncestorOf
    // ----------------------------------------------------------------

    public function test_assert_is_child_of_passes_for_direct_child(): void
    {
        $this->seedMotivatingTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $root = Area::query()->where('name', 'Root')->firstOrFail();

        $this->assertIsChildOf($a, $root);
    }

    public function test_assert_is_child_of_fails_for_grandchild(): void
    {
        $this->seedMotivatingTree();
        $a1 = Area::query()->where('name', 'A1')->firstOrFail();
        $root = Area::query()->where('name', 'Root')->firstOrFail();

        $this->expectFailure(fn () => $this->assertIsChildOf($a1, $root));
    }

    public function test_assert_is_descendant_of_passes_for_grandchild(): void
    {
        $this->seedMotivatingTree();
        $a1 = Area::query()->where('name', 'A1')->firstOrFail();
        $root = Area::query()->where('name', 'Root')->firstOrFail();

        $this->assertIsDescendantOf($a1, $root);
    }

    public function test_assert_is_descendant_of_fails_for_sibling(): void
    {
        $this->seedMotivatingTree();
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $b = Area::query()->where('name', 'B')->firstOrFail();

        $this->expectFailure(fn () => $this->assertIsDescendantOf($a, $b));
    }

    public function test_assert_is_ancestor_of_passes_for_root_over_grandchild(): void
    {
        $this->seedMotivatingTree();
        $root = Area::query()->where('name', 'Root')->firstOrFail();
        $a1 = Area::query()->where('name', 'A1')->firstOrFail();

        $this->assertIsAncestorOf($root, $a1);
    }

    public function test_assert_is_ancestor_of_fails_for_reversed_relationship(): void
    {
        $this->seedMotivatingTree();
        $root = Area::query()->where('name', 'Root')->firstOrFail();
        $a1 = Area::query()->where('name', 'A1')->firstOrFail();

        // a1 is NOT an ancestor of root.
        $this->expectFailure(fn () => $this->assertIsAncestorOf($a1, $root));
    }

    // ----------------------------------------------------------------
    // assertHasDescendants / assertHasChildren
    // ----------------------------------------------------------------

    public function test_assert_has_descendants_passes_with_correct_count(): void
    {
        $this->seedMotivatingTree();
        $root = Area::query()->where('name', 'Root')->firstOrFail();

        // Root has A, A1, B = 3 descendants
        $this->assertHasDescendants($root, 3);

        // A has A1 = 1 descendant
        $a = Area::query()->where('name', 'A')->firstOrFail();
        $this->assertHasDescendants($a, 1);

        // Leaves have 0
        $b = Area::query()->where('name', 'B')->firstOrFail();
        $this->assertHasDescendants($b, 0);
    }

    public function test_assert_has_descendants_fails_with_wrong_count(): void
    {
        $this->seedMotivatingTree();
        $root = Area::query()->where('name', 'Root')->firstOrFail();

        $this->expectFailure(fn () => $this->assertHasDescendants($root, 99));
    }

    public function test_assert_has_children_counts_direct_children_only(): void
    {
        $this->seedMotivatingTree();
        $root = Area::query()->where('name', 'Root')->firstOrFail();

        // Root has A and B as direct children — NOT A1 (grandchild).
        $this->assertHasChildren($root, 2);

        $a = Area::query()->where('name', 'A')->firstOrFail();
        $this->assertHasChildren($a, 1);
    }

    public function test_assert_has_children_fails_with_wrong_count(): void
    {
        $this->seedMotivatingTree();
        $root = Area::query()->where('name', 'Root')->firstOrFail();

        $this->expectFailure(fn () => $this->assertHasChildren($root, 99));
    }

    // ----------------------------------------------------------------
    // assertAggregateMatchesFresh
    // ----------------------------------------------------------------

    public function test_assert_aggregate_matches_fresh_passes_on_intact_tree(): void
    {
        $this->seedMotivatingTree();
        $root = Area::query()->where('name', 'Root')->firstOrFail();

        $this->assertAggregateMatchesFresh($root, 'tickets_total');
        $this->assertAggregateMatchesFresh($root, 'tickets_count_all');
    }

    public function test_assert_aggregate_matches_fresh_fails_on_drifted_value(): void
    {
        $this->seedMotivatingTree();
        // Corrupt the stored value behind Eloquent's back.
        DB::table('areas')->where('name', 'Root')->update(['tickets_total' => 9999]);

        $root = Area::query()->where('name', 'Root')->firstOrFail();
        $error = $this->expectFailure(fn () => $this->assertAggregateMatchesFresh($root, 'tickets_total'));
        $this->assertStringContainsString('tickets_total', $error->getMessage());
    }

    // ----------------------------------------------------------------
    // assertTreeIsIntact / assertAggregatesAreIntact
    // ----------------------------------------------------------------

    public function test_assert_tree_is_intact_passes_on_clean_tree(): void
    {
        $this->seedMotivatingTree();

        $this->assertTreeIsIntact(Area::class);
    }

    public function test_assert_tree_is_intact_fails_when_lft_rgt_corrupted(): void
    {
        $this->seedMotivatingTree();
        // invalid_bounds: rgt must be > lft. Setting lft=10, rgt=5 on A
        // violates that invariant and `countErrors()` will flag it.
        DB::table('areas')->where('name', 'A')->update(['lft' => 10, 'rgt' => 5]);

        $error = $this->expectFailure(fn () => $this->assertTreeIsIntact(Area::class));
        $this->assertMatchesRegularExpression('/duplicate_lft|duplicate_rgt|invalid_bounds|orphans/', $error->getMessage());
    }

    public function test_assert_tree_is_intact_fails_clearly_on_non_node_model(): void
    {
        $error = $this->expectFailure(fn () => $this->assertTreeIsIntact(\stdClass::class));
        $this->assertStringContainsString('NodeTrait', $error->getMessage());
    }

    public function test_assert_aggregates_are_intact_passes_on_clean_tree(): void
    {
        $this->seedMotivatingTree();

        $this->assertAggregatesAreIntact(Area::class);
    }

    public function test_assert_aggregates_are_intact_fails_when_stored_drifted(): void
    {
        $this->seedMotivatingTree();
        DB::table('areas')->where('name', 'Root')->update(['tickets_total' => 9999]);

        $error = $this->expectFailure(fn () => $this->assertAggregatesAreIntact(Area::class));
        $this->assertStringContainsString('tickets_total', $error->getMessage());
    }

    public function test_assert_aggregates_are_intact_skips_when_model_declares_no_aggregates(): void
    {
        // Category has no aggregate columns — the helper should fail
        // *fast* with a clear message rather than silently passing.
        $category = new Category(['name' => 'Root']);
        $category->saveAsRoot();

        $error = $this->expectFailure(fn () => $this->assertAggregatesAreIntact(Category::class));
        $this->assertStringContainsString('aggregate', strtolower($error->getMessage()));
    }

    // ----------------------------------------------------------------
    // Defensive misuse paths — the helpers fail clearly when the
    // caller passes a model that doesn't implement the expected
    // surface. Exercises the `method_exists` and `is_numeric` guards.
    // ----------------------------------------------------------------

    public function test_assert_aggregates_are_intact_fails_clearly_on_non_node_model(): void
    {
        // stdClass has neither aggregatesAreBroken() nor
        // aggregateErrors(). The guard should fire fast with a
        // message that points the user at the missing surface.
        $error = $this->expectFailure(fn () => $this->assertAggregatesAreIntact(\stdClass::class));
        $this->assertStringContainsString('NestedSetAggregate', $error->getMessage());
    }

    public function test_assert_aggregate_matches_fresh_fails_clearly_on_non_aggregate_model(): void
    {
        // Category uses NodeTrait but declares no aggregate columns.
        // NodeTrait provides `freshAggregate()`, but calling it for an
        // undeclared column raises AggregateConfigurationException
        // — which the helper translates into a clean test-time
        // failure rather than the user seeing an exception class
        // they have to learn about.
        $category = new Category(['name' => 'Root']);
        $category->saveAsRoot();

        // freshAggregate() throws AggregateConfigurationException for an
        // unknown column, which surfaces as a test error — but the
        // helper itself triggers when the model doesn't have the
        // method at all. Use an anonymous Model that implements
        // HasNestedSet without NodeTrait to exercise the guard:
        $bareModel = new class extends Model implements HasNestedSet
        {
            public int $id = 1;

            public function getLft(): int
            {
                return 1;
            }

            public function getRgt(): int
            {
                return 2;
            }

            public function getDepth(): int
            {
                return 0;
            }

            public function getParentId(): ?int
            {
                return null;
            }

            public function getBounds(): NodeBounds
            {
                return new NodeBounds(1, 2, 0);
            }

            public function getLftName(): string
            {
                return 'lft';
            }

            public function getRgtName(): string
            {
                return 'rgt';
            }

            public function getDepthName(): string
            {
                return 'depth';
            }

            public function getParentIdName(): string
            {
                return 'parent_id';
            }
        };

        $error = $this->expectFailure(fn () => $this->assertAggregateMatchesFresh($bareModel, 'tickets_total'));
        $this->assertStringContainsString('NestedSetAggregate', $error->getMessage());
    }

    public function test_assert_is_child_of_rejects_non_integer_primary_key(): void
    {
        // The package documents integer primary keys as a hard
        // requirement (depth deltas, aggregate UPDATE bindings,
        // parent_id columns all assume int). Passing a parent with a
        // non-numeric key into an assertion that compares parent_id
        // surfaces with a clear LogicException — better than a
        // silent (int) cast that returns 0 and produces a misleading
        // assertion failure.
        $stringKeyParent = new class extends Model implements HasNestedSet
        {
            protected $primaryKey = 'id';

            public $incrementing = false;

            protected $keyType = 'string';

            protected $attributes = ['id' => 'not-a-number', 'lft' => 1, 'rgt' => 4, 'depth' => 0];

            public function getLft(): int
            {
                return 1;
            }

            public function getRgt(): int
            {
                return 4;
            }

            public function getDepth(): int
            {
                return 0;
            }

            public function getParentId(): ?int
            {
                return null;
            }

            public function getBounds(): NodeBounds
            {
                return new NodeBounds(1, 4, 0);
            }

            public function getLftName(): string
            {
                return 'lft';
            }

            public function getRgtName(): string
            {
                return 'rgt';
            }

            public function getDepthName(): string
            {
                return 'depth';
            }

            public function getParentIdName(): string
            {
                return 'parent_id';
            }
        };

        $child = new class extends Model implements HasNestedSet
        {
            public function getLft(): int
            {
                return 2;
            }

            public function getRgt(): int
            {
                return 3;
            }

            public function getDepth(): int
            {
                return 1;
            }

            public function getParentId(): int
            {
                return 1;
            }

            public function getBounds(): NodeBounds
            {
                return new NodeBounds(2, 3, 1);
            }

            public function getLftName(): string
            {
                return 'lft';
            }

            public function getRgtName(): string
            {
                return 'rgt';
            }

            public function getDepthName(): string
            {
                return 'depth';
            }

            public function getParentIdName(): string
            {
                return 'parent_id';
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('integer primary key');

        $this->assertIsChildOf($child, $stringKeyParent);
    }

    public function test_assert_aggregate_matches_fresh_handles_non_numeric_values(): void
    {
        // Both stored and fresh end up null when an exclusive MIN
        // hits a leaf. Tolerant numeric comparison short-circuits;
        // the helper falls through to a strict assertSame so the
        // null-vs-null case still passes cleanly.
        $this->seedMotivatingTree();
        // A1 is a leaf — its inclusive aggregates are based on its own row,
        // but we'll write a deliberately non-numeric value (NULL) into
        // tickets_min and re-verify against fresh.
        DB::table('areas')->where('name', 'A1')->update(['tickets_min' => null]);
        $a1 = Area::query()->where('name', 'A1')->firstOrFail();

        // Fresh for tickets_min on A1 (inclusive, leaf with tickets=50) is 50.
        // Stored is now null. The non-numeric branch fires; assertSame
        // detects the mismatch.
        $error = $this->expectFailure(fn () => $this->assertAggregateMatchesFresh($a1, 'tickets_min'));
        $this->assertStringContainsString('tickets_min', $error->getMessage());
    }
}
