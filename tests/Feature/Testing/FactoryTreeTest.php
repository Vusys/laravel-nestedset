<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Testing;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\Sequence;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Area;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\Fixtures\Models\ScopedArea;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Round-trip coverage for `BuildsNestedSetTrees`. The trait wraps
 * `bulkInsertTree` so the test fixtures here also exercise the full
 * insert path (gap-open, bulk write, deferred aggregate recompute).
 */
final class FactoryTreeTest extends TestCase
{
    use InteractsWithTrees;

    // ----------------------------------------------------------------
    // Uniform tree shapes
    // ----------------------------------------------------------------

    #[Test]
    public function uniform_tree_produces_expected_node_count(): void
    {
        $root = Category::factory()->tree(depth: 3, branching: 2)->create();

        $this->assertInstanceOf(Category::class, $root);
        $this->assertSame(15, Category::query()->count());
        $this->assertTreeIsIntact(Category::class);
    }

    #[Test]
    public function variable_branching_array_produces_per_depth_counts(): void
    {
        $root = Category::factory()->tree(depth: 3, branching: [5, 2, 1])->create();

        $this->assertInstanceOf(Category::class, $root);
        $this->assertSame(26, Category::query()->count());

        $directChildren = Category::query()->where('parent_id', $root->id)->count();
        $this->assertSame(5, $directChildren);
        $this->assertTreeIsIntact(Category::class);
    }

    #[Test]
    public function variable_branching_closure_invoked_per_parent_depth(): void
    {
        $seenDepths = [];
        $root = Category::factory()
            ->tree(
                depth: 2,
                branching: function (int $parentDepth) use (&$seenDepths): int {
                    $seenDepths[] = $parentDepth;

                    return $parentDepth === 0 ? 3 : 1;
                },
            )
            ->create();

        $this->assertInstanceOf(Category::class, $root);
        $this->assertSame(7, Category::query()->count());
        $this->assertContains(0, $seenDepths);
        $this->assertContains(1, $seenDepths);
        $this->assertTreeIsIntact(Category::class);
    }

    #[Test]
    public function star_shape_one_root_n_siblings(): void
    {
        Category::factory()->tree(depth: 1, branching: 5)->create();

        $this->assertSame(6, Category::query()->count());
        $this->assertSame(1, Category::query()->whereNull('parent_id')->count());
        $this->assertTreeIsIntact(Category::class);
    }

    #[Test]
    public function spine_shape_single_chain(): void
    {
        Category::factory()->tree(depth: 4, branching: 1)->create();

        $this->assertSame(5, Category::query()->count());
        $depths = Category::query()->pluck('depth')->sort()->values()->all();
        $this->assertSame([0, 1, 2, 3, 4], $depths);
        $this->assertTreeIsIntact(Category::class);
    }

    #[Test]
    public function single_root_shape(): void
    {
        $root = Category::factory()->tree(depth: 0, branching: 0)->create();

        $this->assertInstanceOf(Category::class, $root);
        $this->assertSame(1, Category::query()->count());
        $this->assertIsRoot($root);
        $this->assertIsLeaf($root);
    }

    // ----------------------------------------------------------------
    // Explicit shape
    // ----------------------------------------------------------------

    #[Test]
    public function tree_from_shape_with_explicit_attributes(): void
    {
        $roots = Category::factory()->treeFromShape([
            ['name' => 'Electronics', 'children' => [
                ['name' => 'Laptops', 'children' => [
                    ['name' => 'MacBook'],
                    ['name' => 'ThinkPad'],
                ]],
                ['name' => 'Phones'],
            ]],
            ['name' => 'Gadgets'],
        ])->create();

        $this->assertInstanceOf(EloquentCollection::class, $roots);
        $this->assertCount(2, $roots);
        $this->assertSame(['Electronics', 'Gadgets'], $roots->pluck('name')->all());
        $this->assertSame(6, Category::query()->count());

        $electronics = Category::query()->where('name', 'Electronics')->firstOrFail();
        $this->assertHasDescendants($electronics, 4);
        $this->assertTreeIsIntact(Category::class);
    }

