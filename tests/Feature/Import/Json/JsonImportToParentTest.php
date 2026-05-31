<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Import\Json;

use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

final class JsonImportToParentTest extends TestCase
{
    public function test_nested_payload_inserts_under_existing_parent(): void
    {
        $root = new Category(['name' => 'Root']);
        $root->makeRoot()->save();

        $payload = [
            ['name' => 'A', 'children' => [
                ['name' => 'A1', 'children' => []],
                ['name' => 'A2', 'children' => []],
            ]],
            ['name' => 'B', 'children' => []],
        ];

        $inserted = Category::fromJsonTree($payload, $root);

        $this->assertCount(2, $inserted);
        $this->assertSame(4, Category::query()->whereNotNull('parent_id')->count());
        $this->assertSame(['A', 'A1', 'A2', 'B'], Category::query()
            ->whereNotNull('parent_id')
            ->orderBy('lft')
            ->pluck('name')
            ->all()
        );
    }

    public function test_nested_payload_inserts_as_roots_when_parent_null(): void
    {
        $payload = [
            ['name' => 'r1', 'children' => []],
            ['name' => 'r2', 'children' => []],
        ];

        Category::fromJsonTree($payload);

        $this->assertSame(2, Category::query()->whereNull('parent_id')->count());
    }
}
