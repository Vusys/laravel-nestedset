<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Import\Json;

use Vusys\NestedSet\Exceptions\InvalidJsonTreeException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Edge paths on `fromJsonTree()`: empty payload no-op, JSON-string
 * input, flat-shape import, includeKeys + collision, scoped roots
 * requiring scope columns, invalid JSON.
 */
final class JsonImportEdgeCasesTest extends TestCase
{
    use InteractsWithTrees;

    public function test_empty_payload_inserts_nothing_and_returns_empty(): void
    {
        $result = Category::fromJsonTree([]);

        $this->assertCount(0, $result);
        $this->assertSame(0, Category::query()->count());
    }

    public function test_json_string_input_is_decoded_and_inserted(): void
    {
        $json = '[{"name":"r","children":[]}]';

        Category::fromJsonTree($json);

        $this->assertSame(1, Category::query()->count());
    }

    public function test_invalid_json_string_throws(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        Category::fromJsonTree('not json at all');
    }

    public function test_flat_shape_payload_is_imported(): void
    {
        Category::fromJsonTree([
            ['id' => 1, 'name' => 'r', 'parent_id' => null],
            ['id' => 2, 'name' => 'a', 'parent_id' => 1],
        ]);

        $r = Category::query()->where('name', 'r')->firstOrFail();
        $a = Category::query()->where('name', 'a')->firstOrFail();
        $this->assertIsRoot($r);
        $this->assertIsChildOf($a, $r);
    }

    public function test_scoped_model_without_parent_requires_scope_columns_on_roots(): void
    {
        $this->expectException(ScopeViolationException::class);
        MenuItem::fromJsonTree([
            ['name' => 'orphan', 'children' => []],
        ]);
    }

    public function test_scoped_model_with_parent_inherits_scope(): void
    {
        $menu = Menu::query()->create(['name' => 'main']);
        $rootItem = new MenuItem(['name' => 'root']);
        $rootItem->menu_id = $menu->id;
        $rootItem->makeRoot()->save();

        MenuItem::fromJsonTree([
            ['name' => 'A', 'children' => []],
            ['name' => 'B', 'children' => []],
        ], $rootItem);

        $a = MenuItem::query()->where('name', 'A')->firstOrFail();
        $this->assertSame($menu->id, $a->menu_id);
        $this->assertIsChildOf($a, $rootItem);
    }
}
