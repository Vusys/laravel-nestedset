<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
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

    public function test_null_quotes_as_sql_null_literal(): void
    {
        $this->assertSame('NULL', FilterValueQuoter::quote($this->connection, null));
    }

    public function test_bool_true_renders_as_sql_true_literal(): void
    {
        // TRUE/FALSE is the only portable inline boolean — PostgreSQL
        // rejects `boolean_col = 1` against a real BOOLEAN column.
        $this->assertSame('TRUE', FilterValueQuoter::quote($this->connection, true));
    }

    public function test_bool_false_renders_as_sql_false_literal(): void
    {
        $this->assertSame('FALSE', FilterValueQuoter::quote($this->connection, false));
    }

    public function test_positive_int_is_stringified_without_quotes(): void
    {
        $this->assertSame('42', FilterValueQuoter::quote($this->connection, 42));
    }

    public function test_negative_int_keeps_sign(): void
    {
        $this->assertSame('-7', FilterValueQuoter::quote($this->connection, -7));
    }

    public function test_zero_int_renders_as_zero(): void
    {
        // Distinct from bool false (which renders as FALSE).
        $this->assertSame('0', FilterValueQuoter::quote($this->connection, 0));
    }

    public function test_float_is_stringified(): void
    {
        $this->assertSame('3.14', FilterValueQuoter::quote($this->connection, 3.14));
    }

    public function test_plain_string_is_quoted(): void
    {
        $this->assertSame("'hello'", FilterValueQuoter::quote($this->connection, 'hello'));
    }

    public function test_string_with_single_quote_is_escaped(): void
    {
        // SQLite's escape (also the SQL standard): double the quote.
        $this->assertSame("'O''Reilly'", FilterValueQuoter::quote($this->connection, "O'Reilly"));
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

    public function test_array_value_throws(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('must be scalar; got array');

        FilterValueQuoter::quote($this->connection, ['nope']);
    }

    public function test_object_value_throws(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('must be scalar; got stdClass');

        FilterValueQuoter::quote($this->connection, new \stdClass);
    }
}
