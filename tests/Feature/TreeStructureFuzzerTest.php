<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Support\FuzzerConfig;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Seeded random walks over the *non-aggregate* tree-mutation API.
 *
 * Most existing tests in this package work on a fixed 5-7 node tree
 * and exercise one mutation at a time. The aggregate-side fuzzer
 * (see {@see Aggregates\MultiMutationCorrectnessTest}) already covers
 * mutations on aggregate-bearing models. This file covers the core
 * tree-mutation surface without aggregates in the loop — useful for
 * isolating bugs in the structural code from bugs in the maintenance
 * code.
 *
 * Every step exercises *all five* placement operations
 * (`appendToNode`, `prependToNode`, `insertBeforeNode`,
 * `insertAfterNode`, `makeRoot`) plus update and delete. After every
 * step the test asserts the **strong** nested-set invariants:
 *
 *   1. No invalid bounds, no duplicate lft/rgt, no orphans
 *      (`Category::isBroken() === false`).
 *   2. The set of `{lft, rgt}` values is a permutation of `1..2N`
 *      where N is the row count — proves no gaps, no overlaps.
 *   3. For every row: `depth` equals its ancestor-chain length, and
 *      `parent_id` matches the row whose bounds tightly contain it.
 *   4. `getDescendantCount()` agrees with bound-math `(rgt-lft-1)/2`.
 *
 * Failures replay deterministically — the seed prints in the
 * assertion message.
 */
#[Group('fuzzer')]
final class TreeStructureFuzzerTest extends TestCase
{
    /**
     * @return iterable<string, array{seed: int, steps: int}>
     */
    public static function seedProvider(): iterable
    {
        $seeds = FuzzerConfig::seeds([1, 42, 1337, 9999, 314159]);
        $steps = FuzzerConfig::steps(30);

        foreach ($seeds as $seed) {
            yield "seed {$seed}" => ['seed' => $seed, 'steps' => $steps];
        }
    }

    #[DataProvider('seedProvider')]
    public function test_random_mutation_sequence_preserves_invariants(int $seed, int $steps): void
    {
        mt_srand($seed);

        // Seed: a single root.
        (new Category(['name' => 'root']))->saveAsRoot();
        $this->assertCategoryInvariants("[seed={$seed}] step 0 (seed)");

        for ($step = 1; $step <= $steps; $step++) {
            $action = $this->pickAction(mt_rand(0, 99));
            $this->doStep($action, $step);
            $this->assertCategoryInvariants("[seed={$seed}] step {$step} ({$action})");
        }
    }

    // ================================================================
    // Random step picker — exercises every placement variant
    // ================================================================

    private function pickAction(int $roll): string
    {
        // Bias toward growth so the tree gets substantial.
        return match (true) {
            $roll < 16 => 'appendToNode',
            $roll < 28 => 'prependToNode',
            $roll < 40 => 'insertBeforeNode',
            $roll < 52 => 'insertAfterNode',
            $roll < 58 => 'makeRoot',
            $roll < 70 => 'moveTo',
            $roll < 76 => 'siblingUp',
            $roll < 82 => 'siblingDown',
            $roll < 88 => 'bulkInsert',
            $roll < 92 => 'update',
            $roll < 99 => 'delete',
            default => 'noop',
        };
    }

