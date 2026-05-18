<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use PHPUnit\Framework\Attributes\DataProvider;
use Vusys\NestedSet\Aggregates\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\Monster;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Parametric correctness tests for the listener-aggregate path.
 *
 * Monster declares five listener aggregates over different operations:
 *   - weighted_power      : Sum  via WeightedPowerListener (base_power × level)
 *   - fire_count          : Sum  via FireCountListener   (type='fire' ? 1 : 0)
 *   - half_weighted_power : Sum  via HalfWeightedPowerListener (float)
 *   - weakest_level       : Min  via WeakestLevelListener (level)
 *   - weighted_avg        : Avg  via WeightedPowerListener (auto-promoted Sum + Count companions)
 *
 * The same invariant as the SQL path: for every node in a built tree,
 * stored aggregate columns equal `freshAggregate()`. The provider
 * sweeps tree shapes and source-value patterns; the assertion is
 * uniform across all rows.
 *
 * Listener-specific edges covered by the providers:
 *   - `contribution()` returning null (non-fire base_power, see comments)
 *   - mixed null and non-null across types
 *   - fractional contributions (half_weighted_power)
 *   - boundary values (level=1 → weakest_level locked at 1 globally)
 */
final class ListenerCalculationCorrectnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    // ================================================================
    // Provider — tree shapes for Monster
    // ================================================================

    /**
     * @return iterable<string, array{spec: array<string, mixed>}>
     */
    public static function monsterTreeShapeProvider(): iterable
    {
        yield 'single root, fire type' => ['spec' => [
            'name' => 'r', 'type' => 'fire', 'base_power' => 2, 'level' => 3,
        ]];

        yield 'flat fanout — mixed types' => ['spec' => [
            'name' => 'r', 'type' => 'fire', 'base_power' => 2, 'level' => 5, 'children' => [
                ['name' => 'a', 'type' => 'water', 'base_power' => 4, 'level' => 2],
                ['name' => 'b', 'type' => 'fire', 'base_power' => 3, 'level' => 1],
                ['name' => 'c', 'type' => 'water', 'base_power' => 6, 'level' => 4],
            ],
        ]];

        yield 'deep chain — descending levels' => ['spec' => [
            'name' => 'r', 'type' => 'fire', 'base_power' => 10, 'level' => 5, 'children' => [
                ['name' => 'a', 'type' => 'fire', 'base_power' => 8, 'level' => 4, 'children' => [
                    ['name' => 'b', 'type' => 'water', 'base_power' => 6, 'level' => 3, 'children' => [
                        ['name' => 'c', 'type' => 'fire', 'base_power' => 4, 'level' => 2],
                    ]],
                ]],
            ],
        ]];

        yield 'balanced — varied attrs at every depth' => ['spec' => [
            'name' => 'r', 'type' => 'water', 'base_power' => 5, 'level' => 4, 'children' => [
                ['name' => 'a', 'type' => 'fire', 'base_power' => 3, 'level' => 3, 'children' => [
                    ['name' => 'aa', 'type' => 'water', 'base_power' => 7, 'level' => 2],
                    ['name' => 'ab', 'type' => 'fire', 'base_power' => 1, 'level' => 5],
                ]],
                ['name' => 'b', 'type' => 'fire', 'base_power' => 2, 'level' => 6, 'children' => [
                    ['name' => 'ba', 'type' => 'water', 'base_power' => 9, 'level' => 1],
                    ['name' => 'bb', 'type' => 'fire', 'base_power' => 4, 'level' => 7],
                ]],
            ],
        ]];

        // Odd `base_power × level` products keep half_weighted_power
        // fractional — would expose any int-cast truncation in the
        // delta or fresh paths.
        yield 'odd products (fractional half-weighted)' => ['spec' => [
            'name' => 'r', 'type' => 'fire', 'base_power' => 1, 'level' => 1, 'children' => [
                ['name' => 'a', 'type' => 'fire', 'base_power' => 3, 'level' => 5, 'children' => [
                    ['name' => 'b', 'type' => 'water', 'base_power' => 7, 'level' => 9],
                ]],
            ],
        ]];

        // base_power=0 → contributions are 0; level=1 globally → weakest=1.
        // Boundary case for Sum-of-zero vs null contribution semantics.
        yield 'all zeros, all level 1' => ['spec' => [
            'name' => 'r', 'type' => 'water', 'base_power' => 0, 'level' => 1, 'children' => [
                ['name' => 'a', 'type' => 'water', 'base_power' => 0, 'level' => 1],
                ['name' => 'b', 'type' => 'water', 'base_power' => 0, 'level' => 1],
            ],
        ]];

        // Type=null nodes — FireCountListener returns 0 (not null),
        // WeightedPower still computes from base_power*level.
        yield 'null types mixed in' => ['spec' => [
            'name' => 'r', 'type' => null, 'base_power' => 5, 'level' => 2, 'children' => [
                ['name' => 'a', 'type' => 'fire', 'base_power' => 3, 'level' => 4],
                ['name' => 'b', 'type' => null, 'base_power' => 7, 'level' => 1],
            ],
        ]];

        // Single deepest level=1 leaf — exercises Min-listener recompute
        // when the unique extremum holder is at the bottom of a chain.
        yield 'unique min-holder at chain tip' => ['spec' => [
            'name' => 'r', 'type' => 'fire', 'base_power' => 5, 'level' => 10, 'children' => [
                ['name' => 'a', 'type' => 'fire', 'base_power' => 5, 'level' => 9, 'children' => [
                    ['name' => 'b', 'type' => 'fire', 'base_power' => 5, 'level' => 8, 'children' => [
                        ['name' => 'c', 'type' => 'fire', 'base_power' => 5, 'level' => 1],
                    ]],
                ]],
            ],
        ]];
    }

    // ================================================================
    // Lifecycle events × every shape
    // ================================================================

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('monsterTreeShapeProvider')]
    public function test_monster_stored_matches_fresh_after_create(array $spec): void
    {
        $nodes = $this->buildMonsterTree($spec);

        $this->assertStoredEqualsFreshForAll($this->refreshAll($nodes));
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('monsterTreeShapeProvider')]
    public function test_monster_stored_matches_fresh_after_base_power_update(array $spec): void
    {
        $nodes = $this->buildMonsterTree($spec);

        foreach ($nodes as $name => $node) {
            $original = (int) $node->base_power;
            $node->base_power = $original + 10;
            $node->save();

            $this->assertStoredEqualsFreshForAll(
                $this->refreshAll($nodes),
                "after bumping base_power on {$name}",
            );

            $node->base_power = $original;
            $node->save();
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('monsterTreeShapeProvider')]
    public function test_monster_stored_matches_fresh_after_level_update(array $spec): void
    {
        $nodes = $this->buildMonsterTree($spec);

        foreach ($nodes as $name => $node) {
            $original = (int) $node->level;
            // Bump level — exercises the listener Min recompute path
            // (weakest_level depends on level via WeakestLevelListener).
            $node->level = $original + 5;
            $node->save();

            $this->assertStoredEqualsFreshForAll(
                $this->refreshAll($nodes),
                "after bumping level on {$name}",
            );

            $node->level = $original;
            $node->save();
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('monsterTreeShapeProvider')]
    public function test_monster_stored_matches_fresh_after_type_flip(array $spec): void
    {
        $nodes = $this->buildMonsterTree($spec);

        foreach ($nodes as $name => $node) {
            $original = $node->type;
            $node->type = $original === 'fire' ? 'water' : 'fire';
            $node->save();

            $this->assertStoredEqualsFreshForAll(
                $this->refreshAll($nodes),
                "after retyping {$name}",
            );

            $node->type = $original;
            $node->save();
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('monsterTreeShapeProvider')]
    public function test_monster_stored_matches_fresh_after_soft_delete(array $spec): void
    {
        $nodes = $this->refreshAll($this->buildMonsterTree($spec));

        $leaves = array_filter(
            $nodes,
            fn (Monster $n): bool => ($n->rgt - $n->lft) === 1 && $n->parent_id !== null,
        );

        if ($leaves === []) {
            $this->markTestSkipped('no non-root leaves to soft-delete');
        }

        foreach ($leaves as $name => $leaf) {
            $leaf->delete(); // soft delete (Monster uses SoftDeletes)
            unset($nodes[$name]);

            $this->assertStoredEqualsFreshForAll(
                $this->refreshAll($nodes),
                "after soft-deleting {$name}",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('monsterTreeShapeProvider')]
    public function test_monster_stored_matches_fresh_after_soft_delete_then_restore(array $spec): void
    {
        $nodes = $this->refreshAll($this->buildMonsterTree($spec));

        $leaves = array_filter(
            $nodes,
            fn (Monster $n): bool => ($n->rgt - $n->lft) === 1 && $n->parent_id !== null,
        );
        if ($leaves === []) {
            $this->markTestSkipped('no non-root leaves to soft-delete');
        }

        $first = array_key_first($leaves);
        $leaf = $leaves[$first];
        $leafId = (int) $leaf->id;

        $leaf->delete();
        $this->assertStoredEqualsFreshForAll(
            $this->refreshAll(array_diff_key($nodes, [$first => true])),
            "after soft-deleting {$first}",
        );

        // Reload from withTrashed and restore.
        $trashed = Monster::withTrashed()->findOrFail($leafId);
        $trashed->restore();
        $nodes[$first] = $trashed;

        $this->assertStoredEqualsFreshForAll(
            $this->refreshAll($nodes),
            "after restoring {$first}",
        );
    }

    // ================================================================
    // fixAggregates symmetry — listener path
    // ================================================================

    /**
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('monsterTreeShapeProvider')]
    public function test_fix_aggregates_is_a_noop_on_clean_tree(array $spec): void
    {
        $this->buildMonsterTree($spec);

        // After incremental maintenance, fixAggregates must report
        // zero drift for every user-facing listener column.
        $errors = Monster::aggregateErrors();
        foreach ($errors as $column => $count) {
            $this->assertSame(0, $count, "{$column} reports drift before fix");
        }

        $result = Monster::fixAggregates();
        $this->assertSame(0, $result->totalRowsUpdated, 'fix touched rows on a clean tree');
    }

    // ================================================================
    // Helpers
    // ================================================================

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, Monster>
     */
    private function buildMonsterTree(array $spec): array
    {
        /** @var array<string, Monster> $out */
        $out = [];
        $this->buildMonsterRecursive($spec, null, $out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, Monster>  $out
     */
    private function buildMonsterRecursive(array $spec, ?Monster $parent, array &$out): void
    {
        $name = $this->specString($spec, 'name');
        $type = $this->specNullableString($spec, 'type');
        $basePower = $this->specInt($spec, 'base_power');
        $level = $this->specInt($spec, 'level', default: 1);

        $node = new Monster([
            'name' => $name,
            'type' => $type,
            'base_power' => $basePower,
            'level' => $level,
        ]);
        if (!$parent instanceof \Vusys\NestedSet\Tests\Fixtures\Models\Monster) {
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
            $this->buildMonsterRecursive($this->coerceChildSpec($childSpec), $node, $out);
        }
    }

    /**
     * @param  array<string, Monster>  $nodes
     * @return array<string, Monster>
     */
    private function refreshAll(array $nodes): array
    {
        foreach ($nodes as $name => $node) {
            /** @var Monster|null $fresh */
            $fresh = $node->fresh();
            if ($fresh === null) {
                unset($nodes[$name]);

                continue;
            }
            $nodes[$name] = $fresh;
        }

        return $nodes;
    }

    /**
     * @param  array<string, Monster>  $nodes
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

        $expectedNorm = $expected === null ? null : (is_numeric($expected) ? (int) $expected : $expected);
        $actualNorm = $actual === null ? null : (is_numeric($actual) ? (int) $actual : $actual);
        $this->assertSame($expectedNorm, $actualNorm, $message);
    }

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
    private function specInt(array $spec, string $key, int $default = 0): int
    {
        $value = $spec[$key] ?? $default;
        if (! is_int($value)) {
            $this->fail("spec[{$key}]: expected int, got ".get_debug_type($value));
        }

        return $value;
    }
}
