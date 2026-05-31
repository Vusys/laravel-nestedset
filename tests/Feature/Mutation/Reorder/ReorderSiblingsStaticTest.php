<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Mutation\Reorder;

use Illuminate\Support\Facades\DB;
use LogicException;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\Fixtures\Models\Menu;
use Vusys\NestedSet\Tests\Fixtures\Models\MenuItem;
use Vusys\NestedSet\Tests\TestCase;

final class ReorderSiblingsStaticTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('categories')->insert([
            ['id' => 1, 'name' => 'Root', 'lft' => 1, 'rgt' => 8, 'depth' => 0, 'parent_id' => null],
            ['id' => 2, 'name' => 'A',    'lft' => 2, 'rgt' => 3, 'depth' => 1, 'parent_id' => 1],
            ['id' => 3, 'name' => 'B',    'lft' => 4, 'rgt' => 5, 'depth' => 1, 'parent_id' => 1],
            ['id' => 4, 'name' => 'C',    'lft' => 6, 'rgt' => 7, 'depth' => 1, 'parent_id' => 1],
        ]);
        $this->syncSequence('categories');
    }

    public function test_static_helper_delegates_to_instance_method(): void
    {
        $root = Category::query()->findOrFail(1);

        Category::reorderSiblings($root, [4, 2, 3]);

        $this->assertSame(
            ['C', 'A', 'B'],
            Category::query()->where('parent_id', 1)->orderBy('lft')->pluck('name')->all(),
        );
    }

    public function test_static_helper_rejects_parent_of_different_class(): void
    {
        $menu = Menu::create(['name' => 'M']);
        $menuRoot = new MenuItem(['name' => 'mroot', 'menu_id' => $menu->id]);
        $menuRoot->saveAsRoot();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(Category::class);

        Category::reorderSiblings($menuRoot, []);
    }
}