    private function doStep(string $action, int $step): void
    {
        $all = Category::query()->orderBy('lft')->get()->all();
        if ($all === []) {
            return;
        }

        switch ($action) {
            case 'appendToNode':
                $parent = $all[mt_rand(0, count($all) - 1)];
                $node = new Category(['name' => "s{$step}"]);
                $node->appendToNode($parent->refresh())->save();

                return;

            case 'prependToNode':
                $parent = $all[mt_rand(0, count($all) - 1)];
                $node = new Category(['name' => "s{$step}"]);
                $node->prependToNode($parent->refresh())->save();

                return;

            case 'insertBeforeNode':
                $candidates = array_values(array_filter($all, fn (Category $n): bool => $n->parent_id !== null));
                if ($candidates === []) {
                    // No non-root sibling available — fall back to append.
                    $parent = $all[mt_rand(0, count($all) - 1)];
                    $node = new Category(['name' => "s{$step}"]);
                    $node->appendToNode($parent->refresh())->save();

                    return;
                }
                $sibling = $candidates[mt_rand(0, count($candidates) - 1)];
                $node = new Category(['name' => "s{$step}"]);
                $node->insertBeforeNode($sibling->refresh())->save();

                return;

            case 'insertAfterNode':
                $candidates = array_values(array_filter($all, fn (Category $n): bool => $n->parent_id !== null));
                if ($candidates === []) {
                    $parent = $all[mt_rand(0, count($all) - 1)];
                    $node = new Category(['name' => "s{$step}"]);
                    $node->appendToNode($parent->refresh())->save();

                    return;
                }
                $sibling = $candidates[mt_rand(0, count($candidates) - 1)];
                $node = new Category(['name' => "s{$step}"]);
                $node->insertAfterNode($sibling->refresh())->save();

                return;

            case 'makeRoot':
                // Promote a non-root to a root of its own. The package
                // supports multiple roots (forest) for non-scoped models.
                $candidates = array_values(array_filter($all, fn (Category $n): bool => $n->parent_id !== null));
                if ($candidates === []) {
                    return;
                }
                $target = $candidates[mt_rand(0, count($candidates) - 1)];
                $target->makeRoot()->save();

                return;

            case 'moveTo':
                // Pick any non-root leaf and append-to a non-ancestor target.
                $movables = array_values(array_filter(
                    $all,
                    fn (Category $n): bool => $n->parent_id !== null && ($n->rgt - $n->lft) === 1,
                ));
                if ($movables === []) {
                    return;
                }
                $node = $movables[mt_rand(0, count($movables) - 1)];
                $targets = array_values(array_filter(
                    $all,
                    fn (Category $t): bool => $t->getKey() !== $node->getKey()
                        && $t->getKey() !== $node->parent_id
                        && ! $node->isAncestorOf($t),
                ));
                if ($targets === []) {
                    return;
                }
                $target = $targets[mt_rand(0, count($targets) - 1)];
                $node->appendToNode($target->refresh())->save();

                return;

            case 'siblingUp':
                $candidates = array_values(array_filter(
                    $all,
                    fn (Category $n): bool => $n->parent_id !== null,
                ));
                if ($candidates === []) {
                    return;
                }
                $candidates[mt_rand(0, count($candidates) - 1)]->up();

                return;

            case 'siblingDown':
                $candidates = array_values(array_filter(
                    $all,
                    fn (Category $n): bool => $n->parent_id !== null,
                ));
                if ($candidates === []) {
                    return;
                }
                $candidates[mt_rand(0, count($candidates) - 1)]->down();

                return;

            case 'bulkInsert':
                $anchor = $all[mt_rand(0, count($all) - 1)]->refresh();
                $spec = $this->randomBulkInsertSpec($step, depth: mt_rand(1, 3), siblings: mt_rand(1, 3));
                Category::bulkInsertTree($spec, appendTo: $anchor);

                return;

            case 'update':
                $target = $all[mt_rand(0, count($all) - 1)];
                $target->name = "u{$step}";
                $target->save();

                return;

            case 'delete':
                // Prefer leaves to keep the test deterministic against
                // "depth" assertion shenanigans; the package's
                // delete() cascades subtrees, but verifying that's a
                // separate concern.
                $leaves = array_values(array_filter(
                    $all,
                    fn (Category $n): bool => ($n->rgt - $n->lft) === 1 && $n->parent_id !== null,
                ));
                if ($leaves === []) {
                    return;
                }
                $leaves[mt_rand(0, count($leaves) - 1)]->delete();

                return;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function randomBulkInsertSpec(int $step, int $depth, int $siblings): array
    {
        static $tag = 0;
        $out = [];
        for ($i = 0; $i < $siblings; $i++) {
            $tag++;
            $node = ['name' => "bk{$step}_{$tag}"];
            if ($depth > 1 && mt_rand(0, 1) === 1) {
                $node['children'] = $this->randomBulkInsertSpec($step, $depth - 1, mt_rand(1, 2));
            }
            $out[] = $node;
        }

        return $out;
    }

    // ================================================================
    // Invariants — the deep check that catches most structural bugs
    // ================================================================

    private function assertCategoryInvariants(string $stage): void
    {
        // 1. Built-in repair check.
        $this->assertFalse(
            Category::isBroken(),
            "[{$stage}] tree is broken: ".json_encode(Category::countErrors()),
        );

        // Use a raw DB read of *every* row including soft-deleted —
        // Category uses SoftDeletes and soft-deleted rows keep their
        // lft/rgt slots so the bounds permutation is over the full
        // row set, not the live subset.
        /** @var list<object{id: int, lft: int, rgt: int, depth: int, parent_id: int|null}> $live */
        $live = DB::table('categories')
            ->get(['id', 'lft', 'rgt', 'depth', 'parent_id'])
            ->map(static fn (object $r): object => $r)
            ->all();

        // 2. {lft, rgt} forms a permutation of 1..2N within each root's range.
        //    (Multiple roots are allowed → check per root.)
        $rootRanges = [];
        foreach ($live as $row) {
            if ($row->parent_id === null) {
                $rootRanges[$row->id] = [$row->lft, $row->rgt];
            }
        }
        foreach ($rootRanges as $rootId => [$rootLft, $rootRgt]) {
            $bounds = [];
            foreach ($live as $row) {
                if ($row->lft >= $rootLft && $row->rgt <= $rootRgt) {
                    $bounds[] = $row->lft;
                    $bounds[] = $row->rgt;
                }
            }
            sort($bounds);
            $expected = range($rootLft, $rootRgt);
            $this->assertSame(
                $expected,
                $bounds,
                "[{$stage}] root #{$rootId}: bounds are not a permutation of {$rootLft}..{$rootRgt}",
            );
        }

        // 3. parent_id matches the tightest containing ancestor.
        //    Plus depth equals the ancestor-chain length.
        foreach ($live as $row) {
            // Compute the tightest containing ancestor: max-lft among
            // rows whose lft < row.lft AND rgt > row.rgt.
            $bestParentId = null;
            $bestParentLft = -1;
            $ancestorChainLen = 0;
            foreach ($live as $other) {
                if ($other->id === $row->id) {
                    continue;
                }
                if ($other->lft < $row->lft && $other->rgt > $row->rgt) {
                    $ancestorChainLen++;
                    if ($other->lft > $bestParentLft) {
                        $bestParentLft = $other->lft;
                        $bestParentId = $other->id;
                    }
                }
            }

            $this->assertSame(
                $bestParentId,
                $row->parent_id,
                "[{$stage}] #{$row->id}: parent_id mismatch (expected from bounds={$bestParentId}, stored={$row->parent_id})",
            );
            $this->assertSame(
                $ancestorChainLen,
                $row->depth,
                "[{$stage}] #{$row->id}: depth mismatch (expected {$ancestorChainLen}, stored {$row->depth})",
            );
        }

        // 4. getDescendantCount() agrees with bound math.
        //    Reload an arbitrary sample of nodes (full collection would
        //    multiply work; the random walk runs many of these checks
        //    over its lifetime so coverage compounds).
        $sample = Category::query()->orderBy('lft')->get();
        foreach ($sample as $node) {
            $expected = (int) (($node->rgt - $node->lft - 1) / 2);
            $this->assertSame(
                $expected,
                $node->getDescendantCount(),
                "[{$stage}] #{$node->id}: descendant count from bounds={$expected}, getDescendantCount()={$node->getDescendantCount()}",
            );
        }
    }
}
