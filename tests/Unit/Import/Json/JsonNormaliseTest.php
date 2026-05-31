<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Import\Json;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Exceptions\InvalidJsonTreeException;
use Vusys\NestedSet\Import\JsonImportOptions;
use Vusys\NestedSet\Import\JsonTreeNormaliser;

final class JsonNormaliseTest extends TestCase
{
    public function test_nested_shape_is_returned_as_normalised_tree(): void
    {
        $input = [
            ['id' => 1, 'name' => 'r', 'children' => [
                ['id' => 2, 'name' => 'a', 'children' => []],
            ]],
        ];

        $out = JsonTreeNormaliser::normalise($input, new JsonImportOptions);

        $this->assertCount(1, $out);
        $this->assertSame(['id' => 1, 'name' => 'r'], $out[0]['attributes']);
        $this->assertCount(1, $out[0]['children']);
    }

    public function test_single_root_object_is_wrapped(): void
    {
        $input = ['id' => 1, 'name' => 'r', 'children' => []];

        $out = JsonTreeNormaliser::normalise($input, new JsonImportOptions);

        $this->assertCount(1, $out);
        $this->assertSame(1, $out[0]['attributes']['id']);
    }

    public function test_flat_shape_is_assembled_into_nested(): void
    {
        $input = [
            ['id' => 1, 'name' => 'r', 'parent_id' => null],
            ['id' => 2, 'name' => 'a', 'parent_id' => 1],
            ['id' => 3, 'name' => 'aa', 'parent_id' => 2],
        ];

        $out = JsonTreeNormaliser::normalise($input, new JsonImportOptions);

        $this->assertCount(1, $out);
        $this->assertSame(1, $out[0]['attributes']['id']);
        $rootChildren = $out[0]['children'];
        $this->assertCount(1, $rootChildren);

        $aChild = $rootChildren[0];
        $this->assertIsArray($aChild);
        $aGrand = $aChild['children'];
        $this->assertIsArray($aGrand);
        $this->assertCount(1, $aGrand);
    }

    public function test_flat_cycle_throws(): void
    {
        $input = [
            ['id' => 1, 'name' => 'a', 'parent_id' => 2],
            ['id' => 2, 'name' => 'b', 'parent_id' => 1],
        ];

        $this->expectException(InvalidJsonTreeException::class);
        JsonTreeNormaliser::normalise($input, new JsonImportOptions);
    }

    public function test_mixed_shape_throws(): void
    {
        $input = [
            ['id' => 1, 'children' => []],
            ['id' => 2, 'parent_id' => 1],
        ];

        $this->expectException(InvalidJsonTreeException::class);
        JsonTreeNormaliser::normalise($input, new JsonImportOptions);
    }

    public function test_json_string_input_is_decoded(): void
    {
        $json = '[{"id":1,"name":"r","children":[]}]';

        $out = JsonTreeNormaliser::normalise($json, new JsonImportOptions);

        $this->assertCount(1, $out);
    }

    public function test_invalid_json_string_throws(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        JsonTreeNormaliser::normalise('{not json', new JsonImportOptions);
    }
}
