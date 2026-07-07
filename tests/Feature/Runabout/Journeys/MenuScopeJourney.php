<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Runabout\Journeys;

use PHPUnit\Framework\Assert;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

/**
 * A single scoped ({@see MenuItem}, `#[NestedSetScope('menu_id')]`)
 * tree, built to be interleaved with a second instance of itself.
 *
 * Each instance owns one menu. Runabout's {@see interleave()} merge-
 * shuffles two instances into one trail while running every instance's
 * invariants after every step of *any* instance — so this journey's
 * isolation invariant polices the other menu's writes for free. A move
 * in menu B that forgets its scope in the `CASE WHEN` update shows up
 * as menu A's bounds no longer forming a clean `1..2N` permutation.
 */
final class MenuScopeJourney extends Journey
{
    public function __construct(private readonly string $label) {}

    /** @return list<Step> */
    #[\Override]
    public function steps(): array
    {
        return [
            Step::make('found menu')
                ->act(function (Context $ctx): void {
                    $menu = Menu::query()->create(['name' => $this->label]);
                    $ctx->remember('menu id', (int) $menu->id);

                    (new MenuItem(['name' => $this->label.' root', 'menu_id' => $menu->id]))
                        ->saveAsRoot();
                }),

            Step::make('add item')
                ->after('found menu')
                ->repeatable()
                ->weight(3)
                ->act(function (Context $ctx): void {
                    $menuId = $ctx->integer('menu id');
                    $all = $this->itemsIn($menuId);

                    $parent = $all[$ctx->randomInt(0, count($all) - 1)];

                    (new MenuItem(['name' => 's', 'menu_id' => $menuId]))
                        ->appendToNode($parent)
                        ->save();
                }),

            Step::make('move item')
                ->after('add item')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $all = $this->itemsIn($ctx->integer('menu id'));
                    if (count($all) < 2) {
                        return;
                    }

                    $node = $all[$ctx->randomInt(0, count($all) - 1)];

                    $targets = array_values(array_filter(
                        $all,
                        fn (MenuItem $candidate): bool => $candidate->getKey() !== $node->getKey()
                            && ($candidate->getLft() < $node->getLft() || $candidate->getRgt() > $node->getRgt()),
                    ));

                    if ($targets === []) {
                        return;
                    }

                    $node->appendToNode($targets[$ctx->randomInt(0, count($targets) - 1)])->save();
                }),

            Step::make('remove item')
                ->after('add item')
                ->repeatable()
                ->act(function (Context $ctx): void {
                    $all = $this->itemsIn($ctx->integer('menu id'));
                    if (count($all) <= 1) {
                        return;
                    }

                    $leaves = array_values(array_filter(
                        $all,
                        fn (MenuItem $node): bool => $node->getRgt() === $node->getLft() + 1,
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
            Invariant::make(sprintf('menu %s: intact and isolated', $this->label), function (Context $ctx): void {
                if (! $ctx->has('menu id')) {
                    return;
                }

                $menuId = $ctx->integer('menu id');
                $rows = MenuItem::query()->where('menu_id', $menuId)->orderBy('lft')->get();
                if ($rows->isEmpty()) {
                    return;
                }

                $anchor = $rows->first();
                Assert::assertSame(
                    [],
                    array_filter(MenuItem::countErrors($anchor)),
                    sprintf('menu %d corrupted: %s', $menuId, json_encode(MenuItem::countErrors($anchor))),
                );

                // A clean scope holds exactly {1..2N} across its lft/rgt.
                // A uniform shift or an overlap leaked in from the other
                // menu's writes breaks this even when the local structure
                // still looks internally valid.
                $bounds = [];
                foreach ($rows as $row) {
                    $bounds[] = $row->getLft();
                    $bounds[] = $row->getRgt();
                }
                sort($bounds);

                Assert::assertSame(
                    range(1, $rows->count() * 2),
                    $bounds,
                    sprintf('menu %d bounds are not a clean 1..2N permutation — cross-scope leak?', $menuId),
                );
            }),
        ];
    }

    /**
     * @return list<MenuItem>
     */
    private function itemsIn(int $menuId): array
    {
        return array_values(MenuItem::query()->where('menu_id', $menuId)->orderBy('id')->get()->all());
    }
}
