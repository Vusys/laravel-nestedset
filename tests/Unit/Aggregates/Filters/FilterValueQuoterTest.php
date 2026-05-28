<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates\Filters;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Filters\FilterValueQuoter;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * Quoter contract pinned across the five scalar kinds plus the
 * non-scalar reject path. The string path is delegated to PDO::quote
 * via a real in-memory SQLite connection — keeps the test driver-real
 * (no double-escape on the test itself) while staying unit-fast.
 */
final class FilterValueQuoterTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->connection = $capsule->getConnection();
    }

    #[DataProvider('scalarCases')]
    public function test_quotes_scalar_value_to_sql_literal(mixed $value, string $expected): void
    {
        $this->assertSame($expected, FilterValueQuoter::quote($this->connection, $value));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function scalarCases(): iterable
    {
        yield 'null is the SQL NULL literal' => [null, 'NULL'];
        // TRUE/FALSE is the only portable inline boolean — PostgreSQL
        // rejects `boolean_col = 1` against a real BOOLEAN column.
        yield 'bool true renders as TRUE' => [true, 'TRUE'];
        yield 'bool false renders as FALSE' => [false, 'FALSE'];
        yield 'positive int is unquoted' => [42, '42'];
        yield 'negative int keeps its sign' => [-7, '-7'];
        // Distinct from bool false (which renders as FALSE).
        yield 'zero int renders as 0' => [0, '0'];
        yield 'float is stringified' => [3.14, '3.14'];
        yield 'plain string is quoted' => ['hello', "'hello'"];
        // SQLite's escape (also the SQL standard): double the quote.
        yield 'single quote is escaped by doubling' => ["O'Reilly", "'O''Reilly'"];
    }

    public function test_string_with_backslash_is_passed_to_pdo_quote(): void
    {
        // SQLite doesn't treat backslashes specially, so the literal
        // passes through. The point of routing through PDO::quote is
        // that MySQL/MariaDB's PDO drivers will escape backslashes
        // appropriately on those backends — verified by the
        // FilterQuotingTest integration test running on every CI cell.
        $quoted = FilterValueQuoter::quote($this->connection, 'C:\\path');

        $this->assertStringContainsString('C:\\path', $quoted);
        $this->assertStringStartsWith("'", $quoted);
        $this->assertStringEndsWith("'", $quoted);
    }

    #[DataProvider('nonScalarCases')]
    public function test_non_scalar_value_throws(mixed $value, string $expectedMessageFragment): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage($expectedMessageFragment);

        FilterValueQuoter::quote($this->connection, $value);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function nonScalarCases(): iterable
    {
        yield 'array' => [['nope'], 'must be scalar; got array'];
        yield 'object' => [new \stdClass, 'must be scalar; got stdClass'];
    }
}
