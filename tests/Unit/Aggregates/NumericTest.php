<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates;

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
    public function test_as_int_or_zero_returns_zero_for_null(): void
    {
        $this->assertSame(0, Numeric::asIntOrZero(null));
    }

    public function test_as_int_or_zero_returns_zero_for_non_numeric(): void
    {
        $this->assertSame(0, Numeric::asIntOrZero('abc'));
    }

    public function test_as_int_or_zero_passes_through_int(): void
    {
        $this->assertSame(5, Numeric::asIntOrZero(5));
    }

    public function test_as_int_or_zero_truncates_float(): void
    {
        $this->assertSame(5, Numeric::asIntOrZero(5.9));
    }

    public function test_as_int_or_zero_casts_numeric_string(): void
    {
        $this->assertSame(10, Numeric::asIntOrZero('10'));
    }

    public function test_as_numeric_or_null_returns_null_for_null(): void
    {
        $this->assertNull(Numeric::asNumericOrNull(null));
    }

    public function test_as_numeric_or_null_returns_null_for_non_numeric(): void
    {
        $this->assertNull(Numeric::asNumericOrNull('abc'));
    }

    public function test_as_numeric_or_null_preserves_native_int(): void
    {
        $this->assertSame(5, Numeric::asNumericOrNull(5));
    }

    public function test_as_numeric_or_null_preserves_native_float(): void
    {
        $this->assertSame(5.5, Numeric::asNumericOrNull(5.5));
    }

    public function test_as_numeric_or_null_decodes_integer_string_to_int(): void
    {
        $this->assertSame(10, Numeric::asNumericOrNull('10'));
    }

    public function test_as_numeric_or_null_decodes_decimal_string_to_float(): void
    {
        $this->assertSame(10.5, Numeric::asNumericOrNull('10.5'));
    }

    public function test_as_numeric_or_null_decodes_exponent_string_to_float(): void
    {
        $this->assertSame(100.0, Numeric::asNumericOrNull('1e2'));
    }

    public function test_as_numeric_or_zero_returns_zero_for_null(): void
    {
        $this->assertSame(0, Numeric::asNumericOrZero(null));
    }

    public function test_as_numeric_or_zero_returns_zero_for_non_numeric(): void
    {
        $this->assertSame(0, Numeric::asNumericOrZero('abc'));
    }

    public function test_as_numeric_or_zero_preserves_native_int(): void
    {
        $this->assertSame(5, Numeric::asNumericOrZero(5));
    }

    public function test_as_numeric_or_zero_preserves_native_float(): void
    {
        $this->assertSame(5.5, Numeric::asNumericOrZero(5.5));
    }

    public function test_as_numeric_or_zero_decodes_integer_string_to_int(): void
    {
        $this->assertSame(10, Numeric::asNumericOrZero('10'));
    }

    public function test_as_numeric_or_zero_decodes_decimal_string_to_float(): void
    {
        $this->assertSame(10.5, Numeric::asNumericOrZero('10.5'));
    }

    public function test_as_numeric_or_zero_decodes_exponent_string_to_float(): void
    {
        $this->assertSame(100.0, Numeric::asNumericOrZero('1e2'));
    }

    public function test_contribution_or_zero_returns_zero_for_null(): void
    {
        $this->assertSame(0, Numeric::contributionOrZero(null));
    }

    public function test_contribution_or_zero_passes_through_int(): void
    {
        $this->assertSame(5, Numeric::contributionOrZero(5));
    }

    public function test_contribution_or_zero_passes_through_float(): void
    {
        $this->assertSame(5.5, Numeric::contributionOrZero(5.5));
    }
}
