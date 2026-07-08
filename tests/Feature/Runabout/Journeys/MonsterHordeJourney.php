<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Runabout\Journeys;

use PHPUnit\Framework\Assert;
use Vusys\NestedSet\Tests\Fixtures\Models\Monster;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Invariants;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: the *listener* aggregate columns on {@see Monster} must stay
 * correct under every ordering of structural moves, source edits, and
 * cascade soft-delete / restore.
 *
 * Where {@see AreaAggregateJourney} drives the SQL-aggregate path, Monster
 * exercises what that journey cannot reach at once:
 *   - listener aggregates (`contribution(Model)` in PHP) rather than a
 *     source-column SUM — a separate maintenance path;
 *   - an *exclusive* column (`descendant_fire_count`, descendants only,
 *     self excluded) alongside inclusive ones, so a fencepost error in the
 *     self-vs-descendant split shows up;
 *   - MIN/MAX listener columns (`weakest_level` / `strongest_level`) whose
 *     recompute-on-shrink path is order-sensitive; and
 *   - SoftDeletes, so a trashed contributor must drop out of every ancestor
 *     sum and rejoin on restore.
 *
 * Three source columns feed the listeners — `base_power` and `level` drive
 * the weighted sums / avg / min / max, `type` drives the fire counts — so a
 * single "empower" edit fans out into delta, recompute, and filter paths at
 * once.
 *
 * Oracles: the package's own fresh recompute ({@see Monster::aggregateErrors()}),
 * plus an independent bounds-sum for the headline inclusive column that runs
 * no package aggregate code, plus the soft-delete leak check. Restores are
 * taken only from the top of a trashed subtree — restoring a node under a
 * still-trashed ancestor is the separately-tracked partial-restore asymmetry,
 * not this journey's target.
 */
final class MonsterHordeJourney extends Journey
{
    /** @return list<Step> */
    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('plant root')
                ->act(function (Context $ctx): void {
                    (new Monster($this->stats($ctx, 'root')))->saveAsRoot();
                })
                ->assert(fn () => Assert::assertSame(1, Monster::query()->count())),

            Step::make('spawn')
                ->after('plant root')
                ->repeatable()
                ->weight(3)
                ->act(function (Context $ctx): void {
                    $all = Monster::query()->orderBy('id')->get()->all();
                    $parent = $all[$ctx->randomInt(0, count($all) - 1)];

                    (new Monster($this->stats($ctx, 'm')))->appendToNode($parent)->save();
                }),

            // One edit fans into every listener: base_power/level move the
            // weighted sums/avg and the min/max levels; type moves the fire
            // counts. Re-editing the same node is how delta vs recompute drift.
            Step::make('empower')
                ->after('plant root')
                ->repeatable()
                ->weight(2)
                ->act(function (Context $ctx): void {
                    $all = Monster::query()->orderBy('id')->get()->all();
                    $node = $all[$ctx->randomInt(0, count($all) - 1)];

                    $node->base_power = $ctx->randomInt(0, 20);
                    $node->level = $ctx->randomInt(1, 9);
                    $node->type = $ctx->pick(['fire', 'ice', 'earth']);
                    $node->save();
                }),

            Step::make('relocate subtree')
                ->after('spawn')
                ->repeatable()
                ->weight(2)
                ->act(function (Context $ctx): void {
                    $all = Monster::query()->orderBy('id')->get()->all();
                    if (count($all) < 2) {
                        return;
                    }

                    $node = $all[$ctx->randomInt(0, count($all) - 1)];

                    $targets = array_values(array_filter(
                        $all,
                        fn (Monster $candidate): bool => $candidate->getKey() !== $node->getKey()
                            && $candidate->getKey() !== $node->getParentId()
                            && ($candidate->getLft() < $node->getLft() || $candidate->getRgt() > $node->getRgt()),
                    ));

                    if ($targets === []) {
                        return;
                    }

                    $node->appendToNode($targets[$ctx->randomInt(0, count($targets) - 1)]->refresh())->save();
                }),

            Step::make('banish')
                ->after('spawn')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    // Keep the root alive so later steps always have an anchor.
                    $candidates = Monster::query()
                        ->whereNotNull('parent_id')
                        ->orderBy('id')
                        ->get()
                        ->all();

                    if ($candidates === []) {
                        return;
                    }

                    $candidates[$ctx->randomInt(0, count($candidates) - 1)]->delete();
                }),

            Step::make('summon back')
                ->after('banish')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    // Only restore the top of a trashed subtree — a node whose
                    // parent is live (or null). Restoring under a still-trashed
                    // ancestor is the separately-tracked partial-restore
                    // asymmetry; this journey stays on the guaranteed flow.
                    $restorable = Monster::onlyTrashed()
                        ->orderBy('id')
                        ->get()
                        ->filter(function (Monster $node): bool {
                            if ($node->parent_id === null) {
                                return true;
                            }

                            $parent = Monster::withTrashed()->find($node->parent_id);

                            return $parent !== null && ! $parent->trashed();
                        })
                        ->values()
                        ->all();

                    if ($restorable === []) {
                        return;
                    }

                    $restorable[$ctx->randomInt(0, count($restorable) - 1)]->restore();
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            Invariant::make('Monster structure intact', function (): void {
                Assert::assertFalse(Monster::isBroken(), 'Monster tree is broken.');
            }),

            Invariant::make('Monster listener aggregates match a fresh recompute', function (): void {
                Assert::assertSame(
                    [],
                    array_filter(Monster::aggregateErrors()),
                    'Aggregate drift: '.json_encode(Monster::aggregateErrors()),
                );
            }),

            // Independent of the package aggregate path: recompute the headline
            // inclusive weighted-power sum straight from live source rows via
            // bounds. weighted_power = SUM(base_power * level) over the subtree,
            // trashed contributors excluded (default scope on both queries).
            Invariants::cachedColumnMatches(
                Monster::class,
                'weighted_power',
                fn (Monster $node): int => (int) Monster::query()
                    ->whereBetween('lft', [$node->getLft(), $node->getRgt()])
                    ->get()
                    ->sum(fn (Monster $row): int => (int) $row->base_power * (int) $row->level),
            ),

            // The cascade-soft-delete leak: a trashed parent must never keep a
            // live child. Default scope counts only live children.
            Invariants::trashedLeavesNoLiveChildren(
                Monster::class,
                fn (Monster $parent): int => Monster::query()
                    ->where('parent_id', $parent->getKey())
                    ->count(),
                'children',
            ),
        ];
    }

    /**
     * A fresh stat block for a new or re-rolled monster.
     *
     * @return array{name: string, type: string, base_power: int, level: int}
     */
    private function stats(Context $ctx, string $name): array
    {
        return [
            'name' => $name,
            'type' => $ctx->pick(['fire', 'ice', 'earth']),
            'base_power' => $ctx->randomInt(0, 20),
            'level' => $ctx->randomInt(1, 9),
        ];
    }
}
