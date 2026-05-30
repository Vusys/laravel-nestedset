<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Walk;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\UnloadedSubtreeException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;
use Vusys\NestedSet\Walker\WalkContext;
use Vusys\NestedSet\Walker\WalkFilter;
use Vusys\NestedSet\Walker\WalkSignal;

/**
 * Feature tests that exercise the walker via real DB-backed Category /
 * MenuItem fixtures. All scenarios share the five-node electronics tree
 * built by {@see buildElectronicsTree()}:
 *
 *   Electronics
 *   ├── Laptops
 *   └── Phones
 *       ├── iPhone
 *       └── Android
 */
final class HasTreeWalkTest extends TestCase
{
    /** @return array{Category, Category, Category, Category, Category} */
    private function buildElectronicsTree(): array
    {
        $electronics = new Category(['name' => 'Electronics']);
        $electronics->saveAsRoot();
        $electronics = $electronics->refresh();

        $laptops = new Category(['name' => 'Laptops']);
        $laptops->appendToNode($electronics)->save();

        $phones = new Category(['name' => 'Phones']);
        $phones->appendToNode($electronics->refresh())->save();

        $iphone = new Category(['name' => 'iPhone']);
        $iphone->appendToNode($phones->refresh())->save();

        $android = new Category(['name' => 'Android']);
        $android->appendToNode($phones->refresh())->save();

        return [
            $electronics->refresh(),
            $laptops->refresh(),
            $phones->refresh(),
            $iphone->refresh(),
            $android->refresh(),
        ];
    }

    public function test_walk_with_no_subtree_and_no_loaded_descendants_throws_and_fires_no_query(): void
    {
        [$electronics] = $this->buildElectronicsTree();

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $threw = false;
        try {
            $electronics->walk(
                static fn (Model&HasNestedSet $_n, WalkContext $_c): null => null,
            );
        } catch (UnloadedSubtreeException $e) {
            $threw = true;
            $this->assertStringContainsString("->load('descendants')", $e->getMessage());
        }

        $this->assertTrue($threw, 'Expected UnloadedSubtreeException');
        $this->assertSame(0, $queries, 'Walker must not query the database');
    }

    public function test_walk_uses_loaded_descendants_relation_without_extra_query(): void
    {
        [$electronics] = $this->buildElectronicsTree();
        $electronics->load('descendants');

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        /** @var list<string> $visited */
        $visited = [];
        $electronics->walk(function (Model&HasNestedSet $node, WalkContext $_ctx) use (&$visited): void {
            $visited[] = self::nameOf($node);
        });

        $this->assertSame(['Electronics', 'Laptops', 'Phones', 'iPhone', 'Android'], $visited);
        $this->assertSame(0, $queries, 'Walker must not query the database when descendants is loaded');
    }

    public function test_walk_respects_explicit_subtree_argument_over_loaded_relation(): void
    {
        [$electronics, $laptops] = $this->buildElectronicsTree();
        $electronics->load('descendants');

        // Slice contains just Laptops — direct child of the walk root.
        // Passing it explicitly overrides the (wider) loaded `descendants`
        // relation, so the walk yields exactly Electronics + Laptops.
        /** @var EloquentCollection<int, Category> $slice */
        $slice = new EloquentCollection([$laptops]);

        $names = [];
        $electronics->walk(
            function (Model&HasNestedSet $node, WalkContext $_ctx) use (&$names): void {
                $names[] = self::nameOf($node);
            },
            subtree: $slice,
        );

        $this->assertSame(['Electronics', 'Laptops'], $names);
    }

    public function test_walker_honours_partial_subtree_treating_unreached_nodes_as_leaves(): void
    {
        [$electronics, , $phones] = $this->buildElectronicsTree();

        // Caller deliberately loaded only depth 0 + 1 (no grandchildren).
        $partial = Category::query()
            ->whereDescendantOf($electronics->getBounds())
            ->where('depth', '<=', 1)
            ->get();

        $names = [];
        $electronics->walk(
            function (Model&HasNestedSet $node, WalkContext $_ctx) use (&$names): void {
                $names[] = self::nameOf($node);
            },
            subtree: $partial,
        );

        // Phones is in the slice but its children are not — treated as a leaf.
        $this->assertSame(['Electronics', 'Laptops', 'Phones'], $names);
        $this->assertNotContains('iPhone', $names);
        $this->assertNotContains('Android', $names);

        // Confirm Phones really has children in the DB (sanity check).
        $this->assertSame(2, $phones->children()->count());
    }

    public function test_walk_filter_integration_honours_depth_and_predicate_against_real_subtree(): void
    {
        [$electronics] = $this->buildElectronicsTree();
        $electronics->load('descendants');

        // depth(1) → Electronics + Laptops + Phones; iPhone/Android pruned.
        // where(not Phones) AND'd in → Electronics + Laptops only.
        $filter = WalkFilter::compose(
            WalkFilter::depth(1),
            WalkFilter::where(
                static fn (Model&HasNestedSet $n): bool => self::nameOf($n) !== 'Phones',
            ),
        );

        $visited = [];
        foreach ($electronics->dfs(filter: $filter) as $node) {
            $visited[] = self::nameOf($node);
        }

        $this->assertSame(['Electronics', 'Laptops'], $visited);
    }

    public function test_walking_a_scoped_node_stays_within_its_scopes_subtree(): void
    {
        $menuA = Menu::create(['name' => 'Menu A']);
        $menuB = Menu::create(['name' => 'Menu B']);

        $rootA = new MenuItem(['name' => 'Root A', 'menu_id' => $menuA->id]);
        $rootA->saveAsRoot();
        $childA = new MenuItem(['name' => 'Child A', 'menu_id' => $menuA->id]);
        $childA->appendToNode($rootA->refresh())->save();

        $rootB = new MenuItem(['name' => 'Root B', 'menu_id' => $menuB->id]);
        $rootB->saveAsRoot();
        $childB = new MenuItem(['name' => 'Child B', 'menu_id' => $menuB->id]);
        $childB->appendToNode($rootB->refresh())->save();

        $rootA = $rootA->refresh();
        $rootA->load('descendants');

        $names = [];
        $rootA->walk(function (Model&HasNestedSet $node, WalkContext $_ctx) use (&$names): void {
            $names[] = self::nameOf($node);
        });

        // Walker only sees what was loaded — `descendants` is already
        // scope-filtered, so cross-scope contamination is impossible.
        $this->assertSame(['Root A', 'Child A'], $names);
    }

    public function test_flattened_subtree_returns_collection_in_strategy_order(): void
    {
        [$electronics] = $this->buildElectronicsTree();
        $electronics->load('descendants');

        $names = $electronics
            ->flattenedSubtree('bfs')
            ->map(static fn (Model&HasNestedSet $n): string => self::nameOf($n))
            ->all();

        // BFS: depth 0 then 1 then 2.
        $this->assertSame(['Electronics', 'Laptops', 'Phones', 'iPhone', 'Android'], $names);
    }

    public function test_stop_signal_short_circuits_the_walk(): void
    {
        [$electronics] = $this->buildElectronicsTree();
        $electronics->load('descendants');

        $visited = [];
        $electronics->walk(function (Model&HasNestedSet $node, WalkContext $_ctx) use (&$visited): ?WalkSignal {
            $name = self::nameOf($node);
            $visited[] = $name;

            return $name === 'Laptops' ? WalkSignal::Stop : null;
        });

        $this->assertSame(['Electronics', 'Laptops'], $visited);
    }

    private static function nameOf(Model $n): string
    {
        $v = $n->getAttribute('name');

        return is_scalar($v) ? (string) $v : '';
    }
}
