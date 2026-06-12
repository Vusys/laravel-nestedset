<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Fuzzers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Tests\Fixtures\Models\KeyPathCategory;
use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\NestedSet\Tests\Support\FuzzerConfig;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Materialised paths are rebuilt on insert, move, rename, reorder, and
 * fixTree, yet no fuzzer asserted their coherence. This walks random
 * mutation sequences and, after every step, checks that the incrementally
 * maintained paths equal a full rebuild — `fixMaterialisedPaths()` returns
 * the count of rows whose stored path differs from the recomputed one, so
 * an all-zero result means maintenance kept every path coherent. Same
 * incremental-vs-batch oracle the aggregate fuzzer uses (stored vs fresh).
 *
 *   - SluggedCategory  slug-of-`name` path (`/a/b/`), so RENAME must
 *                      cascade the new segment to every descendant.
 *   - KeyPathCategory  id path (`1.2.3`), where MOVE must rewrite the
 *                      whole moved subtree's prefixes.
 */
#[Group('fuzzer')]
final class MaterialisedPathFuzzerTest extends TestCase
{
    /**
     * @return iterable<string, array{seed: int, steps: int}>
     */
    public static function seedProvider(): iterable
    {
        $seeds = FuzzerConfig::seeds([1, 42, 1337, 9999, 314159]);
        $steps = FuzzerConfig::steps(40);

        foreach ($seeds as $seed) {
            yield "seed {$seed}, {$steps} steps" => ['seed' => $seed, 'steps' => $steps];
        }
    }

    // ================================================================
    // SluggedCategory — slug path, with rename
    // ================================================================

    #[DataProvider('seedProvider')]
    #[Test]
    public function slugged_category_random_walk(int $seed, int $steps): void
    {
        mt_srand($seed);

        $root = new SluggedCategory(['name' => 'root']);
        $root->saveAsRoot();

        for ($i = 0; $i < 5; $i++) {
            $parent = $this->randomSlugged();
            if ($parent instanceof SluggedCategory) {
                (new SluggedCategory(['name' => "p{$i}"]))->appendToNode($parent)->save();
            }
        }

        $tag = "[Slugged seed={$seed}]";
        $this->assertSluggedPathsCoherent("{$tag} seed");

        $history = [];
        for ($step = 1; $step <= $steps; $step++) {
            $action = $this->pickAction(mt_rand(0, 99), rename: true);
            $this->sluggedStep($action, $step);
            $history[] = "{$step}:{$action}";
            $this->assertSluggedPathsCoherent("{$tag} step {$step} ({$action})\nHistory: ".implode(' ', $history));
        }
    }

    private function sluggedStep(string $action, int $step): void
    {
        switch ($action) {
            case 'append':
                $parent = $this->randomSlugged();
                if ($parent instanceof SluggedCategory) {
                    (new SluggedCategory(['name' => "s{$step}"]))->appendToNode($parent)->save();
                }

                return;

            case 'move':
                /** @var list<SluggedCategory> $live */
                $live = SluggedCategory::query()->orderBy('lft')->get()->all();
                $node = $this->randomMovableLeaf($live);
                $target = $this->randomMoveTarget($live, $node);
                if ($node instanceof SluggedCategory && $target instanceof SluggedCategory) {
                    $node->appendToNode($target->refresh())->save();
                }

                return;

            case 'rename':
                $node = $this->randomSlugged();
                if ($node instanceof SluggedCategory) {
                    $node->name = "r{$step}";
                    $node->save();
                }

                return;

            case 'reorder':
                $parent = $this->randomSlugged();
                if ($parent instanceof SluggedCategory) {
                    $parent->reorderChildrenBy('name');
                }

                return;
        }
    }

    private function randomSlugged(): ?SluggedCategory
    {
        /** @var list<SluggedCategory> $rows */
        $rows = SluggedCategory::query()->orderBy('lft')->get()->all();

        return $rows === [] ? null : $rows[mt_rand(0, count($rows) - 1)];
    }

    private function assertSluggedPathsCoherent(string $stage): void
    {
        $this->assertFalse(SluggedCategory::isBroken(), "{$stage} tree broken");

        $fixed = SluggedCategory::fixMaterialisedPaths();
        $this->assertSame(
            0,
            array_sum($fixed),
            "{$stage} materialised paths drifted from a full rebuild: ".json_encode($fixed),
        );
    }

    // ================================================================
    // KeyPathCategory — id path, move-heavy
    // ================================================================

