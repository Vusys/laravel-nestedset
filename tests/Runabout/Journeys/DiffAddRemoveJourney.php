<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Runabout\Journeys;

use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Assert;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Diff\TreeDiffApplier;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: a full add/move/remove/modify diff and its directly
 * recomputed inverse round-trip a tree back to its exact starting
 * shape — exercising the diff applier's four-phase ordering that
 * {@see DiffRoundTripJourney} deliberately leaves out.
 *
 * Where `DiffRoundTripJourney` holds identity fixed (moves + modifies
 * only, keyed on `id`), this keys on a tree-wide-unique `name` so
 * inserts and deletes round-trip too: a removed node re-inserts under
 * its original name with a fresh key, and the snapshot compares by
 * name. That unlocks the applier's hardest ordering rule — "adds run
 * first so a move can target a newly-added parent; moves run before
 * removes so a retained child is reparented out of a doomed subtree
 * before its hard-delete cascade" ({@see TreeDiffApplier}).
 * A churn that grafts a node and moves an existing one under it, or
 * that moves a child out of a node then prunes it, produces exactly the
 * simultaneous add+move / move+remove the fixed ordering exists for.
 *
 * The round-trip is the oracle: snapshot (`before`, frozen Eloquent
 * models that keep their pre-churn attributes), churn with a mix of
 * grafts / moves / prunes / ticket edits (`after`), then apply
 * `between(after, before, 'name')`. A correct apply lands the tree back
 * on `before` — sibling order, parents, and payload all restored, which
 * the assertion checks as an ordered `defaultOrder()` description.
 *
 * `Area` carries SQL aggregates, so the standing invariants also prove
 * the applier's deferred aggregate recompute stays correct across the
 * insert/delete churn, and it has no soft-deletes, so a pruned row is
 * genuinely gone and its name is free to re-add cleanly.
 */
final class DiffAddRemoveJourney extends Journey
{
    /** Bounds snapshot/diff/apply cost per round-trip. */
    private const int GRAFT_CAP = 30;

    /** @return list<Step> */
    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('plant root')
                ->act(function (Context $ctx): void {
                    (new Area(['name' => $this->nextName($ctx), 'tickets' => $ctx->randomInt(0, 20)]))->saveAsRoot();
                })
                ->assert(fn () => Assert::assertSame(1, Area::query()->count())),

            // Lasting growth so round-trips have material to churn; a
            // round-trip itself is net-neutral (it restores what it churns).
            Step::make('graft child')
                ->after('plant root')
                ->repeatable()
                ->weight(3)
                ->act(function (Context $ctx): void {
                    if (Area::query()->count() >= self::GRAFT_CAP) {
                        return;
                    }

                    $all = Area::query()->orderBy('id')->get()->all();
                    $parent = $all[$ctx->randomInt(0, count($all) - 1)];

                    (new Area(['name' => $this->nextName($ctx), 'tickets' => $ctx->randomInt(0, 20)]))
                        ->appendToNode($parent)
                        ->save();
                }),

            Step::make('reassign tickets')
                ->after('plant root')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $all = Area::query()->orderBy('id')->get()->all();
                    $node = $all[$ctx->randomInt(0, count($all) - 1)];

