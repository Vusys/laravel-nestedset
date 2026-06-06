<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Import\Json;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Import\JsonImportOptions;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * Shape detection must honour the configured `childrenKey`. With the
 * default `children`, a payload using `kids` would mis-detect as flat
 * and either fail validation or insert nothing — neither of which is
 * a useful surface for callers who picked a custom key.
 */
final class JsonImportCustomChildrenKeyTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function nested_shape_is_detected_when_children_key_is_customised(): void
    {
        $payload = [
            ['name' => 'r', 'kids' => [
                ['name' => 'leaf', 'kids' => []],
            ]],
        ];

        Category::fromJsonTree($payload, null, new JsonImportOptions(childrenKey: 'kids'));

        $r = Category::query()->where('name', 'r')->firstOrFail();
        $leaf = Category::query()->where('name', 'leaf')->firstOrFail();
        $this->assertIsRoot($r);
        $this->assertIsChildOf($leaf, $r);
    }
}