    #[DataProvider('seedProvider')]
    #[Test]
    public function key_path_category_random_walk(int $seed, int $steps): void
    {
        mt_srand($seed);

        $root = new KeyPathCategory(['name' => 'root']);
        $root->saveAsRoot();

        for ($i = 0; $i < 5; $i++) {
            $parent = $this->randomKeyPath();
            if ($parent instanceof KeyPathCategory) {
                (new KeyPathCategory(['name' => "p{$i}"]))->appendToNode($parent)->save();
            }
        }

        $tag = "[KeyPath seed={$seed}]";
        $this->assertKeyPathCoherent("{$tag} seed");

        $history = [];
        for ($step = 1; $step <= $steps; $step++) {
            // No rename — id paths don't depend on the name segment.
            $action = $this->pickAction(mt_rand(0, 99), rename: false);
            $this->keyPathStep($action, $step);
            $history[] = "{$step}:{$action}";
            $this->assertKeyPathCoherent("{$tag} step {$step} ({$action})\nHistory: ".implode(' ', $history));
        }
    }

    private function keyPathStep(string $action, int $step): void
    {
        switch ($action) {
            case 'append':
                $parent = $this->randomKeyPath();
                if ($parent instanceof KeyPathCategory) {
                    (new KeyPathCategory(['name' => "s{$step}"]))->appendToNode($parent)->save();
                }

                return;

            case 'move':
                /** @var list<KeyPathCategory> $live */
                $live = KeyPathCategory::query()->orderBy('lft')->get()->all();
                $node = $this->randomMovableLeaf($live);
                $target = $this->randomMoveTarget($live, $node);
                if ($node instanceof KeyPathCategory && $target instanceof KeyPathCategory) {
                    $node->appendToNode($target->refresh())->save();
                }

                return;

            case 'reorder':
                $parent = $this->randomKeyPath();
                if ($parent instanceof KeyPathCategory) {
                    $parent->reorderChildrenBy('name');
                }

                return;
        }
    }

    private function randomKeyPath(): ?KeyPathCategory
    {
        /** @var list<KeyPathCategory> $rows */
        $rows = KeyPathCategory::query()->orderBy('lft')->get()->all();

        return $rows === [] ? null : $rows[mt_rand(0, count($rows) - 1)];
    }

    private function assertKeyPathCoherent(string $stage): void
    {
        $this->assertFalse(KeyPathCategory::isBroken(), "{$stage} tree broken");

        $fixed = KeyPathCategory::fixMaterialisedPaths();
        $this->assertSame(
            0,
            array_sum($fixed),
            "{$stage} materialised paths drifted from a full rebuild: ".json_encode($fixed),
        );
    }

    // ================================================================
    // Shared helpers
    // ================================================================

    private function pickAction(int $roll, bool $rename): string
    {
        if ($roll < 35) {
            return 'append';
        }
        if ($roll < 60) {
            return 'move';
        }
        if ($roll < 80) {
            return 'reorder';
        }

        return $rename ? 'rename' : 'append';
    }

    /**
     * A movable leaf (has a parent, no descendants) from $live, or null.
     *
     * @template T of \Vusys\NestedSet\Contracts\HasNestedSet
     *
     * @param  list<T>  $live
     * @return T|null
     */
    private function randomMovableLeaf(array $live): mixed
    {
        $leaves = array_values(array_filter(
            $live,
            static fn (mixed $m): bool => $m->getParentId() !== null && ($m->getRgt() - $m->getLft()) === 1,
        ));

        return $leaves === [] ? null : $leaves[mt_rand(0, count($leaves) - 1)];
    }

    /**
     * A legal move target for the leaf $node (not itself, not its current
     * parent), or null. $node is always a leaf (see {@see randomMovableLeaf})
     * so it has no descendants — no target can be inside its subtree, and
     * the descendant exclusion the general case would need isn't required.
     *
     * @template T of \Vusys\NestedSet\Contracts\HasNestedSet&\Illuminate\Database\Eloquent\Model
     *
     * @param  list<T>  $live
     * @param  T|null  $node
     * @return T|null
     */
    private function randomMoveTarget(array $live, mixed $node): mixed
    {
        if ($node === null) {
            return null;
        }

        $targets = array_values(array_filter(
            $live,
            static fn (mixed $t): bool => $t->getKey() !== $node->getKey()
                && $t->getKey() !== $node->getParentId(),
        ));

        return $targets === [] ? null : $targets[mt_rand(0, count($targets) - 1)];
    }
}
