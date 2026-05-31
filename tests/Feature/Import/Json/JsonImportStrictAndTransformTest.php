<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Import\Json;

use Vusys\NestedSet\Exceptions\InvalidJsonTreeException;
use Vusys\NestedSet\Import\JsonImportOptions;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

final class JsonImportStrictAndTransformTest extends TestCase
{
    public function test_strict_mode_rejects_unknown_columns(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        Category::fromJsonTree([
            ['name' => 'r', 'children' => [], 'wat' => 'huh'],
        ]);
    }

    public function test_lax_mode_drops_unknown_columns(): void
    {
        Category::fromJsonTree(
            [['name' => 'r', 'children' => [], 'wat' => 'huh']],
            null,
            new JsonImportOptions(strict: false),
        );

        $this->assertSame(1, Category::query()->count());
    }

    public function test_transform_runs_before_validation(): void
    {
        $transform = static function (array $row, int $depth): array {
            unset($row['wat']);
            $row['title'] = 'rewritten-at-depth-'.$depth;

            return $row;
        };

        Category::fromJsonTree(
            [['name' => 'r', 'children' => [], 'wat' => 'huh']],
            null,
            new JsonImportOptions(transform: $transform),
        );

        $r = Category::query()->firstOrFail();
        $this->assertSame('rewritten-at-depth-0', $r->title);
    }
}
