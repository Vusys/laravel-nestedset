<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Runabout\Journeys;

use PHPUnit\Framework\Assert;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Invariants;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: the SUM/COUNT/AVG/MIN/MAX aggregate columns on {@see Area}
 * must stay correct no matter what order structural moves and
 * source-column edits arrive in.
 *
 * This is the order-dependent bug class the per-mutation fuzzers reach
 * only by chance: the delta-maintenance path (append/move/source-edit)
 * and the recompute path (MIN/MAX subtree invalidation) have to agree
 * with a fresh recompute after *every* interleaving. Runabout drives
 * the same public API in seeded shuffles and shrinks any failing order
 * to its minimal reproduction.
 *
 * Two independent invariants guard the headline SUM so a shared blind
 * spot can't hide: {@see Area::aggregateErrors()} (the library's own
 * fresh-read path) and a hand-rolled bounds-sum via
 * {@see Invariants::cachedColumnMatches} (Eloquent, no package code in
 * the loop).
 */
final class AreaAggregateJourney extends Journey
{
    /** @return list<Step> */
    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('plant root')
                ->act(function (Context $ctx): void {
                    $root = new Area(['name' => 'root', 'tickets' => $ctx->randomInt(0, 20)]);
                    $root->saveAsRoot();
                })
                ->assert(fn () => Assert::assertSame(1, Area::query()->count())),

            Step::make('graft child')
                ->after('plant root')
                ->repeatable()
                ->weight(3)
                ->act(function (Context $ctx): void {
                    $all = Area::query()->orderBy('id')->get()->all();
                    $parent = $all[$ctx->randomInt(0, count($all) - 1)];

                    (new Area(['name' => 'n', 'tickets' => $ctx->randomInt(0, 20)]))
                        ->appendToNode($parent)
                        ->save();
                }),

            Step::make('reassign tickets')
                ->after('plant root')
                ->repeatable()
                ->weight(2)
                ->act(function (Context $ctx): void {
                    $all = Area::query()->orderBy('id')->get()->all();
                    $node = $all[$ctx->randomInt(0, count($all) - 1)];

                    $node->tickets = $ctx->randomInt(0, 20);
                    $node->save();
                }),

            Step::make('move subtree')
                ->after('graft child')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $all = Area::query()->orderBy('id')->get()->all();
                    if (count($all) < 2) {
                        return;
                    }

                    $node = $all[$ctx->randomInt(0, count($all) - 1)];

                    // A node may only move under something outside its own
                    // subtree — moving it under a descendant is illegal.
                    $targets = array_values(array_filter(
                        $all,
                        fn (Area $candidate): bool => $candidate->getKey() !== $node->getKey()
                            && ($candidate->getLft() < $node->getLft() || $candidate->getRgt() > $node->getRgt()),
                    ));

                    if ($targets === []) {
                        return;
                    }

                    $target = $targets[$ctx->randomInt(0, count($targets) - 1)];
                    $node->appendToNode($target)->save();
                }),

            Step::make('prune leaf')
                ->after('graft child')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $all = Area::query()->orderBy('id')->get()->all();
                    if (count($all) <= 1) {
                        return;
                    }

                    $leaves = array_values(array_filter(
                        $all,
                        fn (Area $node): bool => $node->getRgt() === $node->getLft() + 1,
                    ));

                    if ($leaves === []) {
                        return;
                    }

                    $leaves[$ctx->randomInt(0, count($leaves) - 1)]->delete();
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

            Invariant::make('Area aggregates match a fresh recompute', function (): void {
                Assert::assertSame(
                    [],
                    array_filter(Area::aggregateErrors()),
                    'Aggregate drift: '.json_encode(Area::aggregateErrors()),
                );
            }),

            // Independent of the package read path: recompute the subtree
            // sum straight from source rows via bounds. Guards the shared
            // blind spot where maintenance and the fresh-read path could be
            // wrong together.
            Invariants::cachedColumnMatches(
                Area::class,
                'tickets_total',
                fn (Area $area): int => (int) Area::query()
                    ->whereBetween('lft', [$area->getLft(), $area->getRgt()])
                    ->sum('tickets'),
            ),
        ];
    }
}
