<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Runabout\Journeys;

use PHPUnit\Framework\Assert;
use Vusys\NestedSet\Exceptions\TrashedAncestorException;
use Vusys\NestedSet\Tests\Fixtures\Models\SoftBranch;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Invariants;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: cascade soft-delete / restore interleaved with structural
 * growth and source-column edits on {@see SoftBranch}.
 *
 * SoftBranch carries an exclusive SUM, an exclusive COUNT/MAX and a
 * raw-filter SUM (`active = 1`) alongside SoftDeletes — the exact combo
 * where snapshot semantics bite: per-mutation deltas must skip trashed
 * ancestors, and the raw-filter column recomputes when `active` flips.
 * Trashing a parent and restoring it in a shuffled order is where a
 * missed filter on either side of the recompute leaks.
 *
 * The restore step covers both sides of the restore guard: restoring a
 * subtree-top succeeds, while restoring a node whose parent is still
 * trashed must throw {@see TrashedAncestorException} (v0.24.3) — the
 * cascade never walks up, so a partial restore would leave a live child
 * under a trashed parent.
 *
 * Invariants lean on the library's own trashed-aware detectors
 * ({@see SoftBranch::aggregateErrors()} / {@see SoftBranch::isBroken()}),
 * in the spirit of the existing cascade fuzzer, plus the built-in
 * soft-delete leak check.
 */
final class SoftBranchLifecycleJourney extends Journey
{
    /** @return list<Step> */
    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('plant root')
                ->act(function (Context $ctx): void {
                    $root = new SoftBranch([
                        'name' => 'root',
                        'tickets' => $ctx->randomInt(0, 20),
                        'active' => $ctx->pick([0, 1]),
                    ]);
                    $root->saveAsRoot();
                })
                ->assert(fn () => Assert::assertSame(1, SoftBranch::query()->count())),

            Step::make('graft child')
                ->after('plant root')
                ->repeatable()
                ->weight(3)
                ->act(function (Context $ctx): void {
                    $all = SoftBranch::query()->orderBy('id')->get()->all();
                    $parent = $all[$ctx->randomInt(0, count($all) - 1)];

                    (new SoftBranch([
                        'name' => 'n',
                        'tickets' => $ctx->randomInt(0, 20),
                        'active' => $ctx->pick([0, 1]),
                    ]))->appendToNode($parent)->save();
                }),

            Step::make('reassign tickets')
                ->after('plant root')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $all = SoftBranch::query()->orderBy('id')->get()->all();
                    $node = $all[$ctx->randomInt(0, count($all) - 1)];

                    $node->tickets = $ctx->randomInt(0, 20);
                    $node->save();
                }),

            Step::make('toggle active')
                ->after('plant root')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $all = SoftBranch::query()->orderBy('id')->get()->all();
                    $node = $all[$ctx->randomInt(0, count($all) - 1)];

                    $node->active = $node->active === 1 ? 0 : 1;
                    $node->save();
                }),

            Step::make('trash subtree')
                ->after('graft child')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    // Only non-root live nodes: keep at least the root alive
                    // so later steps always have somewhere to act.
                    $candidates = SoftBranch::query()
                        ->whereNotNull('parent_id')
                        ->orderBy('id')
                        ->get()
                        ->all();

                    if ($candidates === []) {
                        return;
                    }

                    $candidates[$ctx->randomInt(0, count($candidates) - 1)]->delete();
                }),

            Step::make('restore node')
                ->after('trash subtree')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    // Restore ANY trashed node, not just subtree-tops, so both
                    // sides of the restore guard are exercised under shuffling:
                    //
                    //  - parent live (or a root)  -> restore succeeds, bringing
                    //    the node plus its same-stamp descendants back.
                    //  - parent still trashed     -> restore() must refuse with
                    //    TrashedAncestorException. The cascade never walks up, so
                    //    bringing the node back would leave a live child under a
                    //    trashed parent; the guard reads the parent's deleted_at
                    //    before any write, so a rejected restore is a no-op.
                    //
                    // A cascade delete stamps the whole subtree, so a live parent
                    // implies live ancestors — "parent live" is the clean case.
                    $trashed = SoftBranch::onlyTrashed()->orderBy('id')->get()->all();
                    if ($trashed === []) {
                        return;
                    }

                    $node = $trashed[$ctx->randomInt(0, count($trashed) - 1)];

                    $parentTrashed = false;
                    if ($node->parent_id !== null) {
                        $parent = SoftBranch::withTrashed()->find($node->parent_id);
                        $parentTrashed = $parent !== null && $parent->trashed();
                    }

                    if (! $parentTrashed) {
                        $node->restore();

                        return;
                    }

                    try {
                        $node->restore();
                        Assert::fail('restore() of a node under a trashed parent must throw TrashedAncestorException.');
                    } catch (TrashedAncestorException) {
                        // Expected — the guard rejected the partial restore.
                    }

                    Assert::assertTrue(
                        SoftBranch::withTrashed()->whereKey($node->getKey())->first()?->trashed() ?? false,
                        'A rejected restore must leave the node trashed and the tree untouched.',
                    );
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            Invariant::make('SoftBranch structure intact', function (): void {
                Assert::assertFalse(SoftBranch::isBroken(), 'SoftBranch tree is broken.');
            }),

            Invariant::make('SoftBranch aggregates match a fresh recompute', function (): void {
                Assert::assertSame(
                    [],
                    array_filter(SoftBranch::aggregateErrors()),
                    'Aggregate drift: '.json_encode(SoftBranch::aggregateErrors()),
                );
            }),

            // A trashed parent must never keep a live child — the classic
            // cascade soft-delete leak. Default scope excludes trashed, so
            // this counts only live children.
            Invariants::trashedLeavesNoLiveChildren(
                SoftBranch::class,
                fn (SoftBranch $parent): int => SoftBranch::query()
                    ->where('parent_id', $parent->getKey())
                    ->count(),
                'children',
            ),
        ];
    }
}
