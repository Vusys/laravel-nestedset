<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Import\Json;

use PHPUnit\Framework\Attributes\Test;
use Vusys\NestedSet\Exceptions\JsonImportKeyCollisionException;
use Vusys\NestedSet\Import\JsonImportOptions;
use Vusys\NestedSet\Testing\InteractsWithTrees;
use Vusys\NestedSet\Tests\Fixtures\Models\Category;
use Vusys\NestedSet\Tests\TestCase;

/**
 * fromJsonTree(..., includeKeys: true) imports rows with their own
 * primary keys. Previously a dead end — bulkInsertTree reserved the key
 * column and threw before any SQL, which also made
 * JsonImportKeyCollisionException unreachable. The package still
 * computes lft/rgt/depth and parent_id; only the PK is taken from the
 * payload.
 */
final class JsonImportIncludeKeysTest extends TestCase
{
    use InteractsWithTrees;

    #[Test]
    public function imports_rows_with_their_explicit_primary_keys(): void
    {
        $payload = [
            ['id' => 500, 'name' => 'Root', 'children' => [
                ['id' => 501, 'name' => 'A', 'children' => []],
                ['id' => 502, 'name' => 'B', 'children' => []],
            ]],
        ];

        $inserted = Category::fromJsonTree($payload, null, new JsonImportOptions(includeKeys: true));

        $this->assertSame([500, 501, 502], $inserted->map->getKey()->all());

        $root = Category::query()->findOrFail(500);
        $a = Category::query()->findOrFail(501);
        $b = Category::query()->findOrFail(502);

        // parent_id references the explicit keys, not auto-generated ones.
        $this->assertNull($root->parent_id);
        $this->assertSame(500, (int) $a->parent_id);
        $this->assertSame(500, (int) $b->parent_id);
        $this->assertIsChildOf($a, $root);
        $this->assertIsChildOf($b, $root);
        $this->assertTreeIsIntact(Category::class);
    }

    #[Test]
    public function a_duplicate_explicit_key_raises_a_collision_exception(): void
    {
        Category::query()->forceCreate([
            'id' => 500, 'name' => 'Existing', 'lft' => 1, 'rgt' => 2, 'depth' => 0, 'parent_id' => null,
        ]);

        $this->allowBrokenTreeAtTearDown = true;

        $this->expectException(JsonImportKeyCollisionException::class);

        Category::fromJsonTree(
            [['id' => 500, 'name' => 'Clash', 'children' => []]],
            null,
            new JsonImportOptions(includeKeys: true),
        );
    }

    #[Test]
    public function without_include_keys_payload_ids_are_ignored(): void
    {
        // Default behaviour is unchanged: the id in the payload is
        // stripped and the store assigns keys.
        $inserted = Category::fromJsonTree([
            ['id' => 999, 'name' => 'Root', 'children' => []],
        ]);

        $root = $inserted->firstOrFail();
        $this->assertNotSame(999, $root->getKey());
        $this->assertNull(Category::query()->find(999));
    }
}
