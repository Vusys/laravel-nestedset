<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Vusys\NestedSet\Tests\Fixtures\Models\SoftBranch;
use Vusys\NestedSet\Tests\Support\FuzzerConfig;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Sibling of {@see SoftDeleteCascadeFuzzerTest} for the SQL-aggregate
 * surface. SoftBranch carries inclusive SUM (delta path), exclusive
 * SUM / COUNT / MAX (RecomputeMaintenance), an inclusive SUM with a
 * raw-SQL filter (RecomputeMaintenance), and SoftDeletes.
 *
 * The fuzzer drives a random mix of structural mutations and soft-
 * delete lifecycle calls, asserting every step:
 *   - `aggregatesAreBroken()` stays false — stored values match
 *     freshly-computed ones under the snapshot-semantics live view.
 *   - The tree structure stays valid.
 */
#[Group('fuzzer')]
final class SoftBranchFuzzerTest extends TestCase
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
    public function test_random_sequences_keep_sql_aggregates_consistent(
        int $seed,
        int $steps,
    ): void {
        mt_srand($seed);

        $root = new SoftBranch([
            'name' => 'root',
            'tickets' => mt_rand(0, 5),
            'active' => mt_rand(0, 1),
        ]);
        $root->saveAsRoot();

        for ($i = 0; $i < 5; $i++) {
            $parent = $this->randomLiveNode();
            if ($parent === null) {
                continue;
            }
            $node = new SoftBranch([
                'name' => "p{$i}",
                'tickets' => mt_rand(0, 30),
                'active' => mt_rand(0, 1),
            ]);
            $node->appendToNode($parent)->save();
        }

        $this->assertInvariants("[seed={$seed}] seed");

        $history = [];
        for ($step = 1; $step <= $steps; $step++) {
            $action = $this->pickAction(mt_rand(0, 99));
            $this->doStep($action, $step);
            $history[] = "{$step}:{$action}";
            $this->assertInvariants(
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
                if ($parent === null) {
                    return;
                }
                $node = new SoftBranch([
                    'name' => "s{$step}",
                    'tickets' => mt_rand(0, 30),
                    'active' => mt_rand(0, 1),
                ]);
                $node->appendToNode($parent)->save();

                return;

            case 'soft_delete':
                $target = $this->randomLiveNonRootNode();
                if ($target === null) {
                    return;
                }
                $target->delete();

                return;

            case 'restore':
                $target = $this->randomTrashedNode();
                if ($target === null) {
                    return;
                }
                $target->restore();

                return;

            case 'mutate_source':
                $target = $this->randomLiveNode();
                if ($target === null) {
                    return;
                }
                $target->tickets = mt_rand(0, 30);
                $target->active = mt_rand(0, 1);
                $target->save();

                return;

            case 'move':
                $live = $this->liveAll();
                $movables = array_values(array_filter(
                    $live,
                    fn (SoftBranch $b): bool => $b->parent_id !== null && ($b->rgt - $b->lft) === 1,
                ));
                if ($movables === []) {
                    return;
                }
                $node = $movables[mt_rand(0, count($movables) - 1)];
                $targets = array_values(array_filter(
                    $live,
                    fn (SoftBranch $t): bool => $t->getKey() !== $node->getKey()
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
                if ($target === null) {
                    return;
                }
                if ($target->rgt - $target->lft !== 1) {
                    return;
                }
                $target->forceDelete();

                return;

            case 'force_delete_leaf':
                $live = $this->liveAll();
                $leaves = array_values(array_filter(
                    $live,
                    fn (SoftBranch $b): bool => $b->parent_id !== null && ($b->rgt - $b->lft) === 1,
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
                    fn (SoftBranch $b): bool => $b->parent_id !== null && ($b->rgt - $b->lft) > 1,
                ));
                if ($subroots === []) {
                    return;
                }
                $subroots[mt_rand(0, count($subroots) - 1)]->delete();

                return;
        }
    }

    /**
     * @return list<SoftBranch>
     */
    private function liveAll(): array
    {
        /** @var list<SoftBranch> $rows */
        $rows = SoftBranch::query()->orderBy('lft')->get()->all();

        return $rows;
    }

    private function randomLiveNode(): ?SoftBranch
    {
        $live = $this->liveAll();
        if ($live === []) {
            return null;
        }

        return $live[mt_rand(0, count($live) - 1)];
    }

    private function randomLiveNonRootNode(): ?SoftBranch
    {
        $live = array_values(array_filter(
            $this->liveAll(),
            fn (SoftBranch $b): bool => $b->parent_id !== null,
        ));

        if ($live === []) {
            return null;
        }

        return $live[mt_rand(0, count($live) - 1)];
    }

    private function randomTrashedNode(): ?SoftBranch
    {
        /** @var list<SoftBranch> $trashed */
        $trashed = SoftBranch::onlyTrashed()->get()->all();
        if ($trashed === []) {
            return null;
        }

        return $trashed[mt_rand(0, count($trashed) - 1)];
    }

    private function assertInvariants(string $stage): void
    {
        $this->assertFalse(
            SoftBranch::aggregatesAreBroken(),
            "{$stage} aggregates are broken: ".json_encode(SoftBranch::aggregateErrors()),
        );
        $this->assertFalse(SoftBranch::isBroken(), "{$stage} tree is broken");

        // Compare each declared user-facing aggregate column against
        // freshAggregate() on every live row. Catches per-row drift
        // that aggregatesAreBroken would miss if the comparator's
        // own logic regressed.
        $columns = ['tickets_total', 'descendants_total', 'descendants_count', 'descendants_max', 'active_tickets_total'];

        foreach ($this->liveAll() as $row) {
            foreach ($columns as $col) {
                $stored = $row->getAttribute($col);
                $fresh = $row->freshAggregate($col);
                $this->assertSame(
                    $this->normalise($fresh),
                    $this->normalise($stored),
                    "{$stage} #{$row->id} ({$row->name}): {$col} mismatch — stored=".json_encode($stored).' fresh='.json_encode($fresh),
                );
            }
        }

        DB::table('soft_branches'); // keep connection warm
    }

    private function normalise(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_numeric($value)) {
            return (string) (int) $value;
        }

        return (string) $value; /** @phpstan-ignore-line */
    }
}
