<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Runabout\Journeys;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Vusys\NestedSet\Query\TreeRepairBuilder;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: {@see Category::fixTree()} must rebuild `lft`/`rgt`/`depth`
 * purely from the `parent_id` walk, converging to the exact same tree
 * no matter what order structural mutations and raw-SQL corruption
 * arrive in.
 *
 * `parent_id` is the library's source of truth; `fixTree` rebuilds the
 * whole forest from it in a single id-ordered DFS
 * ({@see TreeRepairBuilder}). That has a
 * non-obvious consequence this journey pins down: the rebuild orders
 * siblings by **id**, so it is *lossy on sibling order* — a moved or
 * prepended node whose `lft` order no longer matches its `id` order is
 * renumbered back to id order. `fixTree` is therefore NOT a structural
 * no-op after a reorder; the correct oracle is an independent
 * reconstruction that mirrors the id-ordered walk.
 *
 * That reconstruction ({@see reconstruct()}) is the oracle: a
 * from-scratch DFS over `parent_id`, children sorted by id, assigning
 * `lft`/`rgt`/`depth` with no package repair code in the loop. After
 * every `fixTree` the stored columns must equal it byte-for-byte. The
 * `Corruption/` tests each cover one hand-built corruption; this shuffles
 * corruption patterns against real mutations and lets the shrinker find
 * the order that breaks convergence.
 *
 * Corruption only ever damages structural columns (`lft`/`rgt`/`depth`),
 * never `parent_id` or `id` — so the source of truth stays intact and a
 * clean rebuild is always reachable. The repair happens inside the step,
 * so the tree is valid again at every step boundary and the standing
 * structure invariant holds.
 */
final class RepairConvergenceJourney extends Journey
{
    /** Keeps recursion depth and rebuild cost bounded across a run. */
    private const int GRAFT_CAP = 40;

    /** @return list<Step> */
    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('plant root')
                ->act(function (): void {
                    (new Category(['name' => 'root', 'title' => 'root']))->saveAsRoot();
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

                    // Prepend as often as append: prepend puts a low-lft node
                    // that is a high id, deliberately desynchronising lft order
                    // from id order so a later fixTree has real renumbering to do.
                    $child = new Category(['name' => 'n', 'title' => 't']);
                    if ($ctx->pick([true, false])) {
                        $child->prependToNode($parent)->save();
                    } else {
                        $child->appendToNode($parent)->save();
                    }
                }),

