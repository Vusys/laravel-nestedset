<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\SoftDelete;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Query\Aggregates\Maintenance\AggregateValueComparator;
use Vusys\NestedSet\Tests\Fixtures\Models\Monster;
use Vusys\NestedSet\Tests\Support\FuzzerConfig;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Random sequences of mutate / soft-delete / restore / force-delete /
 * move / append on a Monster (SoftDeletes + listener aggregates).
 * After every step the stored aggregate columns are compared against
 * `freshAggregate()` to detect drift across the soft-delete state
 * machine.
 *
 * Snapshot semantics: per-mutation deltas skip trashed ancestors, so
 * their stored aggregates stay frozen at trash time. Restore re-syncs
 * the restored subtree from the now-live set before re-adding to live
 * ancestors. The fuzzer is what proves the snapshot stays consistent
 * under interleaved structural mutations.
 */
#[Group('fuzzer')]
final class SoftDeleteCascadeFuzzerTest extends TestCase
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

    #[DataProvider('seedProvider')]
    #[Test]
    public function random_soft_delete_sequences_keep_aggregates_consistent(
        int $seed,
        int $steps,
    ): void {
        mt_srand($seed);

        $root = new Monster([
            'name' => 'root',
            'type' => 'fire',
            'base_power' => mt_rand(1, 5),
            'level' => mt_rand(1, 3),
        ]);
        $root->saveAsRoot();

        for ($i = 0; $i < 5; $i++) {
            $parent = $this->randomLiveNode();
            if (! $parent instanceof Monster) {
                continue;
            }
            $node = new Monster([
                'name' => "p{$i}",
                'type' => mt_rand(0, 1) === 1 ? 'fire' : 'water',
                'base_power' => mt_rand(1, 9),
                'level' => mt_rand(1, 5),
            ]);
            $node->appendToNode($parent)->save();
        }

        $this->assertAggregatesAgreeWithFresh("[seed={$seed}] seed");

        $history = [];
        for ($step = 1; $step <= $steps; $step++) {
            $action = $this->pickAction(mt_rand(0, 99));
            $this->doStep($action, $step);
            $history[] = "{$step}:{$action}";
            $this->assertAggregatesAgreeWithFresh(
                "[seed={$seed}] step {$step} ({$action})\nHistory: ".implode(' ', $history),
            );
        }
    }

    private function pickAction(int $roll): string
    {
        if ($roll < 18) {
            return 'append';
        }
        if ($roll < 30) {
            return 'soft_delete';
        }
        if ($roll < 42) {
            return 'restore';
        }
        if ($roll < 54) {
            return 'mutate_source';
        }
        if ($roll < 66) {
            return 'move';
        }
        if ($roll < 76) {
            return 'force_delete_trashed';
        }
        if ($roll < 86) {
            return 'force_delete_leaf';
        }
        if ($roll < 95) {
            return 'cascade_delete_subtree';
        }

        return 'noop';
    }

    private function doStep(string $action, int $step): void
    {
        switch ($action) {
            case 'append':
                $parent = $this->randomLiveNode();
                if (! $parent instanceof Monster) {
                    return;
                }
                $node = new Monster([
                    'name' => "s{$step}",
                    'type' => mt_rand(0, 1) === 1 ? 'fire' : 'water',
                    'base_power' => mt_rand(1, 9),
                    'level' => mt_rand(1, 5),
                ]);
                $node->appendToNode($parent)->save();

                return;

            case 'soft_delete':
                $target = $this->randomLiveNonRootNode();
                if (! $target instanceof Monster) {
                    return;
                }
                $target->delete();

                return;

            case 'restore':
                $target = $this->randomTrashedNode();
                if (! $target instanceof Monster) {
                    return;
                }
                $target->restore();

                return;

            case 'mutate_source':
                $target = $this->randomLiveNode();
                if (! $target instanceof Monster) {
                    return;
                }
                $target->base_power = mt_rand(0, 12);
                $target->level = mt_rand(1, 5);
                $target->save();

                return;

            case 'move':
                $live = $this->liveAll();
                $movables = array_values(array_filter(
                    $live,
                    fn (Monster $m): bool => $m->parent_id !== null && ($m->rgt - $m->lft) === 1,
                ));
                if ($movables === []) {
                    return;
                }
                $node = $movables[mt_rand(0, count($movables) - 1)];
                $targets = array_values(array_filter(
                    $live,
                    fn (Monster $t): bool => $t->getKey() !== $node->getKey()
                        && $t->getKey() !== $node->parent_id
                        && ! $node->isAncestorOf($t),
                ));
                if ($targets === []) {
                    return;
                }
                $target = $targets[mt_rand(0, count($targets) - 1)];
                $node->appendToNode($target->refresh())->save();

                return;

            case 'force_delete_trashed':
                $target = $this->randomTrashedNode();
                if (! $target instanceof Monster) {
                    return;
                }
                // Stick to leaves so we don't intentionally corrupt the
                // tree (interior force-delete is documented as creating
                // orphans and lives in fixTree's recovery domain).
                if ($target->rgt - $target->lft !== 1) {
                    return;
                }
                $target->forceDelete();

                return;

            case 'force_delete_leaf':
                $live = $this->liveAll();
                $leaves = array_values(array_filter(
                    $live,
                    fn (Monster $m): bool => $m->parent_id !== null && ($m->rgt - $m->lft) === 1,
                ));
                if ($leaves === []) {
                    return;
                }
                $leaves[mt_rand(0, count($leaves) - 1)]->forceDelete();

                return;

            case 'cascade_delete_subtree':
                $live = $this->liveAll();
                $subroots = array_values(array_filter(
                    $live,
                    fn (Monster $m): bool => $m->parent_id !== null && ($m->rgt - $m->lft) > 1,
                ));
                if ($subroots === []) {
                    return;
                }
                $subroots[mt_rand(0, count($subroots) - 1)]->delete();

                return;
        }
    }

    /**
     * @return list<Monster>
     */
    private function liveAll(): array
    {
        /** @var list<Monster> $rows */
        $rows = Monster::query()->orderBy('lft')->get()->all();

        return $rows;
    }

    private function randomLiveNode(): ?Monster
    {
        $live = $this->liveAll();
        if ($live === []) {
            return null;
        }

        return $live[mt_rand(0, count($live) - 1)];
    }

    private function randomLiveNonRootNode(): ?Monster
    {
        $live = array_values(array_filter(
            $this->liveAll(),
            fn (Monster $m): bool => $m->parent_id !== null,
        ));

        if ($live === []) {
            return null;
        }

        return $live[mt_rand(0, count($live) - 1)];
    }

    private function randomTrashedNode(): ?Monster
    {
        /** @var list<Monster> $trashed */
        $trashed = Monster::onlyTrashed()->get()->all();
        if ($trashed === []) {
            return null;
        }

        return $trashed[mt_rand(0, count($trashed) - 1)];
    }

    private function assertAggregatesAgreeWithFresh(string $stage): void
    {
        // `weighted_avg__sum` is a dead schema column — the user's
        // `weighted_power` Sum satisfies the AVG companion slot, so
        // calling freshAggregate on it throws. Skip it in the check.
        $columns = [
            'weighted_power',
            'fire_count',
            'half_weighted_power',
            'weakest_level',
            'weighted_avg',
        ];

        foreach ($this->liveAll() as $m) {
            foreach ($columns as $col) {
                $stored = $m->getAttribute($col);
                $fresh = $m->freshAggregate($col);
                // Use the library's own tolerant numeric comparator: a
                // weighted_avg stored in a DECIMAL column is only as
                // precise as the column's scale, so a full-precision
                // fresh recompute differs in the trailing digits on
                // MySQL / PostgreSQL. Exact-string equality would
                // false-fail on those backends; the comparator absorbs
                // sub-tolerance storage noise exactly as drift detection
                // does in production.
                $this->assertTrue(
                    AggregateValueComparator::aggregatesEqual($stored, $fresh),
                    "{$stage} #{$m->id} ({$m->name}): {$col} mismatch — stored=".json_encode($stored).' fresh='.json_encode($fresh),
                );
            }
        }

        $this->assertFalse(
            Monster::aggregatesAreBroken(),
            "{$stage} aggregatesAreBroken() returned true",
        );

        $this->assertFalse(Monster::isBroken(), "{$stage} tree is broken");
    }
}
