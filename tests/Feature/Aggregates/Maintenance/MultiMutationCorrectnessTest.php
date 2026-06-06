<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Aggregates\Maintenance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Branch;
use Vusys\NestedSet\Tests\Fixtures\Models\Monster;
use Vusys\NestedSet\Tests\Fixtures\Models\TypedArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Multi-step mutation marathons.
 *
 * Most existing tests apply a single mutation and assert the
 * aftermath — fine for verifying a code path in isolation, weak at
 * catching state-dependent bugs that only surface after several
 * unrelated edits to the same tree.
 *
 * Every test here builds a non-trivial tree (10-20 nodes, mixed
 * depth), applies a scripted sequence of 10-30 mutations, and after
 * **every** step asserts the same invariants:
 *
 *   1. Tree structure: `isBroken() === false` (no invalid bounds,
 *      no duplicate lft/rgt, no orphans).
 *   2. Aggregate consistency: `aggregatesAreBroken() === false` AND
 *      `freshAggregate()` equals stored for every user-facing column
 *      on every node.
 *
 * The mutations are interleaved across kinds — create, update, delete,
 * soft-delete + restore, move — so that the same tree visits multiple
 * maintenance paths back-to-back. If the package leaves stale state
 * after any one mutation, the next mutation will pile on top of it
 * and the invariant check will catch it.
 *
 * Randomised walks at the bottom use seeded PRNG (`mt_srand($seed)`)
 * so a failure is replayable — the seed is printed in the assertion
 * message.
 */
