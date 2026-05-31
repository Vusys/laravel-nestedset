<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Clone;

use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use LogicException;
use Vusys\NestedSet\Events\Subtree\SubtreeCloned;
use Vusys\NestedSet\Exceptions\InvalidCloneTargetException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Exceptions\UnplacedNodeException;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\Fixtures\Models\UuidMenu;
use Vusys\NestedSet\Tests\Fixtures\Models\UuidMenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Subtree cloning: `cloneSubtreeTo`, `cloneSubtreeAsRoot`, and the
 * static `cloneSubtree` convenience. Locks the design decisions
 * spelled out in SUBTREE_CLONE_DESIGN.md.
 *
 * Tree fixture (Category):
 *   root
 *     a
 *       a1
 *       a2
 *     b
 *   sibling
 */
final class CloneSubtreeTest extends TestCase
{
    use InteractsWithTrees;

    public function test_clone_leaf_produces_fresh_key_and_copies_attributes(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'src', 'title' => 'Source Title']);
        $source->appendToNode($root)->save();
        $source->refresh();

        $destination = new Category(['name' => 'dst']);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        $clone = $source->cloneSubtreeTo($destination);

        $this->assertInstanceOf(Category::class, $clone);
        $this->assertNotSame($source->getKey(), $clone->getKey());
        $this->assertSame('src', $clone->name);
        $this->assertSame('Source Title', $clone->title);
        $this->assertSame($destination->getKey(), $clone->parent_id);
        $this->assertIsLeaf($clone);
    }

    public function test_clone_subtree_copies_full_shape(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'a']);
        $source->appendToNode($root)->save();
        $source->refresh();

        $a1 = new Category(['name' => 'a1']);
        $a1->appendToNode($source)->save();

        $a2 = new Category(['name' => 'a2']);
        $a2->appendToNode($source)->save();

        $destination = new Category(['name' => 'dest']);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        $clone = $source->cloneSubtreeTo($destination);

        $this->assertSame('a', $clone->name);
        $this->assertSame($destination->getKey(), $clone->parent_id);

        $children = Category::query()
            ->where('parent_id', $clone->getKey())
            ->orderBy('lft')
            ->pluck('name')
            ->all();
        $this->assertSame(['a1', 'a2'], $children);

        // Source unchanged
        $source->refresh();
        $this->assertSame(2, Category::query()->where('parent_id', $source->getKey())->count());

        // 3 rows added (a, a1, a2 clones) → total before was 5, after 8.
        $this->assertSame(8, Category::query()->count());
        $this->assertTreeIsIntact(Category::class);
    }

    public function test_clone_subtree_as_root_creates_new_root_in_same_scope(): void
    {
        $menu = Menu::create(['name' => 'm']);
        $rootItem = new MenuItem(['name' => 'root', 'menu_id' => $menu->id]);
        $rootItem->saveAsRoot();
        $rootItem->refresh();

        $a = new MenuItem(['name' => 'a', 'menu_id' => $menu->id]);
        $a->appendToNode($rootItem)->save();

        $a1 = new MenuItem(['name' => 'a1', 'menu_id' => $menu->id]);
        $a1->appendToNode($a)->save();

        $clone = $rootItem->cloneSubtreeAsRoot();

        $this->assertNotSame($rootItem->getKey(), $clone->getKey());
        $this->assertNull($clone->parent_id);
        $this->assertSame(0, $clone->depth);
        $this->assertSame($menu->id, $clone->menu_id);

        $cloneDescendantCount = MenuItem::query()
            ->where('menu_id', $menu->id)
            ->where('lft', '>', $clone->lft)
            ->where('rgt', '<', $clone->rgt)
            ->count();
        $this->assertSame(2, $cloneDescendantCount);

        $this->assertSame(6, MenuItem::query()->where('menu_id', $menu->id)->count());
    }

    public function test_clone_as_root_position_first_places_at_forest_start(): void
    {
        $menu = Menu::create(['name' => 'm']);

        $existingRoot = new MenuItem(['name' => 'existing', 'menu_id' => $menu->id]);
        $existingRoot->saveAsRoot();
        $existingRoot->refresh();

        $source = new MenuItem(['name' => 'src', 'menu_id' => $menu->id]);
        $source->saveAsRoot();
        $source->refresh();

        $clone = $source->cloneSubtreeAsRoot(position: 'first');
        $clone->refresh();

        $rootsInOrder = MenuItem::query()
            ->where('menu_id', $menu->id)
            ->whereNull('parent_id')
            ->orderBy('lft')
            ->pluck('name')
            ->all();
        $this->assertSame(['src', 'existing', 'src'], $rootsInOrder);
        $this->assertSame(1, $clone->lft, 'clone landed at the start of the forest');
    }

    public function test_clone_into_own_subtree_throws(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $child = new Category(['name' => 'child']);
        $child->appendToNode($root)->save();
        $child->refresh();

        $this->expectException(InvalidCloneTargetException::class);
        $root->cloneSubtreeTo($child);
    }

    public function test_clone_into_self_throws(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $this->expectException(InvalidCloneTargetException::class);
        $root->cloneSubtreeTo($root);
    }

    public function test_cross_scope_clone_throws(): void
    {
        $menuA = Menu::create(['name' => 'A']);
        $menuB = Menu::create(['name' => 'B']);

        $srcRoot = new MenuItem(['name' => 'src', 'menu_id' => $menuA->id]);
        $srcRoot->saveAsRoot();
        $srcRoot->refresh();

        $dstRoot = new MenuItem(['name' => 'dst', 'menu_id' => $menuB->id]);
        $dstRoot->saveAsRoot();
        $dstRoot->refresh();

        $this->expectException(ScopeViolationException::class);
        $srcRoot->cloneSubtreeTo($dstRoot);
    }

    public function test_clone_uuid_keyed_model(): void
    {
        $menu = UuidMenu::create(['name' => 'm']);

        $source = new UuidMenuItem(['name' => 'src', 'menu_id' => $menu->id]);
        $source->saveAsRoot();
        $source->refresh();

        $a = new UuidMenuItem(['name' => 'a', 'menu_id' => $menu->id]);
        $a->appendToNode($source)->save();

        $destination = new UuidMenuItem(['name' => 'dst', 'menu_id' => $menu->id]);
        $destination->saveAsRoot();
        $destination->refresh();

        $clone = $source->cloneSubtreeTo($destination);

        $this->assertIsString($clone->getKey());
        $this->assertNotSame($source->getKey(), $clone->getKey());
        $this->assertSame($destination->getKey(), $clone->parent_id);

        $this->assertSame(1, UuidMenuItem::query()
            ->where('parent_id', $clone->getKey())
            ->count());
    }

    public function test_transform_rewrites_attributes(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'a', 'title' => 'A']);
        $source->appendToNode($root)->save();

        $child = new Category(['name' => 'a1', 'title' => 'A1']);
        $child->appendToNode($source)->save();

        $destination = new Category(['name' => 'dest']);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        $clone = $source->cloneSubtreeTo(
            $destination,
            transform: function (array $attributes, int $depth): array {
                $name = $attributes['name'];
                $attributes['name'] = 'lvl'.$depth.':'.(is_string($name) ? $name : '');

                return $attributes;
            },
        );

        $this->assertSame('lvl0:a', $clone->name);

        $childClone = Category::query()->where('parent_id', $clone->getKey())->firstOrFail();
        $this->assertSame('lvl1:a1', $childClone->name);
    }

    public function test_transform_setting_structural_column_throws(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'src']);
        $source->appendToNode($root)->save();
        $source->refresh();

        $destination = new Category(['name' => 'dst']);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        $this->expectException(LogicException::class);
        $source->cloneSubtreeTo(
            $destination,
            transform: fn (array $attributes, int $depth): array => [...$attributes, 'lft' => 999],
        );
    }

    public function test_transform_setting_scope_column_throws(): void
    {
        $menu = Menu::create(['name' => 'm']);
        $otherMenu = Menu::create(['name' => 'other']);

        $root = new MenuItem(['name' => 'root', 'menu_id' => $menu->id]);
        $root->saveAsRoot();
        $root->refresh();

        $source = new MenuItem(['name' => 'src', 'menu_id' => $menu->id]);
        $source->appendToNode($root)->save();
        $source->refresh();

        $destination = new MenuItem(['name' => 'dst', 'menu_id' => $menu->id]);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        $this->expectException(LogicException::class);
        $source->cloneSubtreeTo(
            $destination,
            transform: fn (array $attrs, int $depth): array => [...$attrs, 'menu_id' => $otherMenu->id],
        );
    }

    public function test_transform_exception_propagates_and_rolls_back(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'src']);
        $source->appendToNode($root)->save();

        $destination = new Category(['name' => 'dst']);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        $countBefore = Category::query()->count();

        try {
            $source->cloneSubtreeTo(
                $destination,
                transform: function (array $attrs, int $depth): array {
                    throw new \RuntimeException('boom');
                },
            );
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($countBefore, Category::query()->count(), 'transaction rolled back');
    }

    public function test_clone_emits_subtree_cloned_event_once(): void
    {
        Event::fake([SubtreeCloned::class]);

        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'src']);
        $source->appendToNode($root)->save();
        $source->refresh();

        $child = new Category(['name' => 'src_child']);
        $child->appendToNode($source)->save();

        $destination = new Category(['name' => 'dst']);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        $source->cloneSubtreeTo($destination);

        Event::assertDispatchedTimes(SubtreeCloned::class, 1);
        Event::assertDispatched(SubtreeCloned::class, fn (SubtreeCloned $e): bool => $e->rowCount === 2 && $e->includeTrashed === false && $e->modelClass === Category::class);
    }

    public function test_clone_suppresses_per_row_eloquent_events(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'src']);
        $source->appendToNode($root)->save();
        $source->refresh();

        $child = new Category(['name' => 'src_child']);
        $child->appendToNode($source)->save();

        $destination = new Category(['name' => 'dst']);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        // Reset event fake AFTER setup so only clone-emitted events
        // are counted.
        Event::fake();

        $source->cloneSubtreeTo($destination);

        Event::assertNotDispatched('eloquent.created: '.Category::class);
        Event::assertNotDispatched('eloquent.creating: '.Category::class);
    }

    public function test_clone_soft_deleted_source_rejected_by_default(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'src']);
        $source->appendToNode($root)->save();
        $source->refresh();
        $source->delete();
        $source->refresh();

        $destination = new Category(['name' => 'dst']);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        $this->expectException(InvalidArgumentException::class);
        $source->cloneSubtreeTo($destination);
    }

    public function test_clone_soft_deleted_source_allowed_with_include_trashed(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'src']);
        $source->appendToNode($root)->save();
        $source->refresh();
        $source->delete();
        $source->refresh();

        $destination = new Category(['name' => 'dst']);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        $clone = $source->cloneSubtreeTo($destination, includeTrashed: true);

        $this->assertNotNull($clone->getKey());
        $this->assertNull($clone->deleted_at, 'clones land as live rows even when source was trashed');
    }

    public function test_clone_skips_trashed_descendants_by_default(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'src']);
        $source->appendToNode($root)->save();
        $source->refresh();

        $liveChild = new Category(['name' => 'live']);
        $liveChild->appendToNode($source)->save();

        $trashedChild = new Category(['name' => 'trashed']);
        $trashedChild->appendToNode($source)->save();
        $trashedChild->refresh();
        $trashedChild->delete();

        $destination = new Category(['name' => 'dst']);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        $clone = $source->cloneSubtreeTo($destination);

        $cloneNames = Category::query()
            ->where('parent_id', $clone->getKey())
            ->orderBy('lft')
            ->pluck('name')
            ->all();
        $this->assertSame(['live'], $cloneNames, 'trashed descendant skipped by default');
    }

    public function test_clone_includes_trashed_descendants_when_requested(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'src']);
        $source->appendToNode($root)->save();
        $source->refresh();

        $liveChild = new Category(['name' => 'live']);
        $liveChild->appendToNode($source)->save();

        $trashedChild = new Category(['name' => 'trashed']);
        $trashedChild->appendToNode($source)->save();
        $trashedChild->refresh();
        $trashedChild->delete();

        $destination = new Category(['name' => 'dst']);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        $clone = $source->cloneSubtreeTo($destination, includeTrashed: true);

        $cloneChildren = Category::query()
            ->where('parent_id', $clone->getKey())
            ->orderBy('lft')
            ->get();
        $this->assertSame(['live', 'trashed'], $cloneChildren->pluck('name')->all());
        $trashedClone = $cloneChildren->get(1);
        $this->assertInstanceOf(Category::class, $trashedClone);
        $this->assertNull($trashedClone->deleted_at, 'cloned trashed descendant lands as live');
    }

    public function test_clone_into_trashed_destination_throws_regardless_of_include_trashed(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'src']);
        $source->appendToNode($root)->save();
        $source->refresh();

        $destination = new Category(['name' => 'dst']);
        $destination->appendToNode($root)->save();
        $destination->refresh();
        $destination->delete();
        $destination->refresh();

        $this->expectException(InvalidArgumentException::class);
        $source->cloneSubtreeTo($destination, includeTrashed: true);
    }

    public function test_aggregate_columns_recompute_on_clone(): void
    {
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Area(['name' => 'src', 'tickets' => 5]);
        $source->appendToNode($root)->save();
        $source->refresh();

        $child = new Area(['name' => 'src_child', 'tickets' => 3]);
        $child->appendToNode($source)->save();

        $destination = new Area(['name' => 'dst', 'tickets' => 0]);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        $clone = $source->cloneSubtreeTo($destination);
        $clone->refresh();

        $this->assertSame(8, $clone->tickets_total, 'clone subtree sums to 5 + 3 = 8');
        $this->assertSame(2, $clone->tickets_count_all);

        $source->refresh();
        $this->assertSame(8, $source->tickets_total, 'source unchanged');
    }

    public function test_clone_as_root_zeroes_then_recomputes_aggregates(): void
    {
        $root = new Area(['name' => 'root', 'tickets' => 0]);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Area(['name' => 'src', 'tickets' => 7]);
        $source->appendToNode($root)->save();
        $source->refresh();

        $this->assertSame(7, $source->tickets_total);

        $clone = $source->cloneSubtreeAsRoot();
        $clone->refresh();

        $this->assertSame(7, $clone->tickets_total, 'cloned root recomputes from descendants (none → its own tickets)');
        $this->assertSame(1, $clone->tickets_count_all);
    }

    public function test_replicate_parity_on_leaf(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'leaf', 'title' => 'Leaf']);
        $source->appendToNode($root)->save();
        $source->refresh();

        $clone = $source->cloneSubtreeTo($root);

        $this->assertSame($source->name, $clone->name);
        $this->assertSame($source->title, $clone->title);
        $this->assertNotSame($source->getKey(), $clone->getKey());
        $this->assertSame($root->getKey(), $clone->parent_id);
    }

    public function test_static_clone_subtree_helper_matches_instance_method(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'src']);
        $source->appendToNode($root)->save();
        $source->refresh();

        $destination = new Category(['name' => 'dst']);
        $destination->appendToNode($root)->save();
        $destination->refresh();

        $clone = Category::cloneSubtree($source, $destination);

        $this->assertSame('src', $clone->name);
        $this->assertSame($destination->getKey(), $clone->parent_id);
    }

    public function test_unplaced_source_throws(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $unplaced = new Category(['name' => 'unplaced']);
        // never saved

        $this->expectException(UnplacedNodeException::class);
        $unplaced->cloneSubtreeTo($root);
    }

    public function test_unplaced_destination_throws(): void
    {
        $root = new Category(['name' => 'root']);
        $root->saveAsRoot();
        $root->refresh();

        $source = new Category(['name' => 'src']);
        $source->appendToNode($root)->save();
        $source->refresh();

        $unplacedDest = new Category(['name' => 'unplaced']);
        // unsaved → no bounds

        $this->expectException(UnplacedNodeException::class);
        $source->cloneSubtreeTo($unplacedDest);
    }
}
