<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Fuzzers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Support\FuzzerConfig;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Random permutations of sibling sets exercise the CASE-WHEN UPDATE
 * across many shapes the hand-written tests miss. Each step:
 *
 *   - picks a random parent in the tree,
 *   - shuffles its direct children into a random order,
 *   - asserts the tree is still a valid 1..2N nested set,
 *   - asserts the new lft order matches the requested order,
 *   - asserts every parent's child set is unchanged after the reorder
 *     (parent_id stays put).
 *
 * Two fixtures: Category (plain, no aggregates) catches structural
 * regressions; Area (SUM/COUNT/AVG/MIN/MAX) catches any accidental
 * aggregate drift — the reorder must leave stored aggregates intact
 * because it doesn't change ancestry.
 */
#[Group('fuzzer')]
final class ReorderFuzzerTest extends TestCase
{
    use InteractsWithTrees;

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

    #[DataProvider('seedProvider')]
    #[Test]
    public function category_random_reorders_preserve_invariants(int $seed, int $steps): void
    {
        mt_srand($seed);

        $this->seedCategoryTree();

        for ($step = 0; $step < $steps; $step++) {
            $parent = $this->randomCategoryParent();
            if (! $parent instanceof Category) {
                continue;
            }

            /** @var list<int> $childIds */
            $childIds = Category::query()
                ->where('parent_id', $parent->id)
                ->orderBy('lft')
                ->pluck('id')
                ->all();

            // Sanity: collect parent_id snapshot before reorder.
            $parentMapBefore = Category::query()
                ->orderBy('id')
                ->pluck('parent_id', 'id')
                ->all();

            $shuffled = $childIds;
            shuffle($shuffled);

            $parent->refresh()->reorderChildren($shuffled);

            $this->assertFalse(
                Category::isBroken(),
                "Tree broken at seed={$seed} step={$step} parent={$parent->id}.",
            );

            $newOrder = Category::query()
                ->where('parent_id', $parent->id)
                ->orderBy('lft')
                ->pluck('id')
                ->all();
            $this->assertSame(
                $shuffled,
                $newOrder,
                "Reorder result did not match requested order (seed={$seed} step={$step}).",
            );

            $parentMapAfter = Category::query()
                ->orderBy('id')
                ->pluck('parent_id', 'id')
                ->all();
            $this->assertSame(
                $parentMapBefore,
                $parentMapAfter,
                "parent_id of some row changed during a reorder (seed={$seed} step={$step}).",
            );
        }
    }

    #[DataProvider('seedProvider')]
    #[Test]
    public function area_random_reorders_preserve_aggregates(int $seed, int $steps): void
    {
        mt_srand($seed);

        $this->seedAreaTree();

        for ($step = 0; $step < $steps; $step++) {
            $parent = $this->randomAreaParent();
            if (! $parent instanceof Area) {
                continue;
            }

            /** @var list<int> $childIds */
            $childIds = Area::query()
                ->where('parent_id', $parent->id)
                ->orderBy('lft')
                ->pluck('id')
                ->all();

            $shuffled = $childIds;
            shuffle($shuffled);

            $parent->refresh()->reorderChildren($shuffled);

            $this->assertFalse(
                Area::isBroken(),
                "Area tree broken at seed={$seed} step={$step}.",
            );
            $this->assertAggregatesAreIntact(
                Area::class,
                message: "Aggregates drifted at seed={$seed} step={$step}.",
            );
        }
    }

    private function seedCategoryTree(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();

        $level1 = [];
        for ($i = 0; $i < 4; $i++) {
            $n = new Category(['name' => "n1-{$i}"]);
            $n->appendToNode($root->refresh())->save();
            $level1[] = $n;
        }
        foreach ($level1 as $parent) {
            $kidCount = mt_rand(2, 4);
            for ($i = 0; $i < $kidCount; $i++) {
                $n = new Category(['name' => "n2-{$parent->id}-{$i}"]);
                $n->appendToNode($parent->refresh())->save();
            }
        }
    }

    private function seedAreaTree(): void
    {
        $root = new Area(['name' => 'root', 'tickets' => mt_rand(0, 10)]);
        $root->saveAsRoot();

        $level1 = [];
        for ($i = 0; $i < 3; $i++) {
            $n = new Area(['name' => "n1-{$i}", 'tickets' => mt_rand(0, 10)]);
            $n->appendToNode($root->refresh())->save();
            $level1[] = $n;
        }
        foreach ($level1 as $parent) {
            for ($i = 0; $i < 3; $i++) {
                $n = new Area(['name' => "n2-{$parent->id}-{$i}", 'tickets' => mt_rand(0, 10)]);
                $n->appendToNode($parent->refresh())->save();
            }
        }
    }

    private function randomCategoryParent(): ?Category
    {
        /** @var list<int> $ids */
        $ids = Category::query()
            ->whereExists(static function ($q): void {
                $q->from('categories as c2')
                    ->whereColumn('c2.parent_id', 'categories.id');
            })
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return null;
        }

        return Category::query()->find($ids[mt_rand(0, count($ids) - 1)]);
    }

    private function randomAreaParent(): ?Area
    {
        /** @var list<int> $ids */
        $ids = Area::query()
            ->whereExists(static function ($q): void {
                $q->from('areas as c2')
                    ->whereColumn('c2.parent_id', 'areas.id');
            })
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return null;
        }

        return Area::query()->find($ids[mt_rand(0, count($ids) - 1)]);
    }
}
