<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Runabout\Journeys;

use PHPUnit\Framework\Assert;
use Vusys\NestedSet\Diff\TreeDiff;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: {@see TreeDiff::apply()} must replay a moves-and-modifies
 * diff so faithfully that a diff and its recomputed inverse round-trip
 * a tree back to byte-for-byte its starting shape.
 *
 * The applier runs its four phases in a fixed order (add → move →
 * remove → modify) and each phase re-numbers `lft`/`rgt` as it goes,
 * so a diff carrying many *simultaneous* moves is the order-dependent
 * bug class: the moves have to compose to the same tree regardless of
 * the sequence the applier walks them in, and each move's recorded
 * sibling position has to survive its siblings shifting underneath it.
 * The `Diff/` unit tests apply one hand-built diff at a time; none feed
 * apply a diff derived from two arbitrary fuzzed tree states.
 *
 * The round-trip is the oracle. Snapshot the tree (`before`), perform
 * a batch of real moves and title edits (`after`), then apply
 * `between(after, before)` — the inverse computed *directly* from the
 * two snapshots, not {@see TreeDiff::invert()} (whose swapped `Moved`
 * carries no original position and so can't restore order). A correct
 * apply lands the tree back on `before`, sibling order included, which
 * the assertion checks as an ordered `defaultOrder()` list.
 *
 * The window holds identity fixed: only moves (parent + position) and
 * `title` edits happen between the two snapshots — no inserts or
 * deletes — so every row keeps its `id` and the diff keys on `id`
 * cleanly. Inserts arrive through the separate `graft` step, outside
 * any round-trip window.
 */
final class DiffRoundTripJourney extends Journey
{
    /**
     * Above this live-row count the `graft` step stops growing the tree.
     * A round-trip snapshots, diffs, and applies over the whole tree, so
     * an unbounded graft would make late shuffles quadratic for no extra
     * coverage.
     */
    private const int GRAFT_CAP = 30;

    /** @return list<Step> */
    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('plant root')
                ->act(function (): void {
                    (new Category(['name' => 'root', 'title' => 't0']))->saveAsRoot();
                })
                ->assert(fn () => Assert::assertSame(1, Category::query()->count())),

            Step::make('graft child')
                ->after('plant root')
                ->repeatable()
                ->weight(3)
                ->act(function (Context $ctx): void {
                    if (Category::query()->count() >= self::GRAFT_CAP) {
                        return;
                    }

                    $all = Category::query()->orderBy('id')->get()->all();
                    $parent = $all[$ctx->randomInt(0, count($all) - 1)];

                    (new Category(['name' => 'n', 'title' => 't'.$ctx->randomInt(0, 999)]))
                        ->appendToNode($parent)
                        ->save();
                }),

            // Lasting payload churn between round-trips: a round-trip both
            // edits and restores titles inside its own window, so this is
            // what actually moves a title from one round-trip to the next.
            Step::make('retitle')
                ->after('plant root')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $all = Category::query()->orderBy('id')->get()->all();
                    $node = $all[$ctx->randomInt(0, count($all) - 1)];

                    $node->title = 't'.$ctx->randomInt(0, 999);
                    $node->save();
                }),

            // The oracle step: churn the tree with moves + retitles, then
            // undo the churn with a directly-recomputed inverse diff and
            // assert the tree is exactly back where it started — sibling
            // order included, since both snapshots are in defaultOrder.
            Step::make('diff round-trip')
                ->after('graft child')
                ->repeatable()
                ->weight(2)
                ->act(function (Context $ctx): void {
                    if (Category::query()->count() < 2) {
                        return;
                    }

                    $before = $this->snapshot();

                    // A handful of moves + edits so the resulting diff
                    // carries several simultaneous moves, not just one.
                    $churn = $ctx->randomInt(1, 4);
                    for ($i = 0; $i < $churn; $i++) {
                        $this->churnOnce($ctx);
                    }

                    $after = $this->snapshot();

                    // Directly recompute the inverse (after -> before) so
                    // each Moved carries before's true sibling position;
                    // TreeDiff::invert() would drop it.
                    TreeDiff::between($after, $before, 'id')->apply(Category::class);

                    Assert::assertEquals(
                        $before,
                        $this->snapshot(),
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
            Invariant::make('Category structure intact', function (): void {
                Assert::assertSame(
                    [],
                    array_filter(Category::countErrors()),
                    'Tree corruption: '.json_encode(Category::countErrors()),
                );
            }),
        ];
    }

    /**
     * One structural or payload change inside a round-trip window: half
     * the time a subtree move, half the time a title edit.
     */
    private function churnOnce(Context $ctx): void
    {
        $all = Category::query()->orderBy('id')->get()->all();

        if ($ctx->pick([true, false])) {
            $node = $all[$ctx->randomInt(0, count($all) - 1)];
            $node->title = 't'.$ctx->randomInt(0, 999);
            $node->save();

            return;
        }

        $node = $all[$ctx->randomInt(0, count($all) - 1)];

        $targets = array_values(array_filter(
            $all,
            fn (Category $candidate): bool => $candidate->getKey() !== $node->getKey()
                && $candidate->getKey() !== $node->getParentId()
                && ($candidate->getLft() < $node->getLft() || $candidate->getRgt() > $node->getRgt()),
        ));

        if ($targets === []) {
            return;
        }

        $node->appendToNode($targets[$ctx->randomInt(0, count($targets) - 1)]->refresh())->save();
    }

    /**
     * A flat, identity-keyed snapshot in sibling (lft) order. Only the
     * columns the round-trip touches — `id`, `parent_id`, `title` — so
     * the diff is exactly the churn and nothing spurious (timestamps,
     * structural columns) leaks in.
     *
     * @return list<array{id: int, parent_id: int|null, title: string|null}>
     */
    private function snapshot(): array
    {
        /** @var list<array{id: int, parent_id: int|null, title: string|null}> $rows */
        $rows = [];
        foreach (Category::query()->defaultOrder()->get() as $node) {
            $rows[] = [
                'id' => $node->id,
                'parent_id' => $node->parent_id,
                'title' => $node->title,
            ];
        }

        return $rows;
    }
}