                    $node->tickets = $ctx->randomInt(0, 20);
                    $node->save();
                }),

            Step::make('diff round-trip')
                ->after('graft child')
                ->repeatable()
                ->weight(2)
                ->act(function (Context $ctx): void {
                    if (Area::query()->count() < 2) {
                        return;
                    }

                    $before = Area::query()->defaultOrder()->get();

                    $churn = $ctx->randomInt(2, 5);
                    for ($i = 0; $i < $churn; $i++) {
                        $this->churnOnce($ctx);
                    }

                    $after = Area::query()->defaultOrder()->get();

                    // Recompute the inverse directly (after -> before) so each
                    // Moved carries before's true position and each removed row
                    // comes back as an Added carrying its original attributes.
                    TreeDiff::between($after, $before, 'name')->apply(Area::class);

                    Assert::assertEquals(
                        $this->describe($before),
                        $this->describe(Area::query()->defaultOrder()->get()),
                        'Inverse diff failed to restore the tree to its pre-churn shape.',
                    );
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            Invariant::make('Area structure intact', function (): void {
                Assert::assertSame(
                    [],
                    array_filter(Area::countErrors()),
                    'Tree corruption: '.json_encode(Area::countErrors()),
                );
            }),

            // The applier defers aggregate maintenance across all four
            // phases; after an add/remove round-trip the stored aggregates
            // must still match a fresh recompute.
            Invariant::make('Area aggregates match a fresh recompute', function (): void {
                Assert::assertSame(
                    [],
                    array_filter(Area::aggregateErrors()),
                    'Aggregate drift: '.json_encode(Area::aggregateErrors()),
                );
            }),
        ];
    }

    /**
     * One change inside a round-trip window: graft, prune a leaf, move a
     * subtree, or edit tickets. The mix is what makes the resulting diff
     * carry adds, removes, moves and modifies at once.
     */
    private function churnOnce(Context $ctx): void
    {
        $all = Area::query()->orderBy('id')->get()->all();

        switch ($ctx->pick(['graft', 'prune', 'move', 'retickets'])) {
            case 'graft':
                if (count($all) >= self::GRAFT_CAP) {
                    return;
                }
                $parent = $all[$ctx->randomInt(0, count($all) - 1)];
                (new Area(['name' => $this->nextName($ctx), 'tickets' => $ctx->randomInt(0, 20)]))
                    ->appendToNode($parent)
                    ->save();

                return;

            case 'prune':
                $leaves = array_values(array_filter(
                    $all,
                    fn (Area $node): bool => $node->getRgt() === $node->getLft() + 1
                        && $node->getParentId() !== null,
                ));
                if ($leaves === []) {
                    return;
                }
                $leaves[$ctx->randomInt(0, count($leaves) - 1)]->delete();

                return;

            case 'move':
                $node = $all[$ctx->randomInt(0, count($all) - 1)];
                $targets = array_values(array_filter(
                    $all,
                    fn (Area $candidate): bool => $candidate->getKey() !== $node->getKey()
                        && $candidate->getKey() !== $node->getParentId()
                        && ($candidate->getLft() < $node->getLft() || $candidate->getRgt() > $node->getRgt()),
                ));
                if ($targets === []) {
                    return;
                }
                $node->appendToNode($targets[$ctx->randomInt(0, count($targets) - 1)]->refresh())->save();

                return;

            default:
                $node = $all[$ctx->randomInt(0, count($all) - 1)];
                $node->tickets = $ctx->randomInt(0, 20);
                $node->save();
        }
    }

    /**
     * An identity-keyed description in sibling (lft) order: each node as
     * (name, parent's name, tickets). Comparing two of these asserts the
     * tree matches by structure, sibling order, and payload — independent
     * of the primary keys, which change when a removed node re-inserts.
     *
     * @param  Collection<int, Area>  $models
     * @return list<array{name: string, parent: string|null, tickets: int}>
     */
    private function describe(Collection $models): array
    {
        $nameById = [];
        foreach ($models as $model) {
            $nameById[$model->id] = $model->name;
        }

        /** @var list<array{name: string, parent: string|null, tickets: int}> $out */
        $out = [];
        foreach ($models as $model) {
            $out[] = [
                'name' => $model->name,
                'parent' => $model->parent_id === null ? null : ($nameById[$model->parent_id] ?? null),
                'tickets' => $model->tickets,
            ];
        }

        return $out;
    }

    /**
     * A tree-wide-unique node name. A monotonic counter in the trail
     * context guarantees every live node has a distinct name, which is
     * what lets the diff key on `name` and re-insert removed rows under
     * their original identity.
     */
    private function nextName(Context $ctx): string
    {
        $seq = $ctx->has('name seq') ? $ctx->integer('name seq') + 1 : 1;
        $ctx->remember('name seq', $seq);

        return 'a'.$seq;
    }
}
