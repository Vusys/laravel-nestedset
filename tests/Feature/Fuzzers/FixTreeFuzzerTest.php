<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Fuzzers;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Support\FuzzerConfig;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Random tree corruption + fixTree recovery.
 *
 * `CorruptionRecoveryTest` covers each corruption category with one
 * hand-written scenario. This file randomises *combinations*: pick a
 * deterministic tree, inject 1-5 random corruptions (invalid_bounds,
 * duplicate_lft, duplicate_rgt — orphans are excluded because
 * fixTree explicitly cannot recover them), then run fixTree and
 * assert the tree is fully restored.
 *
 * The strong post-fix invariant:
 *   1. `countErrors()` reports zero across every category.
 *   2. `isBroken() === false`.
 *   3. The set of `{lft, rgt}` values for live rows is the
 *      contiguous permutation `1..2N` (fixTree compacts).
 *   4. `parent_id` agrees with the bounds-derived parent for every
 *      live row.
 */
#[Group('fuzzer')]
final class FixTreeFuzzerTest extends TestCase
{
    /** Every test in this class deliberately corrupts the tree. */
    protected bool $allowBrokenTreeAtTearDown = true;

    /**
     * @return iterable<string, array{seed: int, treeSize: int, corruptionCount: int}>
     */
    public static function seedProvider(): iterable
    {
        // FUZZER_RUNS doubles as the corruption count here.
        $seeds = FuzzerConfig::seeds([1, 42, 1337, 9999, 314159]);
        $treeSize = FuzzerConfig::steps(15);
        $corruptionCount = FuzzerConfig::runs(3);

        foreach ($seeds as $seed) {
            yield "seed {$seed}, {$treeSize} nodes, {$corruptionCount} corruptions" => [
                'seed' => $seed, 'treeSize' => $treeSize, 'corruptionCount' => $corruptionCount,
            ];
        }
    }

    #[DataProvider('seedProvider')]
    public function test_fix_tree_recovers_random_corruption(
        int $seed,
        int $treeSize,
        int $corruptionCount,
    ): void {
        mt_srand($seed);

        // Plant a deterministic random tree (valid before corruption).
        $this->plantRandomTree($treeSize);
        $this->assertFalse(
            Category::isBroken(),
            "[seed={$seed}] precondition: tree must start clean",
        );

        // Hard-delete any soft-deleted residue from the plant phase —
        // fixTree's compaction works on live rows; soft-deleted slots
        // would otherwise stick around with stale bounds.
        DB::table('categories')->whereNotNull('deleted_at')->delete();

        // Snapshot the live row count before corruption (some corruptions
        // can't increase or decrease row count).
        $liveBefore = (int) DB::table('categories')->whereNull('deleted_at')->count();

        // Inject the requested number of random corruptions.
        $injected = [];
        for ($i = 0; $i < $corruptionCount; $i++) {
            $kind = ['invalid_bounds', 'duplicate_lft', 'duplicate_rgt'][mt_rand(0, 2)];
            $injected[] = $this->injectCorruption($kind);
        }

        // At least one corruption should be visible to countErrors.
        $errorsAfterCorruption = Category::countErrors();
        $totalErrors = array_sum($errorsAfterCorruption);
        $this->assertGreaterThan(
            0,
            $totalErrors,
            "[seed={$seed}] injected ".implode(',', $injected).' but countErrors reports zero',
        );

        // Run fixTree — it has full discretion on how to lay out the
        // tree; we just check the result is a valid one.
        Category::fixTree();

        $this->assertPostFixInvariants($seed, $liveBefore);
    }

    // ================================================================
    // Plant a random valid tree via the package's mutation API
    // ================================================================

    private function plantRandomTree(int $size): void
    {
        (new Category(['name' => 'root']))->saveAsRoot();
        for ($i = 1; $i < $size; $i++) {
            $all = Category::query()->orderBy('lft')->get()->all();
            $parent = $all[mt_rand(0, count($all) - 1)];
            $method = ['appendToNode', 'prependToNode'][mt_rand(0, 1)];
            $node = new Category(['name' => "n{$i}"]);
            $node->{$method}($parent->refresh())->save();
        }
    }

    // ================================================================
    // Inject one corruption — bypasses the package via raw DB write
    // ================================================================

