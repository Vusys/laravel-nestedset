<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Feature\Import\Json;

use Exception;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Vusys\NestedSet\Import\JsonTreeImporter;

/**
 * extractCollisionKey() only matched bare integers, so a string/UUID
 * primary-key collision surfaced `offendingKey: -1`. It must recover the
 * actual key from each driver's unique-violation message shape.
 */
final class UuidCollisionKeyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: int|string}>
     */
    public static function messages(): iterable
    {
        $uuid = '0190f8a1-2b3c-7def-8123-456789abcdef';

        yield 'mysql quoted uuid' => ["Duplicate entry '{$uuid}' for key 'uuid_tags.PRIMARY'", $uuid];
        yield 'sqlite quoted uuid' => ["UNIQUE constraint failed: '{$uuid}'", $uuid];
        yield 'postgres parenthesised uuid' => ["Key (id)=({$uuid}) already exists.", $uuid];
        yield 'integer key still works' => ['Duplicate entry 42 for key PRIMARY', 42];
        yield 'postgres integer key' => ['Key (id)=(42) already exists.', 42];
    }

    #[Test]
    #[DataProvider('messages')]
    public function collision_key_recovers_string_and_integer_keys(string $message, int|string $expected): void
    {
        $previous = new Exception($message);
        $exception = new QueryException('sqlite', 'insert into x', [], $previous);

        $method = new ReflectionMethod(JsonTreeImporter::class, 'extractCollisionKey');
        $key = $method->invoke(null, $exception);

        $this->assertSame($expected, $key);
    }
}
