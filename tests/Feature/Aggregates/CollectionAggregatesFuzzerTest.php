<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Query\Aggregates\Maintenance\AggregateValueComparator;
use Vusys\NestedSet\Tests\Fixtures\Models\TextJsonArea;
use Vusys\NestedSet\Tests\Support\FuzzerConfig;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Random sequences of append / mutate-source / move / delete against
 * the {@see TextJsonArea} fixture, which carries one of each new
 * aggregate kind:
 *
 *   - distinct_owners      DistinctCount
 *   - child_names          StringAgg (plain)
 *   - distinct_tags        StringAgg (distinct)
 *   - descendant_ids       JsonAgg (scalar)
 *   - descendant_summary   JsonAgg (multi-column object)
 *   - name_lookup          JsonObjectAgg
 *
 * Every step asserts:
 *   - the tree is structurally intact;
 *   - `aggregatesAreBroken()` is false (stored vs freshly-computed
 *     drift, normalised via {@see AggregateValueComparator::aggregateValuesEqual()}).
 */
#[Group('fuzzer')]
final class CollectionAggregatesFuzzerTest extends TestCase
{
    /**
     * @return iterable<string, array{seed: int, steps: int}>
     */
    public static function seedProvider(): iterable
    {
        $seeds = FuzzerConfig::seeds([1, 42, 1337]);
        $steps = FuzzerConfig::steps(25);

        foreach ($seeds as $seed) {
            yield "seed {$seed}, {$steps} steps" => ['seed' => $seed, 'steps' => $steps];
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    #[DataProvider('seedProvider')]
    public function test_random_walk_keeps_new_kind_aggregates_in_sync(int $seed, int $steps): void
    {
        mt_srand($seed);

        $root = new TextJsonArea($this->attrs('root'));
        $root->saveAsRoot();

        for ($i = 0; $i < 4; $i++) {
            $parent = $this->randomNode();
            if (! $parent instanceof TextJsonArea) {
                continue;
            }
            $node = new TextJsonArea($this->attrs("p{$i}"));
            $node->appendToNode($parent)->save();
        }

        $tag = "[CollectionAggregates seed={$seed}]";
        $this->assertInvariants("{$tag} seed");

        $history = [];
        for ($step = 1; $step <= $steps; $step++) {
            $action = $this->pickAction(mt_rand(0, 99));
            $this->step($action, $step);
            $history[] = "{$step}:{$action}";
            $this->assertInvariants("{$tag} step {$step} ({$action})\nHistory: ".implode(' ', $history));
        }
    }

    private function step(string $action, int $step): void
    {
        switch ($action) {
            case 'append':
                $parent = $this->randomNode();
                if (! $parent instanceof TextJsonArea) {
                    return;
                }
                $node = new TextJsonArea($this->attrs("s{$step}"));
                $node->appendToNode($parent)->save();

                return;

            case 'mutate':
                $target = $this->randomNode();
                if (! $target instanceof TextJsonArea) {
                    return;
                }
                $target->tag = $this->randomTag();
                $target->owner = $this->randomOwner();
                $target->save();

                return;

            case 'move':
                $node = $this->randomNonRootNode();
                $newParent = $this->randomNode();
                if (! $node instanceof TextJsonArea
                    || ! $newParent instanceof TextJsonArea
                    || $node->getKey() === $newParent->getKey()
                    || $node->isAncestorOf($newParent)) {
                    return;
                }
                $node->appendToNode($newParent->refresh())->save();

                return;

            case 'delete':
                $node = $this->randomNonRootLeaf();
                if (! $node instanceof TextJsonArea) {
                    return;
                }
                $node->delete();

                return;
        }
    }

    private function randomNode(): ?TextJsonArea
    {
        $rows = TextJsonArea::query()->get()->all();

        return $rows === [] ? null : $rows[mt_rand(0, count($rows) - 1)];
    }

    private function randomNonRootNode(): ?TextJsonArea
    {
        $rows = TextJsonArea::query()->whereNotNull('parent_id')->get()->all();

        return $rows === [] ? null : $rows[mt_rand(0, count($rows) - 1)];
    }

    private function randomNonRootLeaf(): ?TextJsonArea
    {
        $rows = TextJsonArea::query()
            ->whereNotNull('parent_id')
            ->whereRaw('rgt = lft + 1')
            ->get()->all();

        return $rows === [] ? null : $rows[mt_rand(0, count($rows) - 1)];
    }

    /** @return array<string, mixed> */
    private function attrs(string $name): array
    {
        return [
            'name' => $name.'_'.mt_rand(0, 9999),
            'owner' => $this->randomOwner(),
            'tag' => $this->randomTag(),
            'published' => true,
        ];
    }

    private function randomTag(): string
    {
        return ['red', 'blue', 'green', 'yellow', 'purple'][mt_rand(0, 4)];
    }

    private function randomOwner(): string
    {
        return ['Alice', 'Bob', 'Carol', 'Dave', 'Eve'][mt_rand(0, 4)];
    }

    private function pickAction(int $roll): string
    {
        return match (true) {
            $roll < 40 => 'append',
            $roll < 60 => 'mutate',
            $roll < 80 => 'move',
            default => 'delete',
        };
    }

    private function assertInvariants(string $stage): void
    {
        $this->assertFalse(TextJsonArea::isBroken(), "{$stage} tree broken");
        $this->assertFalse(
            TextJsonArea::aggregatesAreBroken(),
            "{$stage} aggregates broken: ".json_encode(TextJsonArea::aggregateErrors()),
        );
    }
}