            Step::make('move subtree')
                ->after('graft child')
                ->repeatable()
                ->weight(2)
                ->act(function (Context $ctx): void {
                    $all = Category::query()->orderBy('id')->get()->all();
                    if (count($all) < 2) {
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
                }),

            // Reorder siblings: leaves the tree valid but with lft order out
            // of step with id order, the exact state fixTree canonicalises.
            Step::make('shuffle siblings')
                ->after('graft child')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $all = Category::query()->orderBy('id')->get()->all();
                    $node = $all[$ctx->randomInt(0, count($all) - 1)];

                    if ($ctx->pick([true, false])) {
                        $node->up();
                    } else {
                        $node->down();
                    }
                }),

            // A clean rebuild on a valid (but reordered) tree: fixTree must
            // reproduce the id-ordered reconstruction exactly, and a second
            // call must then be a true no-op (idempotence).
            Step::make('rebuild clean')
                ->after('graft child')
                ->repeatable()
                ->weight(2)
                ->act(function (): void {
                    Category::fixTree();
                    $this->assertMatchesReconstruction('after a clean rebuild');

                    $canonical = $this->structure();
                    Category::fixTree();
                    Assert::assertSame(
                        $canonical,
                        $this->structure(),
                        'fixTree was not idempotent: a second call changed a canonical tree.',
                    );
                }),

            // Corrupt structural columns via raw SQL, then repair. fixTree
            // must detect the damage and rebuild the exact same tree from
            // parent_id, losing no rows and touching no parent pointer.
            Step::make('corrupt and repair')
                ->after('graft child')
                ->repeatable()
                ->weight(2)
                ->act(function (Context $ctx): void {
                    $beforeParents = $this->parentMap();
                    $beforeCount = count($beforeParents);

                    $this->corrupt($ctx);

                    Assert::assertGreaterThan(
                        0,
                        array_sum(Category::countErrors()),
                        'Raw-SQL corruption was not detected by countErrors().',
                    );

                    Category::fixTree();

                    Assert::assertSame(
                        [],
                        array_filter(Category::countErrors()),
                        'fixTree() left the tree broken: '.json_encode(Category::countErrors()),
                    );
                    $this->assertMatchesReconstruction('after repairing corruption');
                    Assert::assertSame(
                        $beforeParents,
                        $this->parentMap(),
                        'Repair changed a parent_id or lost/added a row.',
                    );
                    Assert::assertSame($beforeCount, Category::query()->count());
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
     * Damage structural columns on one random node, leaving `id` and
     * `parent_id` (the source of truth) untouched so a full rebuild can
     * always recover. Every pattern is guaranteed detectable by
     * countErrors().
     */
    private function corrupt(Context $ctx): void
    {
        $table = (new Category)->getTable();
        $all = Category::query()->orderBy('id')->get()->all();
        $node = $all[$ctx->randomInt(0, count($all) - 1)];

        $update = match ($ctx->pick(['invalid', 'collapse', 'shift', 'depth'])) {
            'invalid' => ['lft' => $node->rgt + 1],       // lft > rgt
            'collapse' => ['rgt' => $node->lft],           // lft == rgt (even width / lft >= rgt)
            'shift' => ['lft' => $node->lft + 1000, 'rgt' => $node->rgt + 1000],
            default => ['depth' => $node->depth + 5],
        };

        DB::table($table)->where('id', $node->id)->update($update);
    }

    /**
     * Expected `lft`/`rgt`/`depth` for every node, rebuilt from scratch:
     * DFS over `parent_id` with children in ascending `id` order and one
     * continuous 1..2N counter — exactly what {@see Category::fixTree()}
     * produces, computed with no package repair code.
     *
     * @return array<int, array{lft: int, rgt: int, depth: int}>
     */
    private function reconstruct(): array
    {
        /** @var array<int, list<int>> $children indexed by parent id; 0 == root */
        $children = [];
        foreach (Category::query()->get() as $node) {
            $children[$node->parent_id ?? 0][] = $node->id;
        }
        foreach ($children as $parentId => $_) {
            sort($children[$parentId]);
        }

        /** @var array<int, array{lft: int, rgt: int, depth: int}> $result */
        $result = [];
        $counter = 1;
        foreach ($children[0] ?? [] as $rootId) {
            $this->assignPositions($rootId, 0, $counter, $children, $result);
        }

        return $result;
    }

    /**
     * @param  array<int, list<int>>  $children
     * @param  array<int, array{lft: int, rgt: int, depth: int}>  $result
     */
    private function assignPositions(int $id, int $depth, int &$counter, array $children, array &$result): void
    {
        $lft = $counter++;
        foreach ($children[$id] ?? [] as $childId) {
            $this->assignPositions($childId, $depth + 1, $counter, $children, $result);
        }
        $result[$id] = ['lft' => $lft, 'rgt' => $counter++, 'depth' => $depth];
    }

    private function assertMatchesReconstruction(string $context): void
    {
        Assert::assertEquals(
            $this->reconstruct(),
            $this->structure(),
            "fixTree structure diverged from an independent parent_id rebuild {$context}.",
        );
    }

    /**
     * The tree's stored structural columns, keyed by id.
     *
     * @return array<int, array{lft: int, rgt: int, depth: int}>
     */
    private function structure(): array
    {
        /** @var array<int, array{lft: int, rgt: int, depth: int}> $rows */
        $rows = [];
        foreach (Category::query()->get() as $node) {
            $rows[$node->id] = ['lft' => $node->lft, 'rgt' => $node->rgt, 'depth' => $node->depth];
        }
        ksort($rows);

        return $rows;
    }

    /**
     * @return array<int, int|null>
     */
    private function parentMap(): array
    {
        /** @var array<int, int|null> $map */
        $map = [];
        foreach (Category::query()->get() as $node) {
            $map[$node->id] = $node->parent_id;
        }
        ksort($map);

        return $map;
    }
}
