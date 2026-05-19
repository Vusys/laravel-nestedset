<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Query\TreeAggregateBuilder;

/**
 * Pins the tolerance shape of `TreeAggregateBuilder::aggregatesEqual`.
 * Both sides arrive as int / float / decimal-string / null depending
 * on backend; the comparator must:
 *
 *  - treat null/null as equal and null/non-null as not.
 *  - treat exact-equal numerics as equal regardless of representation
 *    (int vs string vs float).
 *  - tolerate sub-precision rounding noise at typical AVG magnitudes
 *    (`DECIMAL(_,4)` storage vs PHP float).
 *  - tolerate float-arithmetic noise at large magnitudes via a
 *    relative tolerance — a SUM at 1M+ scale routinely has float
 *    rounding noise above the 1e-4 absolute floor.
 *  - reject genuine drift in both regimes.
 */
final class AggregatesEqualTest extends TestCase
{
    public function test_null_equals_null(): void
    {
        $this->assertTrue(TreeAggregateBuilder::aggregatesEqual(null, null));
    }

    public function test_null_differs_from_zero(): void
    {
        $this->assertFalse(TreeAggregateBuilder::aggregatesEqual(null, 0));
        $this->assertFalse(TreeAggregateBuilder::aggregatesEqual(0, null));
    }

    public function test_exact_int_equality(): void
    {
        $this->assertTrue(TreeAggregateBuilder::aggregatesEqual(42, 42));
    }

    public function test_int_and_decimal_string_with_same_value(): void
    {
        // Postgres returns DECIMAL columns as strings; the comparator
        // must not flag them as drift.
        $this->assertTrue(TreeAggregateBuilder::aggregatesEqual(42, '42.0000'));
    }

    public function test_avg_rounding_at_typical_magnitude_is_tolerated(): void
    {
        // 175 / 3 = 58.333... — stored as 58.3333 by the SQL layer
        // and re-computed as 58.33333333333333 in PHP. Should compare
        // equal.
        $this->assertTrue(TreeAggregateBuilder::aggregatesEqual(58.3333, 175 / 3));
    }

    public function test_relative_tolerance_handles_float_noise_at_large_magnitudes(): void
    {
        // At 1e9 scale, accumulated float-arithmetic noise can exceed
        // the 1e-4 absolute floor. The relative-tolerance branch
        // (1e-9 of max) must accept these as equal.
        $stored = 1_000_000_000.0;
        $computed = 1_000_000_000.0 + 1e-3; // 1e-12 relative

        $this->assertTrue(TreeAggregateBuilder::aggregatesEqual($stored, $computed));
    }

    public function test_genuine_drift_at_large_magnitudes_is_caught(): void
    {
        // An honest drift of 0.5 against a stored 1B exceeds both
        // tolerances (5e-1 absolute, 5e-10 relative is borderline).
        // Use a clearly-drifted value that's well over both
        // thresholds.
        $this->assertFalse(TreeAggregateBuilder::aggregatesEqual(1_000_000_000, 1_000_001_000));
    }

    public function test_small_distinct_avg_values_are_not_collapsed(): void
    {
        // The old absolute-1e-4 form swallowed the difference here.
        // Source values in the 1e-3 scale (e.g. AVG over per-mille
        // shares) should still detect drift.
        $this->assertFalse(TreeAggregateBuilder::aggregatesEqual(0.001, 0.002));
    }

    public function test_zero_is_equal_to_zero_for_all_representations(): void
    {
        $this->assertTrue(TreeAggregateBuilder::aggregatesEqual(0, 0));
        $this->assertTrue(TreeAggregateBuilder::aggregatesEqual(0, 0.0));
        $this->assertTrue(TreeAggregateBuilder::aggregatesEqual('0', 0));
        $this->assertTrue(TreeAggregateBuilder::aggregatesEqual('0.0000', 0));
    }

    public function test_negative_values_compare_correctly(): void
    {
        $this->assertTrue(TreeAggregateBuilder::aggregatesEqual(-42, -42));
        $this->assertFalse(TreeAggregateBuilder::aggregatesEqual(-42, 42));
        $this->assertTrue(TreeAggregateBuilder::aggregatesEqual(-58.3333, -175 / 3));
    }
}
