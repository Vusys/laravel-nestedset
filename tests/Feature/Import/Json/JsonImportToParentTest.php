<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Import\Json;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

final class JsonImportToParentTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function nested_payload_inserts_under_existing_parent(): void
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

        $a = Category::query()->where('name', 'A')->firstOrFail();
        $a1 = Category::query()->where('name', 'A1')->firstOrFail();
        $a2 = Category::query()->where('name', 'A2')->firstOrFail();
        $b = Category::query()->where('name', 'B')->firstOrFail();

        $this->assertIsChildOf($a, $root);
        $this->assertIsChildOf($b, $root);
        $this->assertIsChildOf($a1, $a);
        $this->assertIsChildOf($a2, $a);
        $this->assertTreeIsIntact(Category::class);
    }

    #[Test]
    public function nested_payload_inserts_as_roots_when_parent_null(): void
    {
        $payload = [
            ['name' => 'r1', 'children' => []],
            ['name' => 'r2', 'children' => []],
        ];

        Category::fromJsonTree($payload);

        $r1 = Category::query()->where('name', 'r1')->firstOrFail();
        $r2 = Category::query()->where('name', 'r2')->firstOrFail();
        $this->assertIsRoot($r1);
        $this->assertIsRoot($r2);
    }
}
