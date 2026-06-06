<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Import\Json;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Exceptions\InvalidJsonTreeException;
use Vusys\NestedSet\Import\JsonImportOptions;
use Vusys\NestedSet\Import\JsonTreeNormaliser;

/**
 * Each error path on the JSON normaliser — non-array payload, non-array
 * rows, flat-shape rows missing `id`, ill-typed `parent_id`, duplicate
 * `id`, parent referencing a non-existent id, dangling cycle through
 * the flat shape, and the empty-payload short-circuit.
 */
final class JsonNormaliseErrorsTest extends TestCase
{
    #[Test]
    public function non_array_decoded_payload_throws(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        JsonTreeNormaliser::normalise('"a bare string"', new JsonImportOptions);
    }

    #[Test]
    public function empty_payload_returns_empty_list(): void
    {
        $this->assertSame([], JsonTreeNormaliser::normalise([], new JsonImportOptions));
    }

    #[Test]
    public function non_array_row_in_nested_shape_throws(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        JsonTreeNormaliser::normalise(['not an object'], new JsonImportOptions);
    }

    #[Test]
    public function nested_row_with_non_array_children_throws(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        JsonTreeNormaliser::normalise(
            [['name' => 'r', 'children' => 'oops']],
            new JsonImportOptions,
        );
    }

    #[Test]
    public function flat_row_without_id_throws(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        JsonTreeNormaliser::normalise(
            [['name' => 'r', 'parent_id' => null]],
            new JsonImportOptions,
        );
    }

    #[Test]
    public function flat_id_must_be_int_or_string(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        JsonTreeNormaliser::normalise(
            [['id' => 1.5, 'name' => 'r', 'parent_id' => null]],
            new JsonImportOptions,
        );
    }

    #[Test]
    public function flat_duplicate_id_throws(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        JsonTreeNormaliser::normalise(
            [
                ['id' => 1, 'name' => 'r', 'parent_id' => null],
                ['id' => 1, 'name' => 'r2', 'parent_id' => null],
            ],
            new JsonImportOptions,
        );
    }

    #[Test]
    public function flat_parent_id_must_be_scalar(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        JsonTreeNormaliser::normalise(
            [
                ['id' => 1, 'name' => 'r', 'parent_id' => null],
                ['id' => 2, 'name' => 'c', 'parent_id' => ['nope']],
            ],
            new JsonImportOptions,
        );
    }

    #[Test]
    public function flat_parent_id_referencing_unknown_row_throws(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        JsonTreeNormaliser::normalise(
            [['id' => 1, 'name' => 'a', 'parent_id' => 99]],
            new JsonImportOptions,
        );
    }

    #[Test]
    public function single_root_object_input_is_wrapped(): void
    {
        $out = JsonTreeNormaliser::normalise(
            ['id' => 1, 'name' => 'lone'],
            new JsonImportOptions,
        );
        $this->assertCount(1, $out);
    }

    #[Test]
    public function non_array_child_inside_children_throws(): void
    {
        $this->expectException(InvalidJsonTreeException::class);
        JsonTreeNormaliser::normalise(
            [['name' => 'r', 'children' => ['not-an-object']]],
            new JsonImportOptions,
        );
    }
}