    #[Test]
    public function tree_from_shape_under_existing_parent_grafts_subtree(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->saveAsRoot();
        $root->refresh();

        $result = Category::factory()->treeFromShape([
            ['name' => 'Graft A'],
            ['name' => 'Graft B'],
        ], parent: $root)->create();

        $this->assertInstanceOf(EloquentCollection::class, $result);
        $this->assertCount(2, $result);
        foreach ($result as $node) {
            $this->assertInstanceOf(Category::class, $node);
            $this->assertIsChildOf($node, $root->refresh());
        }
    }

    #[Test]
    public function explicit_shape_with_single_top_level_entry_returns_model(): void
    {
        $root = Category::factory()->treeFromShape([
            ['name' => 'Solo', 'children' => [
                ['name' => 'Child'],
            ]],
        ])->create();

        $this->assertInstanceOf(Category::class, $root);
        $this->assertSame('Solo', $root->name);
        $this->assertSame(2, Category::query()->count());
    }

    #[Test]
    public function empty_shape_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Category::factory()->treeFromShape([])->create();
    }

    // ----------------------------------------------------------------
    // Argument validation
    // ----------------------------------------------------------------

    #[Test]
    public function negative_depth_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/depth must be >= 0/');
        Category::factory()->tree(depth: -1, branching: 1);
    }

    #[Test]
    public function int_branching_zero_with_positive_depth_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/branching must be >= 1/');
        Category::factory()->tree(depth: 2, branching: 0);
    }

    #[Test]
    public function branching_array_shorter_than_depth_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/branching array length \(2\) is less than depth \(3\)/');
        Category::factory()->tree(depth: 3, branching: [2, 2]);
    }

    #[Test]
    public function branching_array_zero_entry_with_deeper_levels_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/branching\[0\] is 0/');
        Category::factory()->tree(depth: 2, branching: [0, 2]);
    }

    // ----------------------------------------------------------------
    // create() / make() passthrough
    // ----------------------------------------------------------------

    #[Test]
    public function create_without_tree_shape_delegates_to_parent(): void
    {
        $row = Category::factory()
            ->afterMaking(fn (Category $c): Category => $c->makeRoot())
            ->create();

        $this->assertInstanceOf(Category::class, $row);
        $this->assertSame(1, Category::query()->count());
        $this->assertNotSame('', $row->name);
        $this->assertNull($row->parent_id);
    }

    #[Test]
    public function create_with_attributes_under_tree_shape_applies_state_first(): void
    {
        $root = Category::factory()
            ->tree(depth: 0, branching: 0, labelColumn: null)
            ->create(['name' => 'Stamped']);

        $this->assertInstanceOf(Category::class, $root);
        $this->assertSame('Stamped', $root->name);
        $this->assertSame(1, Category::query()->count());
    }

    #[Test]
    public function make_without_tree_shape_delegates_to_parent(): void
    {
        $row = Category::factory()->make();

        $this->assertInstanceOf(Category::class, $row);
        $this->assertFalse($row->exists, 'make() must not persist when no tree shape is queued.');
        $this->assertSame(0, Category::query()->count());
    }

    #[Test]
    public function preview_tree_without_queued_shape_throws(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/call tree\(\) or treeFromShape\(\) first/');
        Category::factory()->previewTree();
    }

    #[Test]
    public function count_zero_returns_empty_collection(): void
    {
        $result = Category::factory()->count(0)->tree(depth: 1, branching: 2)->create();

        $this->assertInstanceOf(EloquentCollection::class, $result);
        $this->assertCount(0, $result);
        $this->assertSame(0, Category::query()->count());
    }

    // ----------------------------------------------------------------
    // Labels
    // ----------------------------------------------------------------

    #[Test]
    public function default_label_writes_depth_and_sibling_to_name(): void
    {
        Category::factory()->tree(depth: 1, branching: 2)->create();

        $names = Category::query()->orderBy('lft')->pluck('name')->all();
        $this->assertSame(
            ['Depth 0 Sibling 0', 'Depth 1 Sibling 0', 'Depth 1 Sibling 1'],
            $names,
        );
    }

    #[Test]
    public function label_column_override_writes_to_alternative_column(): void
    {
        Category::factory()->tree(depth: 1, branching: 1, labelColumn: 'title')->create();

        $rows = Category::query()->orderBy('lft')->get(['name', 'title']);
        $titles = $rows->pluck('title')->all();
        $this->assertSame(['Depth 0 Sibling 0', 'Depth 1 Sibling 0'], $titles);

        foreach ($rows as $row) {
            $this->assertNotSame('', $row->name, 'definition() still supplies the name when labels go elsewhere.');
        }
    }

    #[Test]
    public function label_column_null_defers_entirely_to_definition(): void
    {
        Category::factory()->tree(depth: 0, branching: 0, labelColumn: null)->create();

        $name = Category::query()->value('name');
        $this->assertIsString($name);
        $this->assertStringNotContainsString('Depth ', $name);
    }

    #[Test]
    public function missing_label_column_rejected_upfront(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no_such_column/');

        Category::factory()->tree(depth: 0, branching: 0, labelColumn: 'no_such_column')->create();
    }

    // ----------------------------------------------------------------
    // Per-row closure
    // ----------------------------------------------------------------

    #[Test]
    public function per_row_closure_receives_depth_sibling_index_and_parent_attrs(): void
    {
        $observed = [];
        Category::factory()->tree(
            depth: 2,
            branching: 2,
            per: function (int $depth, int $siblingIndex, ?array $parentAttrs) use (&$observed): array {
                $observed[] = [$depth, $siblingIndex, $parentAttrs !== null];

                return [];
            },
        )->create();

        $this->assertContains([0, 0, false], $observed, 'Root receives null parent attrs.');
        $this->assertContains([1, 0, true], $observed);
        $this->assertContains([2, 1, true], $observed);
    }

    #[Test]
    public function per_row_closure_can_propagate_parent_attribute_down_tree(): void
    {
        Category::factory()->tree(
            depth: 2,
            branching: 1,
            labelColumn: null,
            per: function (int $depth, int $siblingIndex, ?array $parentAttrs): array {
                if ($depth === 0) {
                    return ['name' => 'rootdomain'];
                }

                return ['name' => $parentAttrs['name'] ?? 'missing'];
            },
        )->create();

        $names = Category::query()->orderBy('lft')->pluck('name')->all();
        $this->assertSame(['rootdomain', 'rootdomain', 'rootdomain'], $names);
    }

    #[Test]
    public function per_row_closure_does_not_receive_primary_key(): void
    {
        $sawPrimary = false;
        Category::factory()->tree(
            depth: 1,
            branching: 1,
            per: function (int $depth, int $siblingIndex, ?array $parentAttrs) use (&$sawPrimary): array {
                if ($parentAttrs !== null && array_key_exists('id', $parentAttrs)) {
                    $sawPrimary = true;
                }

                return [];
            },
        )->create();

        $this->assertFalse($sawPrimary, 'Parent attrs must not expose the primary key — rows have not been inserted yet.');
    }

    #[Test]
    public function per_row_closure_returning_non_array_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Category::factory()->tree(
            depth: 0,
            branching: 0,
            per: fn (): string => 'not an array',
        )->create();
    }

    // ----------------------------------------------------------------
    // count() composition
    // ----------------------------------------------------------------

    #[Test]
    public function count_then_tree_produces_independent_trees(): void
    {
        $result = Category::factory()->count(3)->tree(depth: 1, branching: 2)->create();

        $this->assertInstanceOf(EloquentCollection::class, $result);
        $this->assertCount(3, $result);
        $this->assertSame(9, Category::query()->count(), 'Three independent 3-node trees = 9 total.');
        $this->assertSame(3, Category::query()->whereNull('parent_id')->count());
        $this->assertTreeIsIntact(Category::class);
    }

    #[Test]
    public function sibling_index_resets_per_count_iteration(): void
    {
        $perRowSeen = [];
        Category::factory()->count(2)->tree(
            depth: 1,
            branching: 2,
            per: function (int $depth, int $siblingIndex) use (&$perRowSeen): array {
                $perRowSeen[] = $siblingIndex;

                return [];
            },
        )->create();

        $depthOneIndices = array_values(array_filter($perRowSeen, fn (int $i): bool => $i === 1));
        $this->assertCount(2, $depthOneIndices, 'Each tree contributes one siblingIndex=1; global indexing would yield 0 or 3.');
    }

    #[Test]
    public function tree_then_count_rejected(): void
    {
        $this->expectException(LogicException::class);

        Category::factory()->tree(depth: 1, branching: 2)->count(3);
    }

    // ----------------------------------------------------------------
    // make() rejection
    // ----------------------------------------------------------------

    #[Test]
    public function tree_then_make_throws(): void
    {
        $this->expectException(LogicException::class);

        Category::factory()->tree(depth: 1, branching: 1)->make();
    }

    #[Test]
    public function tree_from_shape_then_make_throws(): void
    {
        $this->expectException(LogicException::class);

        Category::factory()->treeFromShape([['name' => 'X']])->make();
    }

    // ----------------------------------------------------------------
    // Scoped models
    // ----------------------------------------------------------------

    #[Test]
    public function scoped_factory_with_anchor_produces_scoped_tree(): void
    {
        $menu = Menu::create(['name' => 'Sidebar']);
        $anchor = new MenuItem(['name' => 'Anchor', 'menu_id' => $menu->id]);
        $anchor->saveAsRoot();
        $anchor->refresh();

        $result = MenuItem::factory()
            ->state(['menu_id' => $menu->id])
            ->tree(depth: 2, branching: 2, parent: $anchor)
            ->create();

        $this->assertInstanceOf(MenuItem::class, $result);
        $this->assertSame($menu->id, $result->menu_id);
        $this->assertSame(8, MenuItem::query()->count(), '1 anchor + 7-node subtree = 8 rows.');
        foreach (MenuItem::query()->get() as $node) {
            $this->assertSame($menu->id, $node->menu_id);
        }
    }

    #[Test]
    public function scoped_factory_without_scope_state_raises_scope_violation(): void
    {
        $this->expectException(ScopeViolationException::class);

        MenuItem::factory()->tree(depth: 0, branching: 0)->create();
    }

    #[Test]
    public function parent_scope_mismatch_rejected_upfront(): void
    {
        $menuA = Menu::create(['name' => 'A']);
        $menuB = Menu::create(['name' => 'B']);
        $other = new MenuItem(['name' => 'anchor', 'menu_id' => $menuA->id]);
        $other->saveAsRoot();
        $other->refresh();

        $this->expectException(ScopeViolationException::class);

        MenuItem::factory()
            ->state(['menu_id' => $menuB->id])
            ->tree(depth: 0, branching: 0, parent: $other)
            ->create();
    }

    #[Test]
    public function scoped_factory_without_scope_state_falls_through_to_anchor_scope(): void
    {
        $menu = Menu::create(['name' => 'Inherited']);
        $anchor = new MenuItem(['name' => 'Anchor', 'menu_id' => $menu->id]);
        $anchor->saveAsRoot();
        $anchor->refresh();

        $result = MenuItem::factory()
            ->tree(depth: 1, branching: 1, parent: $anchor)
            ->create();

        $this->assertInstanceOf(MenuItem::class, $result);
        $this->assertSame($menu->id, $result->menu_id, 'menu_id flows from the anchor when factory state is silent.');
    }

    #[Test]
    public function parent_class_mismatch_rejected_upfront(): void
    {
        $crossClassAnchor = new ScopedArea(['name' => 'cross-class', 'tenant_id' => 1]);
        $crossClassAnchor->saveAsRoot();
        $crossClassAnchor->refresh();

        $this->expectException(ScopeViolationException::class);
        $this->expectExceptionMessageMatches('/must be an instance of/');

        MenuItem::factory()
            ->state(['menu_id' => 99])
            ->tree(depth: 0, branching: 0, parent: $crossClassAnchor)
            ->create();
    }

    // ----------------------------------------------------------------
    // Soft-deleted parent rejection
    // ----------------------------------------------------------------

    #[Test]
    public function grafting_onto_trashed_parent_rejected_upfront(): void
    {
        $trashed = new Category(['name' => 'gone']);
        $trashed->saveAsRoot();
        $trashed->refresh();
        $trashed->delete();
        $this->allowBrokenTreeAtTearDown = true;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/trashed/');

        Category::factory()->tree(depth: 0, branching: 0, parent: $trashed)->create();
    }

    // ----------------------------------------------------------------
    // Aggregate recompute
    // ----------------------------------------------------------------

    #[Test]
    public function aggregate_factory_populates_source_columns_and_recomputes(): void
    {
        $root = Area::factory()
            ->state(['tickets' => 5])
            ->tree(
                depth: 2,
                branching: 2,
                per: fn (int $d, int $i): array => ['tickets' => 1],
            )
            ->create();

        $this->assertInstanceOf(Area::class, $root);

        $allTotal = (int) Area::query()->sum('tickets');
        $rootFresh = Area::query()->whereKey($root->id)->firstOrFail();
        $this->assertSame($allTotal, $rootFresh->tickets_total);
    }

    // ----------------------------------------------------------------
    // afterCreating hooks
    // ----------------------------------------------------------------

    #[Test]
    public function after_creating_fires_once_per_inserted_row(): void
    {
        $touched = [];
        $factory = Category::factory()->afterCreating(function (Category $row) use (&$touched): void {
            $touched[] = $row->id;
        });

        $factory->tree(depth: 1, branching: 2)->create();

        $this->assertCount(3, $touched);
        $this->assertSame(Category::query()->pluck('id')->all(), $touched);
    }

    #[Test]
    public function after_creating_opt_out_skips_hooks(): void
    {
        $touched = 0;
        $factory = Category::factory()->afterCreating(function () use (&$touched): void {
            $touched++;
        });

        $factory->tree(depth: 1, branching: 2, afterCreating: false)->create();

        $this->assertSame(0, $touched);
    }

    // ----------------------------------------------------------------
    // Sequences
    // ----------------------------------------------------------------

    #[Test]
    public function sequence_cycles_in_dfs_pre_order(): void
    {
        Category::factory()
            ->state(new Sequence(['title' => 'A'], ['title' => 'B'], ['title' => 'C']))
            ->tree(depth: 1, branching: 2)
            ->create();

        $titles = Category::query()->orderBy('lft')->pluck('title')->all();
        $this->assertSame(['A', 'B', 'C'], $titles);
    }

    // ----------------------------------------------------------------
    // previewTree
    // ----------------------------------------------------------------

    #[Test]
    public function preview_tree_does_not_persist(): void
    {
        $payload = Category::factory()->tree(depth: 1, branching: 2)->previewTree();

        $this->assertSame(0, Category::query()->count());
        $this->assertCount(1, $payload);
        $this->assertCount(2, $payload[0]['children']);
    }

    #[Test]
    public function preview_tree_replays_via_tree_from_shape(): void
    {
        $payload = Category::factory()->tree(depth: 1, branching: 2)->previewTree();

        $root = Category::factory()->treeFromShape($payload)->create();
        $this->assertInstanceOf(Category::class, $root);

        $expectedNames = $this->collectShapeField($payload, 'name');
        $actualNames = Category::query()->orderBy('lft')->pluck('name')->all();
        $this->assertSame($expectedNames, $actualNames);
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $shape
     * @return list<mixed>
     */
    private function collectShapeField(array $shape, string $key): array
    {
        $out = [];
        foreach ($shape as $node) {
            $out[] = $node[$key] ?? null;
            /** @var list<array<string, mixed>> $children */
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            foreach ($this->collectShapeField($children, $key) as $child) {
                $out[] = $child;
            }
        }

        return $out;
    }
}
