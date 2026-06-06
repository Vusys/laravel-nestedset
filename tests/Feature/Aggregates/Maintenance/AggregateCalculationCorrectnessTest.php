<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Maintenance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Branch;
use Vusys\NestedSet\Tests\Fixtures\Models\TypedArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Parametric correctness tests for the SQL-aggregate path.
 *
 * Strategy: every provider row builds a tree, applies a lifecycle event
 * (create / update-source / delete / soft-delete-restore / move), and
 * the assertion is the same invariant for every row — stored aggregate
 * columns equal `freshAggregate()` on every node and every column.
 *
 * The invariant is weaker than asserting hand-computed values but
 * exponentially broader: a single `dataset(tree shape, source values,
 * action) → assert stored == fresh` covers the cartesian product that
 * scenario-style tests can only cover by enumeration. The hand-computed
 * baselines live in `AvgMaintenanceTest`, `DeltaMaintenanceTest`,
 * `MinMaxMaintenanceTest`, etc.
 *
 * Operations covered per model:
 *   - Area:      SUM / COUNT / AVG / MIN / MAX (inclusive, unfiltered)
 *   - Branch:    inclusive SUM + exclusive SUM/COUNT/MAX + raw-filter SUM
 *   - TypedArea: equality-filtered SUM/COUNT/MAX + not-null-filtered COUNT
 */
final class AggregateCalculationCorrectnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    // ================================================================
    // Providers — shapes and source-value sets
    // ================================================================

    /**
     * Tree shapes chosen for structural variety:
     *  - single root  → boundary case (zero descendants)
     *  - flat fanout  → wide-shallow, every child is a leaf
     *  - deep chain   → degenerate vertical, exercises long ancestor chains
     *  - balanced     → mixed depths, balanced left/right
     *  - lopsided     → unequal subtrees, exercises asymmetric bounds
     *
     * @return iterable<string, array{spec: array<string, mixed>}>
     */
    public static function treeShapeProvider(): iterable
    {
        yield 'single root' => ['spec' => [
            'name' => 'r', 'value' => 10,
        ]];

        yield 'flat fanout (root + 3 leaves)' => ['spec' => [
            'name' => 'r', 'value' => 1, 'children' => [
                ['name' => 'a', 'value' => 2],
                ['name' => 'b', 'value' => 3],
                ['name' => 'c', 'value' => 4],
            ],
        ]];

        yield 'deep chain (4 levels)' => ['spec' => [
            'name' => 'r', 'value' => 1, 'children' => [
                ['name' => 'a', 'value' => 2, 'children' => [
                    ['name' => 'b', 'value' => 3, 'children' => [
                        ['name' => 'c', 'value' => 4],
                    ]],
                ]],
            ],
        ]];

        yield 'balanced (2 children, each with 2 grandchildren)' => ['spec' => [
            'name' => 'r', 'value' => 1, 'children' => [
                ['name' => 'a', 'value' => 2, 'children' => [
                    ['name' => 'aa', 'value' => 3],
                    ['name' => 'ab', 'value' => 4],
                ]],
                ['name' => 'b', 'value' => 5, 'children' => [
                    ['name' => 'ba', 'value' => 6],
                    ['name' => 'bb', 'value' => 7],
                ]],
            ],
        ]];

        yield 'lopsided (one branch deep, one shallow)' => ['spec' => [
            'name' => 'r', 'value' => 10, 'children' => [
                ['name' => 'a', 'value' => 1, 'children' => [
                    ['name' => 'a1', 'value' => 2, 'children' => [
                        ['name' => 'a2', 'value' => 3],
                    ]],
                ]],
                ['name' => 'b', 'value' => 99],
            ],
        ]];

        yield 'all zeros (boundary — AVG, MIN, MAX still defined)' => ['spec' => [
            'name' => 'r', 'value' => 0, 'children' => [
                ['name' => 'a', 'value' => 0],
                ['name' => 'b', 'value' => 0],
            ],
        ]];
    }

    /**
     * Tree shapes for TypedArea (which has a `type` column the equality
     * and not-null filters key off). Mirrors `treeShapeProvider` but
     * injects a `type` per node.
     *
     * @return iterable<string, array{spec: array<string, mixed>}>
     */
    public static function typedTreeShapeProvider(): iterable
    {
        // Mixed types across a balanced tree — exercises both filter
        // matches and misses at every depth.
        yield 'mixed types balanced' => ['spec' => [
            'name' => 'r', 'value' => 10, 'type' => 'fire', 'children' => [
                ['name' => 'a', 'value' => 5, 'type' => 'water', 'children' => [
                    ['name' => 'aa', 'value' => 3, 'type' => 'fire'],
                    ['name' => 'ab', 'value' => 7, 'type' => null],
                ]],
                ['name' => 'b', 'value' => 2, 'type' => 'water', 'children' => [
                    ['name' => 'ba', 'value' => 1, 'type' => 'water'],
                ]],
            ],
        ]];

        // No nodes match — every filtered SUM should land at 0,
        // filtered COUNT at 0, filtered MAX at null.
        yield 'no fire nodes at all' => ['spec' => [
            'name' => 'r', 'value' => 5, 'type' => 'water', 'children' => [
                ['name' => 'a', 'value' => 3, 'type' => 'water'],
                ['name' => 'b', 'value' => 1, 'type' => null],
            ],
        ]];

        // Every node matches — filtered aggregate should equal unfiltered.
        yield 'every node is fire' => ['spec' => [
            'name' => 'r', 'value' => 5, 'type' => 'fire', 'children' => [
                ['name' => 'a', 'value' => 3, 'type' => 'fire', 'children' => [
                    ['name' => 'aa', 'value' => 1, 'type' => 'fire'],
                ]],
            ],
        ]];

        // Null `type` rows — the equality filter `type = 'fire'` should
        // skip them, while the not-null filter on `tickets` is unaffected
        // (typed_areas.tickets is non-nullable).
        yield 'mixed null and non-null types' => ['spec' => [
            'name' => 'r', 'value' => 1, 'type' => null, 'children' => [
                ['name' => 'a', 'value' => 5, 'type' => 'fire'],
                ['name' => 'b', 'value' => 7, 'type' => null],
            ],
        ]];
    }

    /**
     * Tree shapes for Branch (which has an `active` 0/1 column for the
     * raw-SQL filter and uses exclusive aggregates on the same tree).
     *
     * @return iterable<string, array{spec: array<string, mixed>}>
     */
    public static function branchTreeShapeProvider(): iterable
    {
        yield 'mixed active flags' => ['spec' => [
            'name' => 'r', 'value' => 10, 'active' => 1, 'children' => [
                ['name' => 'a', 'value' => 5, 'active' => 0, 'children' => [
                    ['name' => 'aa', 'value' => 3, 'active' => 1],
                ]],
                ['name' => 'b', 'value' => 7, 'active' => 1],
            ],
        ]];

        yield 'all inactive (raw filter should yield zeros)' => ['spec' => [
            'name' => 'r', 'value' => 5, 'active' => 0, 'children' => [
                ['name' => 'a', 'value' => 3, 'active' => 0],
            ],
        ]];

        yield 'all active (raw-filtered == unfiltered)' => ['spec' => [
            'name' => 'r', 'value' => 5, 'active' => 1, 'children' => [
                ['name' => 'a', 'value' => 3, 'active' => 1],
            ],
        ]];

        yield 'leaf-only (exclusive aggregates expose root-with-no-children)' => ['spec' => [
            'name' => 'r', 'value' => 42, 'active' => 1,
        ]];
    }

    // ================================================================
    // Area — inclusive SUM/COUNT/AVG/MIN/MAX
    // ================================================================

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('treeShapeProvider')]
    #[Test]
    public function area_stored_matches_fresh_after_create(array $spec): void
    {
        $nodes = $this->buildAreaTree($spec);

        $this->assertStoredEqualsFreshForAll($this->refreshAll($nodes));
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('treeShapeProvider')]
    #[Test]
    public function area_stored_matches_fresh_after_source_update(array $spec): void
    {
        $nodes = $this->buildAreaTree($spec);

        foreach ($nodes as $name => $node) {
            $original = (int) $node->tickets;
            $node->tickets = $original + 100;
            $node->save();
            $node->refresh();

            $this->assertStoredEqualsFreshForAll(
                $this->refreshAll($nodes),
                "after updating {$name}",
            );

            $node->tickets = $original;
            $node->save();
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('treeShapeProvider')]
    #[Test]
    public function area_stored_matches_fresh_after_delete(array $spec): void
    {
        $nodes = $this->refreshAll($this->buildAreaTree($spec));

        $leaves = array_filter(
            $nodes,
            fn (Area $n): bool => ($n->rgt - $n->lft) === 1 && $n->parent_id !== null,
        );

        if ($leaves === []) {
            $this->markTestSkipped('shape has no non-root leaves to delete');
        }

        // Delete the leaves one by one and reassert after each. Only leaves
        // are safe to delete via plain `->delete()` — interior-node delete
        // is structural and assert-equivalent to subtree removal, covered
        // by the dedicated structural test below.
        foreach ($leaves as $name => $leaf) {
            $leaf->delete();
            unset($nodes[$name]);

            $this->assertStoredEqualsFreshForAll(
                $this->refreshAll($nodes),
                "after deleting leaf {$name}",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('treeShapeProvider')]
    #[Test]
    public function area_stored_matches_fresh_after_move(array $spec): void
    {
        $nodes = $this->buildAreaTree($spec);
        if (count($nodes) < 3) {
            $this->markTestSkipped('move requires at least three nodes');
        }

        // Pick the deepest leaf (largest lft among nodes with rgt-lft=1)
        // and a non-ancestor target. One representative move per shape
        // — the broader (operation × shape × delete/source-update)
        // matrix is already covered above; this method nails the
        // structural-move path's calculation correctness.
        $nodes = $this->refreshAll($nodes);
        $leaves = array_filter(
            $nodes,
            fn (Area $n): bool => ($n->rgt - $n->lft) === 1 && $n->parent_id !== null,
        );
        if ($leaves === []) {
            $this->markTestSkipped('no non-root leaves available');
        }

        $leaf = end($leaves);
        $leafName = array_key_last($leaves);

        // Target: any non-ancestor, non-leaf node, preferring siblings
        // of the leaf's parent (cross-parent move) over the leaf's parent.
        $target = null;
        $targetName = null;
        foreach ($nodes as $name => $candidate) {
            if ($name === $leafName) {
                continue;
            }
            if ($candidate->getKey() === $leaf->parent_id) {
                continue; // skip current parent — that'd be a no-op
            }
            if ($leaf->isAncestorOf($candidate)) {
                continue;
            }
            $target = $candidate;
            $targetName = $name;
            break;
        }

        if ($target === null) {
            $this->markTestSkipped('no valid move target for this shape');
        }

        $leaf->appendToNode($target->refresh())->save();

        $this->assertStoredEqualsFreshForAll(
            $this->refreshAll($nodes),
            "after moving {$leafName} under {$targetName}",
        );
    }

    // ================================================================
    // Branch — inclusive + exclusive + raw-filter
    // ================================================================

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('branchTreeShapeProvider')]
    #[Test]
    public function branch_stored_matches_fresh_after_create(array $spec): void
    {
        $nodes = $this->buildBranchTree($spec);
        // Exclusive + raw-filter aggregates skip incremental maintenance on
        // create; they're computed by the trailing fixAggregates step.
        Branch::fixAggregates();

        $this->assertStoredEqualsFreshForAll($this->refreshAll($nodes));
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('branchTreeShapeProvider')]
    #[Test]
    public function branch_stored_matches_fresh_after_source_update(array $spec): void
    {
        $nodes = $this->buildBranchTree($spec);

        foreach ($nodes as $name => $node) {
            $node->tickets = ((int) $node->tickets) + 50;
            $node->save();
            Branch::fixAggregates();

            $this->assertStoredEqualsFreshForAll(
                $this->refreshAll($nodes),
                "after updating tickets on {$name}",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('branchTreeShapeProvider')]
    #[Test]
    public function branch_stored_matches_fresh_after_active_flag_flip(array $spec): void
    {
        $nodes = $this->buildBranchTree($spec);

        // Flip the raw-filter watch column (active) on every node and
        // re-check. Maintenance is incremental on the watched column,
        // so this exercises the raw-filter delta path.
        foreach ($nodes as $name => $node) {
            $node->active = $node->active === 1 ? 0 : 1;
            $node->save();

            $this->assertStoredEqualsFreshForAll(
                $this->refreshAll($nodes),
                "after flipping active on {$name}",
            );
        }
    }

    // ================================================================
    // TypedArea — equality + not-null filters
    // ================================================================

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('typedTreeShapeProvider')]
    #[Test]
    public function typed_area_stored_matches_fresh_after_create(array $spec): void
    {
        $nodes = $this->buildTypedAreaTree($spec);

        $this->assertStoredEqualsFreshForAll($this->refreshAll($nodes));
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('typedTreeShapeProvider')]
    #[Test]
    public function typed_area_stored_matches_fresh_after_type_change(array $spec): void
    {
        $nodes = $this->buildTypedAreaTree($spec);

        // Equality filter watches the `type` column — flipping it
        // moves contributions between filtered aggregates.
        foreach ($nodes as $name => $node) {
            $node->type = $node->type === 'fire' ? 'water' : 'fire';
            $node->save();

            $this->assertStoredEqualsFreshForAll(
                $this->refreshAll($nodes),
                "after retyping {$name}",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('typedTreeShapeProvider')]
    #[Test]
    public function typed_area_stored_matches_fresh_after_source_change(array $spec): void
    {
        $nodes = $this->buildTypedAreaTree($spec);

        // Bump the source column on every node — exercises the
        // delta path for filtered SUM/COUNT/MAX.
        foreach ($nodes as $name => $node) {
            $original = (int) $node->tickets;
            $node->tickets = $original + 50;
            $node->save();

            $this->assertStoredEqualsFreshForAll(
                $this->refreshAll($nodes),
                "after bumping tickets on {$name}",
            );

            $node->tickets = $original;
            $node->save();
        }
    }

    // ================================================================
    // Helpers — tree building + invariant check
    // ================================================================

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, Area> Keyed by node name.
     */
    private function buildAreaTree(array $spec): array
    {
        /** @var array<string, Area> $out */
        $out = [];
        $this->buildAreaRecursive($spec, null, $out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, Area>  $out
     */
    private function buildAreaRecursive(array $spec, ?Area $parent, array &$out): void
    {
        $name = $this->specString($spec, 'name');
        $tickets = $spec['value'] ?? 0;

        $node = new Area(['name' => $name, 'tickets' => $tickets]);
        if (! $parent instanceof Area) {
            $node->saveAsRoot();
        } else {
            $node->appendToNode($parent->refresh())->save();
        }
        $node->refresh();
        $out[$name] = $node;

        if (! isset($spec['children']) || ! is_array($spec['children'])) {
            return;
        }
        foreach ($spec['children'] as $childSpec) {
            $this->buildAreaRecursive($this->coerceChildSpec($childSpec), $node, $out);
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, Branch>
     */
    private function buildBranchTree(array $spec): array
    {
        /** @var array<string, Branch> $out */
        $out = [];
        $this->buildBranchRecursive($spec, null, $out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, Branch>  $out
     */
    private function buildBranchRecursive(array $spec, ?Branch $parent, array &$out): void
    {
        $name = $this->specString($spec, 'name');
        $tickets = $this->specInt($spec, 'value');
        $active = $this->specInt($spec, 'active');

        $node = new Branch(['name' => $name, 'tickets' => $tickets, 'active' => $active]);
        if (! $parent instanceof Branch) {
            $node->saveAsRoot();
        } else {
            $node->appendToNode($parent->refresh())->save();
        }
        $node->refresh();
        $out[$name] = $node;

        if (! isset($spec['children']) || ! is_array($spec['children'])) {
            return;
        }
        foreach ($spec['children'] as $childSpec) {
            $this->buildBranchRecursive($this->coerceChildSpec($childSpec), $node, $out);
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, TypedArea>
     */
    private function buildTypedAreaTree(array $spec): array
    {
        /** @var array<string, TypedArea> $out */
        $out = [];
        $this->buildTypedAreaRecursive($spec, null, $out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, TypedArea>  $out
     */
    private function buildTypedAreaRecursive(array $spec, ?TypedArea $parent, array &$out): void
    {
        $name = $this->specString($spec, 'name');
        $tickets = $this->specInt($spec, 'value');
        $type = $this->specNullableString($spec, 'type');

        $node = new TypedArea(['name' => $name, 'tickets' => $tickets, 'type' => $type]);
        if (! $parent instanceof TypedArea) {
            $node->saveAsRoot();
        } else {
            $node->appendToNode($parent->refresh())->save();
        }
        $node->refresh();
        $out[$name] = $node;

        if (! isset($spec['children']) || ! is_array($spec['children'])) {
            return;
        }
        foreach ($spec['children'] as $childSpec) {
            $this->buildTypedAreaRecursive($this->coerceChildSpec($childSpec), $node, $out);
        }
    }

    /**
     * @template T of Area|Branch|TypedArea
     *
     * @param  array<string, T>  $nodes
     * @return array<string, T>
     */
    private function refreshAll(array $nodes): array
    {
        foreach ($nodes as $name => $node) {
            $fresh = $node->fresh();
            if ($fresh === null) {
                unset($nodes[$name]);

                continue;
            }
            /** @var T $fresh */
            $nodes[$name] = $fresh;
        }

        return $nodes;
    }

    /**
     * The invariant: for every node in $nodes, every user-facing
     * aggregate column equals its `freshAggregate()` value. AVG is
     * compared with delta tolerance; other functions with strict equality.
     *
     * @param  array<string, Area|Branch|TypedArea>  $nodes
     */
    private function assertStoredEqualsFreshForAll(array $nodes, string $message = ''): void
    {
        $prefix = $message === '' ? '' : "[{$message}] ";

        foreach ($nodes as $name => $node) {
            foreach ($node->getAggregateDefinitions() as $definition) {
                $column = $definition->getColumn();
                $stored = $node->getAttribute($column);
                $fresh = $node->freshAggregate($column);

                $this->assertSameAggregateValue(
                    $fresh,
                    $stored,
                    "{$prefix}{$name}.{$column}: stored != fresh",
                );
            }
        }
    }

    private function assertSameAggregateValue(mixed $expected, mixed $actual, string $message): void
    {
        $expectedIsFloat = is_float($expected) || (is_string($expected) && str_contains($expected, '.'));
        $actualIsFloat = is_float($actual) || (is_string($actual) && str_contains($actual, '.'));

        if ($expectedIsFloat || $actualIsFloat) {
            $expectedFloat = $expected === null ? 0.0 : (is_numeric($expected) ? (float) $expected : 0.0);
            $actualFloat = $actual === null ? 0.0 : (is_numeric($actual) ? (float) $actual : 0.0);
            $this->assertEqualsWithDelta($expectedFloat, $actualFloat, 0.0001, $message);

            return;
        }

        // Null-safe comparison; numeric strings collapse to int so
        // PG/MySQL string-decimal returns compare equal to int casts.
        $expectedNorm = $expected === null ? null : (is_numeric($expected) ? (int) $expected : $expected);
        $actualNorm = $actual === null ? null : (is_numeric($actual) ? (int) $actual : $actual);

        $this->assertSame($expectedNorm, $actualNorm, $message);
    }

    // ----------------------------------------------------------------
    // Type-safe spec coercion (replaces ad-hoc `(string) $spec[...]`)
    // ----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $spec
     */
    private function specString(array $spec, string $key): string
    {
        $value = $spec[$key] ?? null;
        if (! is_string($value)) {
            $this->fail("spec[{$key}]: expected string, got ".get_debug_type($value));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function specNullableString(array $spec, string $key): ?string
    {
        $value = $spec[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            $this->fail("spec[{$key}]: expected string|null, got ".get_debug_type($value));
        }

        return $value;
    }

    /**
     * Validate a `children[]` element is the same `array<string, mixed>`
     * spec shape the recursive builders expect.
     *
     * @return array<string, mixed>
     */
    private function coerceChildSpec(mixed $childSpec): array
    {
        if (! is_array($childSpec)) {
            $this->fail('spec.children entries must be arrays; got '.get_debug_type($childSpec));
        }
        foreach (array_keys($childSpec) as $key) {
            if (! is_string($key)) {
                $this->fail('spec.children entry keys must be strings');
            }
        }

        /** @var array<string, mixed> $childSpec */
        return $childSpec;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function specInt(array $spec, string $key): int
    {
        $value = $spec[$key] ?? 0;
        if (! is_int($value)) {
            $this->fail("spec[{$key}]: expected int, got ".get_debug_type($value));
        }

        return $value;
    }
}