final class MultiMutationCorrectnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AggregateRegistry::flush();
    }

    // ================================================================
    // Scripted scenarios — realistic, hand-curated multi-step flows
    // ================================================================

    #[Test]
    public function interleaved_inserts_updates_moves_and_deletes_keep_sum_count_avg_min_max_in_sync(): void
    {
        // Seed: 5-node tree.
        $root = new Area(['name' => 'Root', 'tickets' => 100]);
        $root->saveAsRoot();
        $a = new Area(['name' => 'A', 'tickets' => 50]);
        $a->appendToNode($root->refresh())->save();
        $b = new Area(['name' => 'B', 'tickets' => 25]);
        $b->appendToNode($root->refresh())->save();
        $a1 = new Area(['name' => 'A1', 'tickets' => 10]);
        $a1->appendToNode($a->refresh())->save();
        $a2 = new Area(['name' => 'A2', 'tickets' => 5]);
        $a2->appendToNode($a->refresh())->save();

        $this->assertAreaInvariants('seed');

        // 1. Add a grandchild under A1.
        $a1x = new Area(['name' => 'A1x', 'tickets' => 3]);
        $a1x->appendToNode($a1->refresh())->save();
        $this->assertAreaInvariants('1: append A1x under A1');

        // 2. Bump B's tickets — pushes MAX upward.
        $b->refresh();
        $b->tickets = 200;
        $b->save();
        $this->assertAreaInvariants('2: B.tickets 25→200 (new MAX)');

        // 3. Reduce A1's tickets — was the MIN holder via A1x (3); now A1 itself
        $a1->refresh();
        $a1->tickets = 1;
        $a1->save();
        $this->assertAreaInvariants('3: A1.tickets 10→1 (now MIN)');

        // 4. Add a deep grandchild.
        $a1xx = new Area(['name' => 'A1xx', 'tickets' => 7]);
        $a1xx->appendToNode($a1x->refresh())->save();
        $this->assertAreaInvariants('4: append A1xx under A1x');

        // 5. Move A2 under B (cross-parent move).
        $a2->refresh();
        $a2->appendToNode($b->refresh())->save();
        $this->assertAreaInvariants('5: move A2 under B');

        // 6. Bring A2's tickets back up — exercises MAX delta.
        $a2->refresh();
        $a2->tickets = 99;
        $a2->save();
        $this->assertAreaInvariants('6: A2.tickets 5→99');

        // 7. Add a third top-level sibling and three grandchildren.
        $c = new Area(['name' => 'C', 'tickets' => 8]);
        $c->appendToNode($root->refresh())->save();
        foreach (['c1' => 2, 'c2' => 4, 'c3' => 6] as $n => $t) {
            $node = new Area(['name' => $n, 'tickets' => $t]);
            $node->appendToNode($c->refresh())->save();
        }
        $this->assertAreaInvariants('7: append C + 3 grandchildren');

        // 8. Delete A1xx (a leaf in the deepest chain).
        Area::query()->where('name', 'A1xx')->firstOrFail()->delete();
        $this->assertAreaInvariants('8: delete A1xx');

        // 9. Re-parent C under A — moves a whole subtree (C + 3 children).
        $c->refresh();
        $c->appendToNode($a->refresh())->save();
        $this->assertAreaInvariants('9: move C subtree under A');

        // 10. Promote A1 to root — restructures most of the tree.
        $a1->refresh();
        $a1->makeRoot()->save();
        $this->assertAreaInvariants('10: promote A1 to root');

        // 11. Move A1 back under A.
        $a1->refresh();
        $a1->appendToNode($a->refresh())->save();
        $this->assertAreaInvariants('11: re-parent A1 back under A');

        // 12. Update root's tickets.
        $root->refresh();
        $root->tickets = 1000;
        $root->save();
        $this->assertAreaInvariants('12: Root.tickets 100→1000');

        // 13. Delete a leaf (c2).
        Area::query()->where('name', 'c2')->firstOrFail()->delete();
        $this->assertAreaInvariants('13: delete c2');

        // 14. Add a node, then immediately re-update it.
        $z = new Area(['name' => 'Z', 'tickets' => 1]);
        $z->appendToNode($a1->refresh())->save();
        $z->refresh();
        $z->tickets = 500;
        $z->save();
        $this->assertAreaInvariants('14: append Z then bump tickets');

        // 15. Delete an interior node (A2). The package's delete cascades
        // its subtree — if A2 had any descendants we'd be deleting them too.
        Area::query()->where('name', 'A2')->firstOrFail()->delete();
        $this->assertAreaInvariants('15: delete interior A2');
    }

    #[Test]
    public function raw_filter_aggregates_stay_consistent_across_inserts_source_updates_and_predicate_flips(): void
    {
        // Branch: inclusive SUM + exclusive SUM/COUNT/MAX + raw-filter SUM.
        // Builds and mutates a 7-node tree with mixed active flags.
        $root = new Branch(['name' => 'Root', 'tickets' => 10, 'active' => 1]);
        $root->saveAsRoot();
        $a = new Branch(['name' => 'A', 'tickets' => 20, 'active' => 0]);
        $a->appendToNode($root->refresh())->save();
        $b = new Branch(['name' => 'B', 'tickets' => 40, 'active' => 1]);
        $b->appendToNode($root->refresh())->save();
        $a1 = new Branch(['name' => 'A1', 'tickets' => 5, 'active' => 1]);
        $a1->appendToNode($a->refresh())->save();
        $a2 = new Branch(['name' => 'A2', 'tickets' => 15, 'active' => 0]);
        $a2->appendToNode($a->refresh())->save();

        // Exclusive + raw-filter columns don't fill in on plain save —
        // run an initial fix to bring them to truth.
        Branch::fixAggregates();
        $this->assertBranchInvariants('seed');

        // 1. Flip A1 active off → A's active_tickets_total drops.
        $a1->refresh();
        $a1->active = 0;
        $a1->save();
        $this->assertBranchInvariants('1: A1 active 1→0');

        // 2. Bump B's tickets — pushes MAX-via-exclusive.
        $b->refresh();
        $b->tickets = 100;
        $b->save();
        Branch::fixAggregates(); // exclusive columns refresh
        $this->assertBranchInvariants('2: B.tickets 40→100');

        // 3. Add a grandchild under B.
        $b1 = new Branch(['name' => 'B1', 'tickets' => 7, 'active' => 1]);
        $b1->appendToNode($b->refresh())->save();
        Branch::fixAggregates();
        $this->assertBranchInvariants('3: append B1');

        // 4. Move A1 under B (cross-parent).
        $a1->refresh();
        $a1->appendToNode($b->refresh())->save();
        Branch::fixAggregates();
        $this->assertBranchInvariants('4: move A1 under B');

        // 5. Flip every node's active.
        foreach (Branch::all() as $node) {
            $node->active = 1 - (int) $node->active;
            $node->save();
        }
        Branch::fixAggregates();
        $this->assertBranchInvariants('5: flip every active');

        // 6. Delete a leaf (A2).
        Branch::query()->where('name', 'A2')->firstOrFail()->delete();
        Branch::fixAggregates();
        $this->assertBranchInvariants('6: delete A2');

        // 7. Insert a new active node as a sibling of A.
        $c = new Branch(['name' => 'C', 'tickets' => 22, 'active' => 1]);
        $c->appendToNode($root->refresh())->save();
        Branch::fixAggregates();
        $this->assertBranchInvariants('7: append C as sibling of A');

        // 8. Make A a root (separating it from Root's subtree).
        $a->refresh();
        $a->makeRoot()->save();
        Branch::fixAggregates();
        $this->assertBranchInvariants('8: A becomes its own root');

        // 9. Re-parent A back under Root.
        $a->refresh();
        $a->appendToNode($root->refresh())->save();
        Branch::fixAggregates();
        $this->assertBranchInvariants('9: A back under Root');

        // 10. Delete interior A (cascades children).
        Branch::query()->where('name', 'A')->firstOrFail()->delete();
        Branch::fixAggregates();
        $this->assertBranchInvariants('10: delete interior A');
    }

    #[Test]
    public function listener_aggregates_stay_consistent_through_inserts_updates_soft_deletes_and_restores(): void
    {
        // Monster: Sum (weighted_power, fire_count), Min (weakest_level),
        // Avg (weighted_avg). Includes the listener-AVG promotion path.
        $root = new Monster(['name' => 'Root', 'type' => 'fire', 'base_power' => 5, 'level' => 4]);
        $root->saveAsRoot();
        $a = new Monster(['name' => 'A', 'type' => 'water', 'base_power' => 3, 'level' => 2]);
        $a->appendToNode($root->refresh())->save();
        $b = new Monster(['name' => 'B', 'type' => 'fire', 'base_power' => 6, 'level' => 3]);
        $b->appendToNode($root->refresh())->save();
        $a1 = new Monster(['name' => 'A1', 'type' => 'fire', 'base_power' => 2, 'level' => 1]);
        $a1->appendToNode($a->refresh())->save();
        $a2 = new Monster(['name' => 'A2', 'type' => 'water', 'base_power' => 8, 'level' => 5]);
        $a2->appendToNode($a->refresh())->save();

        $this->assertMonsterInvariants('seed');

        // 1. Soft-delete a leaf (A1 holds min level).
        $a1->refresh();
        $a1->delete();
        $this->assertMonsterInvariants('1: soft-delete A1 (min holder)');

        // 2. Bump A2's level.
        $a2->refresh();
        $a2->level = 7;
        $a2->save();
        $this->assertMonsterInvariants('2: A2.level 5→7');

        // 3. Add a grandchild under B.
        $b1 = new Monster(['name' => 'B1', 'type' => 'fire', 'base_power' => 4, 'level' => 2]);
        $b1->appendToNode($b->refresh())->save();
        $this->assertMonsterInvariants('3: append B1');

        // 4. Restore A1.
        $trashed = Monster::withTrashed()->where('name', 'A1')->firstOrFail();
        $trashed->restore();
        $this->assertMonsterInvariants('4: restore A1');

        // 5. Retype root (fire→water) — fire_count drops by 1.
        $root->refresh();
        $root->type = 'water';
        $root->save();
        $this->assertMonsterInvariants('5: Root type fire→water');

        // 6. Bump root's base_power.
        $root->refresh();
        $root->base_power = 20;
        $root->save();
        $this->assertMonsterInvariants('6: Root.base_power 5→20');

        // 7. Move A1 under B (cross-parent — listener min recompute).
        $a1->refresh();
        $a1->appendToNode($b->refresh())->save();
        $this->assertMonsterInvariants('7: move A1 under B');

        // 8. Bump A1's level high (no longer min).
        $a1->refresh();
        $a1->level = 99;
        $a1->save();
        $this->assertMonsterInvariants('8: A1.level 1→99');

        // 9. Soft-delete B1 then immediately restore.
        $b1->refresh();
        $b1->delete();
        $this->assertMonsterInvariants('9a: soft-delete B1');
        $trashedB1 = Monster::withTrashed()->where('name', 'B1')->firstOrFail();
        $trashedB1->restore();
        $this->assertMonsterInvariants('9b: restore B1');

        // 10. Add a chain of 3 deep descendants under A.
        $a->refresh();
        $parent = $a;
        foreach (['x', 'y', 'z'] as $n) {
            $node = new Monster(['name' => $n, 'type' => 'fire', 'base_power' => 1, 'level' => 1]);
            $node->appendToNode($parent->refresh())->save();
            $parent = $node;
        }
        $this->assertMonsterInvariants('10: chain x→y→z under A');

        // 11. Delete the chain tip 'z'.
        Monster::query()->where('name', 'z')->firstOrFail()->delete();
        $this->assertMonsterInvariants('11: soft-delete chain tip z');

        // 12. Promote A to root.
        $a->refresh();
        $a->makeRoot()->save();
        $this->assertMonsterInvariants('12: A becomes its own root');

        // 13. Re-parent A back under root.
        $a->refresh();
        $a->appendToNode($root->refresh())->save();
        $this->assertMonsterInvariants('13: A back under Root');
    }

    #[Test]
    public function typed_filtered_aggregates_stay_consistent_when_source_values_flip_in_and_out_of_filter_predicates(): void
    {
        $root = new TypedArea(['name' => 'Root', 'tickets' => 10, 'type' => 'fire']);
        $root->saveAsRoot();
        $a = new TypedArea(['name' => 'A', 'tickets' => 5, 'type' => 'water']);
        $a->appendToNode($root->refresh())->save();
        $b = new TypedArea(['name' => 'B', 'tickets' => 3, 'type' => null]);
        $b->appendToNode($root->refresh())->save();
        $a1 = new TypedArea(['name' => 'A1', 'tickets' => 7, 'type' => 'fire']);
        $a1->appendToNode($a->refresh())->save();
        $a2 = new TypedArea(['name' => 'A2', 'tickets' => 9, 'type' => 'water']);
        $a2->appendToNode($a->refresh())->save();

        $this->assertTypedAreaInvariants('seed');

        // 1. Retype A1: fire→water (drops fire_tickets, raises water_max chance).
        $a1->refresh();
        $a1->type = 'water';
        $a1->save();
        $this->assertTypedAreaInvariants('1: A1 fire→water');

        // 2. Bump B's tickets.
        $b->refresh();
        $b->tickets = 50;
        $b->save();
        $this->assertTypedAreaInvariants('2: B.tickets 3→50');

        // 3. Retype B null→fire (filter membership flip).
        $b->refresh();
        $b->type = 'fire';
        $b->save();
        $this->assertTypedAreaInvariants('3: B null→fire');

        // 4. Add a fire grandchild under A2.
        $a2x = new TypedArea(['name' => 'A2x', 'tickets' => 12, 'type' => 'fire']);
        $a2x->appendToNode($a2->refresh())->save();
        $this->assertTypedAreaInvariants('4: append A2x (fire)');

        // 5. Cross-parent move: A1 → under B.
        $a1->refresh();
        $a1->appendToNode($b->refresh())->save();
        $this->assertTypedAreaInvariants('5: move A1 under B');

        // 6. Delete A2x.
        TypedArea::query()->where('name', 'A2x')->firstOrFail()->delete();
        $this->assertTypedAreaInvariants('6: delete A2x');

        // 7. Bulk retype all current fire nodes → water.
        TypedArea::query()->where('type', 'fire')->get()->each(function (TypedArea $node): void {
            $node->type = 'water';
            $node->save();
        });
        $this->assertTypedAreaInvariants('7: bulk retype fire→water');

        // 8. Insert a new fire root branch.
        $c = new TypedArea(['name' => 'C', 'tickets' => 11, 'type' => 'fire']);
        $c->appendToNode($root->refresh())->save();
        $this->assertTypedAreaInvariants('8: append C (fire)');
    }

    #[Test]
    public function deferred_maintenance_closure_keeps_aggregates_correct_after_mixed_inserts_updates_and_deletes(): void
    {
        // Build a small tree first.
        $root = new Area(['name' => 'Root', 'tickets' => 0]);
        $root->saveAsRoot();
        $a = new Area(['name' => 'A', 'tickets' => 10]);
        $a->appendToNode($root->refresh())->save();
        $b = new Area(['name' => 'B', 'tickets' => 20]);
        $b->appendToNode($root->refresh())->save();

        $this->assertAreaInvariants('seed before deferred block');

        // Inside the deferred block: a *lot* of mixed mutations, all
        // accumulated into one fixAggregates at the closing brace.
        Area::withDeferredAggregateMaintenance(function () use ($root, $a, $b): void {
            // 1. Append 5 children under A.
            foreach ([3, 5, 7, 11, 13] as $i => $tickets) {
                $node = new Area(['name' => "a{$i}", 'tickets' => $tickets]);
                $node->appendToNode($a->refresh())->save();
            }

            // 2. Update A's own tickets.
            $a->refresh();
            $a->tickets = 100;
            $a->save();

            // 3. Move a3 (tickets=11) under B.
            $a3 = Area::query()->where('name', 'a3')->firstOrFail();
            $a3->appendToNode($b->refresh())->save();

            // 4. Bump root's tickets.
            $root->refresh();
            $root->tickets = 500;
            $root->save();

            // 5. Delete a1 (a leaf).
            Area::query()->where('name', 'a1')->firstOrFail()->delete();
        });

        // After the closure, one fixAggregates ran. Tree and aggregates
        // must be perfectly consistent.
        $this->assertAreaInvariants('after deferred block (single fixAggregates)');
    }

    // ================================================================
    // Randomised walks — same invariants, mutations chosen by seeded PRNG
    // ================================================================

    /**
     * Seeds chosen at random and pinned. Each seed walks ~25 mutations.
     *
     * @return iterable<string, array{seed: int, steps: int}>
     */
    public static function randomWalkSeedProvider(): iterable
    {
        yield 'seed 1 (25 steps)' => ['seed' => 1, 'steps' => 25];

        yield 'seed 42 (25 steps)' => ['seed' => 42, 'steps' => 25];

        yield 'seed 1337 (25 steps)' => ['seed' => 1337, 'steps' => 25];

        yield 'seed 9999 (30 steps)' => ['seed' => 9999, 'steps' => 30];
    }

    #[DataProvider('randomWalkSeedProvider')]
    #[Test]
    public function random_walk_area(int $seed, int $steps): void
    {
        mt_srand($seed);

        // Plant 3 nodes to start.
        $root = new Area(['name' => 'r', 'tickets' => 10]);
        $root->saveAsRoot();
        for ($i = 0; $i < 2; $i++) {
            $node = new Area(['name' => "n{$i}", 'tickets' => mt_rand(1, 50)]);
            $node->appendToNode($root->refresh())->save();
        }

        $this->assertAreaInvariants("[seed={$seed}] step 0 (seed)");

        for ($step = 1; $step <= $steps; $step++) {
            $action = $this->pickAction(mt_rand(0, 99));
            $this->randomAreaStep($action, $step);
            $this->assertAreaInvariants("[seed={$seed}] step {$step} ({$action})");
        }
    }

    #[DataProvider('randomWalkSeedProvider')]
    #[Test]
    public function random_walk_monster(int $seed, int $steps): void
    {
        mt_srand($seed);

        $root = new Monster(['name' => 'r', 'type' => 'fire', 'base_power' => 5, 'level' => 3]);
        $root->saveAsRoot();
        for ($i = 0; $i < 2; $i++) {
            $node = new Monster([
                'name' => "n{$i}",
                'type' => ($i % 2 === 0) ? 'fire' : 'water',
                'base_power' => mt_rand(1, 10),
                'level' => mt_rand(1, 5),
            ]);
            $node->appendToNode($root->refresh())->save();
        }

        $this->assertMonsterInvariants("[seed={$seed}] step 0 (seed)");

        for ($step = 1; $step <= $steps; $step++) {
            $action = $this->pickAction(mt_rand(0, 99), allowSoftDelete: true);
            $this->randomMonsterStep($action, $step);
            $this->assertMonsterInvariants("[seed={$seed}] step {$step} ({$action})");
        }
    }

    // ================================================================
    // Random-walk step implementations
    // ================================================================

    private function pickAction(int $roll, bool $allowSoftDelete = false): string
    {
        // 35 append, 20 update, 15 delete, 15 move, 10 soft+restore, 5 noop
        if ($roll < 35) {
            return 'append';
        }
        if ($roll < 55) {
            return 'update';
        }
        if ($roll < 70) {
            return 'delete';
        }
        if ($roll < 85) {
            return 'move';
        }
        if ($allowSoftDelete && $roll < 95) {
            return 'soft_delete_then_restore';
        }

        return 'noop';
    }

    private function randomAreaStep(string $action, int $step): void
    {
        $all = Area::query()->orderBy('lft')->get()->all();
        if ($all === []) {
            return;
        }

        switch ($action) {
            case 'append':
                $parent = $all[mt_rand(0, count($all) - 1)];
                $node = new Area(['name' => "s{$step}", 'tickets' => mt_rand(0, 200)]);
                $node->appendToNode($parent->refresh())->save();

                return;

            case 'update':
                $target = $all[mt_rand(0, count($all) - 1)];
                $target->tickets = mt_rand(0, 500);
                $target->save();

                return;

            case 'delete':
                if (count($all) < 2) {
                    return;
                }
                $leaves = array_filter($all, fn (Area $n): bool => ($n->rgt - $n->lft) === 1 && $n->parent_id !== null);
                if ($leaves === []) {
                    return;
                }
                $leafList = array_values($leaves);
                $leaf = $leafList[mt_rand(0, count($leafList) - 1)];
                $leaf->delete();

                return;

            case 'move':
                if (count($all) < 3) {
                    return;
                }
                $candidates = array_filter(
                    $all,
                    fn (Area $n): bool => $n->parent_id !== null && ($n->rgt - $n->lft) === 1,
                );
                if ($candidates === []) {
                    return;
                }
                $list = array_values($candidates);
                $node = $list[mt_rand(0, count($list) - 1)];

                $targets = array_filter(
                    $all,
                    fn (Area $t): bool => $t->getKey() !== $node->getKey()
                        && $t->getKey() !== $node->parent_id
                        && ! $node->isAncestorOf($t),
                );
                if ($targets === []) {
                    return;
                }
                $tList = array_values($targets);
                $target = $tList[mt_rand(0, count($tList) - 1)];
                $node->appendToNode($target->refresh())->save();

                return;

            case 'noop':
            default:
                return;
        }
    }

    private function randomMonsterStep(string $action, int $step): void
    {
        $all = Monster::query()->orderBy('lft')->get()->all();
        if ($all === []) {
            return;
        }

        switch ($action) {
            case 'append':
                $parent = $all[mt_rand(0, count($all) - 1)];
                $node = new Monster([
                    'name' => "s{$step}",
                    'type' => mt_rand(0, 1) === 0 ? 'fire' : 'water',
                    'base_power' => mt_rand(1, 10),
                    'level' => mt_rand(1, 5),
                ]);
                $node->appendToNode($parent->refresh())->save();

                return;

            case 'update':
                $target = $all[mt_rand(0, count($all) - 1)];
                $col = ['base_power', 'level', 'type'][mt_rand(0, 2)];
                if ($col === 'type') {
                    $target->type = $target->type === 'fire' ? 'water' : 'fire';
                } elseif ($col === 'level') {
                    $target->level = mt_rand(1, 10);
                } else {
                    $target->base_power = mt_rand(1, 20);
                }
                $target->save();

                return;

            case 'delete':
                if (count($all) < 2) {
                    return;
                }
                $leaves = array_filter($all, fn (Monster $n): bool => ($n->rgt - $n->lft) === 1 && $n->parent_id !== null);
                if ($leaves === []) {
                    return;
                }
                $leafList = array_values($leaves);
                $leaf = $leafList[mt_rand(0, count($leafList) - 1)];
                $leaf->delete(); // soft delete

                return;

            case 'move':
                if (count($all) < 3) {
                    return;
                }
                $candidates = array_filter(
                    $all,
                    fn (Monster $n): bool => $n->parent_id !== null && ($n->rgt - $n->lft) === 1,
                );
                if ($candidates === []) {
                    return;
                }
                $list = array_values($candidates);
                $node = $list[mt_rand(0, count($list) - 1)];

                $targets = array_filter(
                    $all,
                    fn (Monster $t): bool => $t->getKey() !== $node->getKey()
                        && $t->getKey() !== $node->parent_id
                        && ! $node->isAncestorOf($t),
                );
                if ($targets === []) {
                    return;
                }
                $tList = array_values($targets);
                $target = $tList[mt_rand(0, count($tList) - 1)];
                $node->appendToNode($target->refresh())->save();

                return;

            case 'soft_delete_then_restore':
                $leaves = array_filter($all, fn (Monster $n): bool => ($n->rgt - $n->lft) === 1 && $n->parent_id !== null);
                if ($leaves === []) {
                    return;
                }
                $leafList = array_values($leaves);
                $leaf = $leafList[mt_rand(0, count($leafList) - 1)];
                $leafId = (int) $leaf->id;
                $leaf->delete();
                $trashed = Monster::withTrashed()->findOrFail($leafId);
                $trashed->restore();

                return;

            case 'noop':
            default:
                return;
        }
    }

    // ================================================================
    // Invariant helpers — one per fixture model
    // ================================================================

    private function assertAreaInvariants(string $stage): void
    {
        $this->assertFalse(Area::isBroken(), "[{$stage}] tree is broken: ".json_encode(Area::countErrors()));
        $this->assertFalse(Area::aggregatesAreBroken(), "[{$stage}] aggregates drifted");

        foreach (Area::query()->orderBy('lft')->get() as $node) {
            foreach ($node->getAggregateDefinitions() as $definition) {
                $column = $definition->getColumn();
                $stored = $node->getAttribute($column);
                $fresh = $node->freshAggregate($column);
                $this->assertSameAggregateValue(
                    $fresh,
                    $stored,
                    "[{$stage}] #{$node->id}({$node->name}).{$column}: stored != fresh",
                );
            }
        }
    }

    private function assertBranchInvariants(string $stage): void
    {
        $this->assertFalse(Branch::isBroken(), "[{$stage}] tree is broken: ".json_encode(Branch::countErrors()));
        $this->assertFalse(Branch::aggregatesAreBroken(), "[{$stage}] aggregates drifted");

        foreach (Branch::query()->orderBy('lft')->get() as $node) {
            foreach ($node->getAggregateDefinitions() as $definition) {
                $column = $definition->getColumn();
                $stored = $node->getAttribute($column);
                $fresh = $node->freshAggregate($column);
                $this->assertSameAggregateValue(
                    $fresh,
                    $stored,
                    "[{$stage}] #{$node->id}({$node->name}).{$column}: stored != fresh",
                );
            }
        }
    }

    private function assertMonsterInvariants(string $stage): void
    {
        $this->assertFalse(Monster::isBroken(), "[{$stage}] tree is broken: ".json_encode(Monster::countErrors()));
        $this->assertFalse(Monster::aggregatesAreBroken(), "[{$stage}] aggregates drifted");

        foreach (Monster::query()->orderBy('lft')->get() as $node) {
            foreach ($node->getAggregateDefinitions() as $definition) {
                $column = $definition->getColumn();
                $stored = $node->getAttribute($column);
                $fresh = $node->freshAggregate($column);
                $this->assertSameAggregateValue(
                    $fresh,
                    $stored,
                    "[{$stage}] #{$node->id}({$node->name}).{$column}: stored != fresh",
                );
            }
        }
    }

    private function assertTypedAreaInvariants(string $stage): void
    {
        $this->assertFalse(TypedArea::isBroken(), "[{$stage}] tree is broken: ".json_encode(TypedArea::countErrors()));
        $this->assertFalse(TypedArea::aggregatesAreBroken(), "[{$stage}] aggregates drifted");

        foreach (TypedArea::query()->orderBy('lft')->get() as $node) {
            foreach ($node->getAggregateDefinitions() as $definition) {
                $column = $definition->getColumn();
                $stored = $node->getAttribute($column);
                $fresh = $node->freshAggregate($column);
                $this->assertSameAggregateValue(
                    $fresh,
                    $stored,
                    "[{$stage}] #{$node->id}({$node->name}).{$column}: stored != fresh",
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
}
