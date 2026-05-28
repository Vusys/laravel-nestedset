<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Numeric;

/**
 * Pins the four coercion contracts the aggregate maintenance path
 * relies on:
 *  - asIntOrZero: always int, null/non-numeric → 0
 *  - asNumericOrNull: int|float passthrough, "10"/"10.5"/"1e2" → natural type, null/non-numeric → null
 *  - asNumericOrZero: int|float passthrough, decimal-string detection, null/non-numeric → 0
 *  - contributionOrZero: typed pass-through with null → 0
 *
 * The null-vs-zero distinction matters in two places: the weighted-avg
 * delta path (null = no weight recorded, zero = explicit zero weight),
 * and Sum companion reads on delete/move/restore (a NULL filtered
 * extreme must stay NULL, not collapse to 0).
 *
 * `assertSame` is strict-equality so int vs float type drift fails the
 * assertion — no separate `assertIsInt` / `assertIsFloat` needed.
 */
final class NumericTest extends TestCase
{
    #[DataProvider('asIntOrZeroCases')]
    public function test_as_int_or_zero(mixed $input, int $expected): void
    {
        $this->assertSame($expected, Numeric::asIntOrZero($input));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: int}>
     */
    public static function asIntOrZeroCases(): iterable
    {
        yield 'null becomes zero' => [null, 0];
        yield 'non-numeric string becomes zero' => ['abc', 0];
        yield 'int passes through' => [5, 5];
        yield 'float truncates toward zero' => [5.9, 5];
        yield 'numeric string casts to int' => ['10', 10];
    }

    #[DataProvider('asNumericOrNullCases')]
    public function test_as_numeric_or_null(mixed $input, int|float|null $expected): void
    {
        $this->assertSame($expected, Numeric::asNumericOrNull($input));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: int|float|null}>
     */
    public static function asNumericOrNullCases(): iterable
    {
        yield 'null stays null' => [null, null];
        yield 'non-numeric string becomes null' => ['abc', null];
        yield 'native int is preserved' => [5, 5];
        yield 'native float is preserved' => [5.5, 5.5];
        yield 'integer string decodes to int' => ['10', 10];
        yield 'decimal string decodes to float' => ['10.5', 10.5];
        yield 'exponent string decodes to float' => ['1e2', 100.0];
    }

    #[DataProvider('asNumericOrZeroCases')]
    public function test_as_numeric_or_zero(mixed $input, int|float $expected): void
    {
        $this->assertSame($expected, Numeric::asNumericOrZero($input));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: int|float}>
     */
    public static function asNumericOrZeroCases(): iterable
    {
        yield 'null becomes zero' => [null, 0];
        yield 'non-numeric string becomes zero' => ['abc', 0];
        yield 'native int is preserved' => [5, 5];
        yield 'native float is preserved' => [5.5, 5.5];
        yield 'integer string decodes to int' => ['10', 10];
        yield 'decimal string decodes to float' => ['10.5', 10.5];
        yield 'exponent string decodes to float' => ['1e2', 100.0];
    }

    #[DataProvider('contributionOrZeroCases')]
    public function test_contribution_or_zero(int|float|null $input, int|float $expected): void
    {
        $this->assertSame($expected, Numeric::contributionOrZero($input));
    }

    /**
     * @return iterable<string, array{0: int|float|null, 1: int|float}>
     */
    public static function contributionOrZeroCases(): iterable
    {
        yield 'null becomes zero' => [null, 0];
        yield 'int passes through' => [5, 5];
        yield 'float passes through' => [5.5, 5.5];
    }
}
