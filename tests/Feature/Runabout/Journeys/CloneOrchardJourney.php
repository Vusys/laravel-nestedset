<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Runabout\Journeys;

use PHPUnit\Framework\Assert;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Invariants;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: subtree cloning ({@see Area::cloneSubtreeTo()} /
 * {@see Area::cloneSubtreeAsRoot()}) must leave the tree and its
 * aggregate columns coherent no matter what order clones interleave
 * with grafts, moves, and source-column edits.
 *
 * A clone is not a normal mutation. It sits on top of `bulkInsertTree`:
 * one gap-open, an event-suppressed bulk INSERT of the whole payload,
 * an optional reposition ({@see Area::cloneSubtreeTo()} with a
 * non-`'last'` position re-fires `moveTo`), and a single *deferred*
 * aggregate recompute at the end of the clone's transaction. The
 * {@see AreaAggregateJourney} drives the per-row delta / recompute
 * paths; this journey drives the clone path that bypasses them and
 * back-fills via one batch recompute — the interaction never exercised
 * by the leaf-at-a-time fuzzers.
 *
 * The order-dependent bug class: clone a subtree, move it, clone the
 * clone, edit a source ticket count, clone as a new root — every step
 * re-numbers `lft`/`rgt` and the deferred recompute has to agree with a
 * fresh read after each one. A clone whose recompute reads stale bounds
 * (or whose payload leaks a source aggregate value instead of zeroing
 * it for the recompute) drifts.
 *
 * Same three guards as {@see AreaAggregateJourney} so the clone path is
 * held to the identical standard: structure ({@see Area::countErrors()}),
 * the library's own fresh recompute ({@see Area::aggregateErrors()}),
 * and an independent bounds-sum with no package aggregate code in the
 * loop. `makeRoot` numbers each root at `maxRgt + 1`, so the forest's
 * `lft`/`rgt` ranges stay globally disjoint and the bounds-sum is exact
 * even after `cloneSubtreeAsRoot` adds sibling roots.
 */
final class CloneOrchardJourney extends Journey
{
    /**
     * Clones multiply rows, so an unbounded run explodes. Above this
     * live-row count the clone steps no-op — grafts, moves, edits, and
     * prunes still churn structure, keeping the shuffle productive
     * without letting a run balloon into a slow one.
     */
    private const int CLONE_CAP = 50;

    /** @return list<Step> */
    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('plant root')
                ->act(function (Context $ctx): void {
                    (new Area(['name' => 'root', 'tickets' => $ctx->randomInt(0, 20)]))->saveAsRoot();
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

            // The headline mutation: deep-copy a subtree under a node
            // outside itself. Position alternates so both the append-last
            // fast path and the moveTo reposition inside the clone fire.
            Step::make('clone into tree')
                ->after('graft child')
                ->repeatable()
                ->weight(2)
                ->act(function (Context $ctx): void {
                    if (Area::query()->count() >= self::CLONE_CAP) {
                        return;
                    }

                    $all = Area::query()->orderBy('id')->get()->all();
                    $source = $all[$ctx->randomInt(0, count($all) - 1)];

                    // A clone target must lie outside the source's own
                    // subtree (self and descendants excluded); the source's
                    // parent is a legal target and a distinct case.
                    $targets = array_values(array_filter(
                        $all,
                        fn (Area $candidate): bool => $candidate->getKey() !== $source->getKey()
                            && ($candidate->getLft() < $source->getLft() || $candidate->getRgt() > $source->getRgt()),
                    ));

                    if ($targets === []) {
                        return;
                    }

                    $target = $targets[$ctx->randomInt(0, count($targets) - 1)];
                    $source->cloneSubtreeTo($target, $ctx->pick(['last', 'first']));
                }),

            // The distinct as-root clone path: bulk-insert then promote to
            // a new root among its siblings. Keeps the forest multi-rooted,
            // exercising the disjoint-range numbering the bounds oracle
            // relies on.
            Step::make('clone as root')
                ->after('graft child')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    if (Area::query()->count() >= self::CLONE_CAP) {
                        return;
                    }

                    $all = Area::query()->orderBy('id')->get()->all();
                    $source = $all[$ctx->randomInt(0, count($all) - 1)];

                    $source->cloneSubtreeAsRoot($ctx->pick(['last', 'first']));
                }),

            Step::make('relocate subtree')
                ->after('graft child')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $all = Area::query()->orderBy('id')->get()->all();
                    if (count($all) < 2) {
                        return;
                    }

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
                        fn (Area $node): bool => $node->getRgt() === $node->getLft() + 1
                            && $node->getParentId() !== null,
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

            // Independent of the package read path: recompute each subtree
            // sum straight from source rows via bounds. Roots are numbered
            // at maxRgt + 1, so ranges never overlap across the forest and
            // the bounds-sum stays exact after an as-root clone.
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
