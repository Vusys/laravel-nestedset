<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Runabout\Journeys;

use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Vusys\NestedSet\Tests\Feature\Fuzzers\MaterialisedPathFuzzerTest;
use Vusys\NestedSet\Tests\Fixtures\Models\SluggedCategory;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: the maintained slug path column on {@see SluggedCategory}
 * (`#[NestedSetMaterialisedPath(column: 'url_path', slug: 'name')]`,
 * shape `/root/branch/leaf/`) must stay coherent no matter what order
 * subtree moves and renames arrive in.
 *
 * Two mutations cascade into a node's *descendants*, and their
 * interaction is the order-dependent bug class:
 *   - RENAME rewrites the renamed node's own segment and must push the
 *     new segment down to every descendant path.
 *   - MOVE re-parents a subtree and must rewrite the *prefix* of every
 *     node inside it to the new ancestor chain.
 * Rename an ancestor, then move the subtree under a differently-named
 * parent (or the reverse) and a maintenance path that rebuilds only one
 * half leaves stale segments behind.
 *
 * The existing {@see MaterialisedPathFuzzerTest}
 * only ever moves *leaves* — this journey moves whole subtrees (internal
 * nodes carrying descendants), which is where the prefix rewrite has to
 * touch more than the moved row itself.
 *
 * Two independent oracles guard the column so a shared blind spot can't
 * hide: {@see SluggedCategory::fixMaterialisedPaths()} (the package's own
 * full rebuild — an all-zero drift count means maintenance kept every
 * path coherent) and a from-scratch reconstruction that walks `parent_id`
 * and slugs `name` with no package path code in the loop. Every node's
 * name is unique tree-wide (a monotonic counter), so per-parent slug
 * disambiguation never fires and the reconstruction is exact.
 */
final class SluggedPathJourney extends Journey
{
    /** @return list<Step> */
    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('plant root')
                ->act(function (Context $ctx): void {
                    (new SluggedCategory(['name' => $this->nextName($ctx)]))->saveAsRoot();
                })
                ->assert(fn () => Assert::assertSame(1, SluggedCategory::query()->count())),

            Step::make('graft')
                ->after('plant root')
                ->repeatable()
                ->weight(3)
                ->act(function (Context $ctx): void {
                    $all = SluggedCategory::query()->orderBy('id')->get()->all();
                    $parent = $all[$ctx->randomInt(0, count($all) - 1)];

                    $child = new SluggedCategory(['name' => $this->nextName($ctx)]);

                    // Prepend as well as append: the head-of-siblings insert
                    // is a distinct maintenance path, and sibling order never
                    // changes a slug path, so both must land the same value.
                    if ($ctx->pick([true, false])) {
                        $child->prependToNode($parent)->save();
                    } else {
                        $child->appendToNode($parent)->save();
                    }
                }),

            // Rename any node, root included. Renaming the root cascades the
            // new segment into the prefix of every path in the tree.
            Step::make('rename')
                ->after('plant root')
                ->repeatable()
                ->weight(2)
                ->act(function (Context $ctx): void {
                    $all = SluggedCategory::query()->orderBy('id')->get()->all();
                    $node = $all[$ctx->randomInt(0, count($all) - 1)];

                    $node->name = $this->nextName($ctx);
                    $node->save();
                }),

            // Move a whole subtree — deliberately NOT restricted to leaves.
            // The moved node may carry descendants whose prefixes all have
            // to be rewritten to the target's chain.
            Step::make('move subtree')
                ->after('graft')
                ->repeatable()
                ->weight(2)
                ->act(function (Context $ctx): void {
                    $all = SluggedCategory::query()->orderBy('id')->get()->all();
                    if (count($all) < 2) {
                        return;
                    }

                    $node = $all[$ctx->randomInt(0, count($all) - 1)];

                    // A node may only move under something outside its own
                    // subtree, and moving under its current parent is a no-op
                    // worth skipping so the step does real work.
                    $targets = array_values(array_filter(
                        $all,
                        fn (SluggedCategory $candidate): bool => $candidate->getKey() !== $node->getKey()
                            && $candidate->getKey() !== $node->getParentId()
                            && ($candidate->getLft() < $node->getLft() || $candidate->getRgt() > $node->getRgt()),
                    ));

                    if ($targets === []) {
                        return;
                    }

                    $target = $targets[$ctx->randomInt(0, count($targets) - 1)];
                    $node->appendToNode($target->refresh())->save();
                }),

            // Sibling reorder: never changes a path, but churns lft/rgt and
            // re-fires path maintenance, so it must leave every path fixed.
            Step::make('reorder siblings')
                ->after('graft')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $all = SluggedCategory::query()->orderBy('id')->get()->all();
                    $parent = $all[$ctx->randomInt(0, count($all) - 1)];

                    $parent->reorderChildrenBy('name');
                }),
        ];
    }

    /** @return list<Invariant> */
    #[\Override]
    public function invariants(): array
    {
        return [
            Invariant::make('SluggedCategory structure intact', function (): void {
                Assert::assertFalse(SluggedCategory::isBroken(), 'SluggedCategory tree is broken.');
            }),

            // The package's own rebuild oracle: fixMaterialisedPaths() writes
            // each column back to its freshly recomputed value and returns the
            // number of rows that had drifted. All-zero => incremental
            // maintenance already agreed with a full rebuild.
            Invariant::make('SluggedCategory paths match a full rebuild', function (): void {
                $drift = SluggedCategory::fixMaterialisedPaths();

                Assert::assertSame(
                    0,
                    array_sum($drift),
                    'Materialised-path drift from a full rebuild: '.json_encode($drift),
                );
            }),

            // Independent of every package path routine: rebuild each expected
            // path straight from the source of truth (parent_id chain + slug of
            // name). Guards the blind spot where maintenance and the package
            // rebuild could be wrong in the same way.
            Invariant::make('SluggedCategory paths match a parent_id reconstruction', function (): void {
                /** @var array<int, SluggedCategory> $byId */
                $byId = [];
                foreach (SluggedCategory::query()->get() as $row) {
                    $byId[$row->id] = $row;
                }

                foreach ($byId as $node) {
                    Assert::assertSame(
                        $this->reconstructPath($node, $byId),
                        $node->url_path,
                        sprintf('SluggedCategory %d has a stale url_path.', $node->id),
                    );
                }
            }),
        ];
    }

    /**
     * The expected `/a/b/c/` path built only from `parent_id` and `name`:
     * walk to the root, slug each name, wrap in separators. Names are unique
     * tree-wide (see {@see nextName}), so this needs no per-parent
     * disambiguation and matches the stored column exactly.
     *
     * @param  array<int, SluggedCategory>  $byId
     */
    private function reconstructPath(SluggedCategory $node, array $byId): string
    {
        $segments = [];
        $cursor = $node;

        while (true) {
            $segments[] = Str::slug((string) $cursor->name);

            $parentId = $cursor->getParentId();
            if ($parentId === null || ! isset($byId[$parentId])) {
                break;
            }

            $cursor = $byId[$parentId];
        }

        return '/'.implode('/', array_reverse($segments)).'/';
    }

    /**
     * A tree-wide-unique node name. A monotonic counter kept in the trail
     * context guarantees no two live nodes ever share a slug, which is what
     * lets {@see reconstructPath} skip the package's per-parent
     * disambiguation and still match byte-for-byte.
     */
    private function nextName(Context $ctx): string
    {
        $seq = $ctx->has('name seq') ? $ctx->integer('name seq') + 1 : 1;
        $ctx->remember('name seq', $seq);

        return 'n'.$seq;
    }
}
