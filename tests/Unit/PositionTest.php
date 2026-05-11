<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Position;

final class PositionTest extends TestCase
{
    public function test_before_has_value_one(): void
    {
        $this->assertSame(1, Position::Before->value);
    }

    public function test_after_has_value_two(): void
    {
        $this->assertSame(2, Position::After->value);
    }

    public function test_can_be_created_from_integer_value(): void
    {
        $this->assertSame(Position::Before, Position::from(1));
        $this->assertSame(Position::After, Position::from(2));
    }

    public function test_enum_has_exactly_two_cases(): void
    {
        $this->assertSame([Position::Before, Position::After], Position::cases());
    }
}
