<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Runabout\Journeys;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Vusys\NestedSet\Export\JsonOptions;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * Journey: a `toJsonTreeForest()` export, a full wipe, and a
 * `fromJsonTree()` re-import must reproduce the tree byte-for-byte —
 * structure, sibling order, and payload — no matter what order the tree
 * was built and re-imported in.
 *
 * Import runs through `bulkInsertTree`, which regenerates primary keys
 * and lft/rgt/depth and defers one aggregate recompute to the end. So a
 * round-trip proves three things at once: the serialiser captures the
 * full shape (nested `children` = sibling order), the importer rebuilds
 * parent wiring and structural columns from that nesting, and the
 * deferred aggregate pass repopulates the (un-exported) aggregate
 * columns from the imported source columns.
 *
 * The order-dependent surface: the tree is grown, moved, and promoted to
 * a multi-root forest across shuffled steps, so each round-trip exports a
 * differently-shaped tree, and successive round-trips import from an
 * already-imported (id-regenerated) tree. A serialiser or importer that
 * leaned on insertion order, id order, or single-root assumptions drifts.
 *
 * The oracle is an independent by-name description ({@see describe()}):
 * (name, parent's name, tickets, depth) in `defaultOrder`, rebuilt with
 * no package export/import code in the loop. Names are tree-wide unique
 * (a counter), so identity survives the id regeneration and the two
 * descriptions compare exactly. Two standing invariants add structure
 * ({@see Area::countErrors()}) and the deferred-aggregate correctness
 * ({@see Area::aggregateErrors()}).
 */
final class JsonRoundTripJourney extends Journey
{
    /** Each round-trip serialises the whole forest, so bound its size. */
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

                    // Prepend as well as append so sibling order is not just
                    // insertion order — the round-trip must carry it faithfully.
                    $child = new Area(['name' => $this->nextName($ctx), 'tickets' => $ctx->randomInt(0, 20)]);
                    if ($ctx->pick([true, false])) {
                        $child->prependToNode($parent)->save();
                    } else {
                        $child->appendToNode($parent)->save();
                    }
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

            Step::make('move subtree')
                ->after('graft child')
                ->repeatable()
                ->weight(2)
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

            // Promote a subtree to its own root so the export is a real
            // forest, exercising toJsonTreeForest's multi-root path and the
            // importer's root-seeding (each new root at maxRgt + 1).
            Step::make('promote to root')
                ->after('graft child')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $nonRoots = Area::query()->whereNotNull('parent_id')->orderBy('id')->get()->all();
                    if ($nonRoots === []) {
                        return;
                    }

                    $nonRoots[$ctx->randomInt(0, count($nonRoots) - 1)]->makeRoot()->save();
                }),

            // The oracle step: export the whole forest, wipe every row, and
            // re-import. The tree must come back identical.
            Step::make('json round-trip')
                ->after('graft child')
                ->repeatable()
                ->weight(2)
                ->act(function (): void {
                    $before = $this->describe(Area::query()->defaultOrder()->get());

                    $json = Area::toJsonTreeForest(new JsonOptions(extras: ['name', 'tickets']));

                    // bulkInsertTree only inserts; the caller wipes. A raw
                    // table delete avoids per-model tree bookkeeping on rows
                    // that are all about to disappear anyway.
                    DB::table((new Area)->getTable())->delete();

                    Area::fromJsonTree($json);

                    Assert::assertEquals(
                        $before,
                        $this->describe(Area::query()->defaultOrder()->get()),
                        'JSON round-trip did not reproduce the tree.',
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

            // Import repopulates aggregate columns via one deferred pass;
            // after a round-trip the stored aggregates must match a fresh
            // recompute from the imported source columns.
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
     * An identity-keyed description in sibling (lft) order: each node as
     * (name, parent's name, tickets, depth). Comparing two of these
     * asserts structure, sibling order, payload, and depth independent of
     * the primary keys, which the importer regenerates.
     *
     * @param  Collection<int, Area>  $models
     * @return list<array{name: string, parent: string|null, tickets: int, depth: int}>
     */
    private function describe(Collection $models): array
    {
        $nameById = [];
        foreach ($models as $model) {
            $nameById[$model->id] = $model->name;
        }

        /** @var list<array{name: string, parent: string|null, tickets: int, depth: int}> $out */
        $out = [];
        foreach ($models as $model) {
            $out[] = [
                'name' => $model->name,
                'parent' => $model->parent_id === null ? null : ($nameById[$model->parent_id] ?? null),
                'tickets' => $model->tickets,
                'depth' => $model->depth,
            ];
        }

        return $out;
    }

    /**
     * A tree-wide-unique node name. A monotonic counter in the trail
     * context guarantees distinct names, so the by-name oracle survives
     * the primary-key regeneration a JSON import performs.
     */
    private function nextName(Context $ctx): string
    {
        $seq = $ctx->has('name seq') ? $ctx->integer('name seq') + 1 : 1;
        $ctx->remember('name seq', $seq);

        return 'a'.$seq;
    }
}
