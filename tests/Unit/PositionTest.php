<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Position;

/**
 * `Position` is an internal-only enum used by sibling-relative
 * mutations to disambiguate "before" vs "after" without a magic-string
 * argument. Its int backing values are persisted nowhere — but the
 * package codebase round-trips them through `Position::from()` in
 * a few places, so the int contract is load-bearing within the
 * package even though no schema depends on it.
 */
final class PositionTest extends TestCase
{
    public function test_before_case_uses_int_value_1_for_internal_round_trip(): void
    {
        $this->assertSame(1, Position::Before->value);
    }

    public function test_after_case_uses_int_value_2_for_internal_round_trip(): void
    {
        $this->assertSame(2, Position::After->value);
    }

    public function test_from_int_round_trips_each_case_back_to_its_enum_instance(): void
    {
        $this->assertSame(Position::Before, Position::from(1));
        $this->assertSame(Position::After, Position::from(2));
    }

    public function test_enum_is_exactly_two_cases_before_and_after_no_silent_third_case(): void
    {
        // Pinning case count + identity guards against a silent third
        // case being added (e.g. "Inside" or "Replace") that would
        // break every `match (Position $p)` arm at the call sites.
        $this->assertSame([Position::Before, Position::After], Position::cases());
    }
}