    private function injectCorruption(string $kind): string
    {
        $rows = DB::table('categories')->whereNull('deleted_at')->get(['id', 'lft', 'rgt'])->all();
        if ($rows === []) {
            return $kind.' (no rows)';
        }
        $victim = $rows[mt_rand(0, count($rows) - 1)];

        switch ($kind) {
            case 'invalid_bounds':
                // Swap lft/rgt — guarantees lft >= rgt.
                DB::table('categories')->where('id', $victim->id)->update([
                    'lft' => $victim->rgt,
                    'rgt' => $victim->lft,
                ]);

                return "invalid_bounds:#{$victim->id}";

            case 'duplicate_lft':
                // Copy some other row's lft to this row.
                $others = array_values(array_filter($rows, fn (object $r): bool => $r->id !== $victim->id));
                if ($others === []) {
                    return $kind.' (no other row)';
                }
                $other = $others[mt_rand(0, count($others) - 1)];
                DB::table('categories')->where('id', $victim->id)->update(['lft' => $other->lft]);

                return "duplicate_lft:#{$victim->id}";

            case 'duplicate_rgt':
                $others = array_values(array_filter($rows, fn (object $r): bool => $r->id !== $victim->id));
                if ($others === []) {
                    return $kind.' (no other row)';
                }
                $other = $others[mt_rand(0, count($others) - 1)];
                DB::table('categories')->where('id', $victim->id)->update(['rgt' => $other->rgt]);

                return "duplicate_rgt:#{$victim->id}";
        }

        return 'unknown';
    }

    // ================================================================
    // Post-fix strong invariants
    // ================================================================

    private function assertPostFixInvariants(int $seed, int $expectedRowCount): void
    {
        // 1. Package's own check.
        $errors = Category::countErrors();
        foreach ($errors as $kind => $count) {
            $this->assertSame(
                0,
                $count,
                "[seed={$seed}] post-fix countErrors['{$kind}']={$count}",
            );
        }
        $this->assertFalse(Category::isBroken(), "[seed={$seed}] post-fix tree is still broken");

        // 2. Row count is preserved (fixTree never deletes rows).
        $liveAfter = (int) DB::table('categories')->whereNull('deleted_at')->count();
        $this->assertSame(
            $expectedRowCount,
            $liveAfter,
            "[seed={$seed}] post-fix row count changed",
        );

        // 3. {lft, rgt} for live rows is a contiguous 1..2N permutation
        //    *per root*. fixTree compacts each root's range.
        /** @var list<object{id: int, lft: int, rgt: int, depth: int, parent_id: int|null}> $rows */
        $rows = DB::table('categories')
            ->whereNull('deleted_at')
            ->get(['id', 'lft', 'rgt', 'depth', 'parent_id'])
            ->all();
        $rootRanges = [];
        foreach ($rows as $r) {
            if ($r->parent_id === null) {
                $rootRanges[$r->id] = [(int) $r->lft, (int) $r->rgt];
            }
        }
        foreach ($rootRanges as $rootId => [$rootLft, $rootRgt]) {
            $bounds = [];
            foreach ($rows as $r) {
                if ((int) $r->lft >= $rootLft && (int) $r->rgt <= $rootRgt) {
                    $bounds[] = (int) $r->lft;
                    $bounds[] = (int) $r->rgt;
                }
            }
            sort($bounds);
            $expected = range($rootLft, $rootRgt);
            $this->assertSame(
                $expected,
                $bounds,
                "[seed={$seed}] post-fix root #{$rootId}: bounds aren't a perm of {$rootLft}..{$rootRgt}",
            );
        }

        // 4. parent_id matches bounds-derived tightest-containing ancestor.
        foreach ($rows as $r) {
            $bestParentId = null;
            $bestParentLft = -1;
            foreach ($rows as $other) {
                if ($other->id === $r->id) {
                    continue;
                }
                if ((int) $other->lft < (int) $r->lft && (int) $other->rgt > (int) $r->rgt && (int) $other->lft > $bestParentLft) {
                    $bestParentLft = (int) $other->lft;
                    $bestParentId = (int) $other->id;
                }
            }
            $this->assertSame(
                $bestParentId,
                $r->parent_id === null ? null : (int) $r->parent_id,
                "[seed={$seed}] post-fix #{$r->id}: parent_id ({$r->parent_id}) doesn't match bounds-derived parent ({$bestParentId})",
            );
        }
    }
}
