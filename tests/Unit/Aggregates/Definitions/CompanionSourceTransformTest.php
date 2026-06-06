<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates\Definitions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceTransform;

/**
 * Pins the PHP-side value transforms, with emphasis on the `AsInt`
 * truthiness rules — the boolean-rollup companion casts each source
 * value to 0/1 exactly as the SQL `CASE WHEN c THEN 1 ELSE 0 END`
 * expression would, so the PHP delta-capture path and the SQL recompute
 * path stay arithmetically equivalent.
 */
final class CompanionSourceTransformTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function asIntCases(): iterable
    {
        // Native booleans.
        yield 'bool true is 1' => [true, 1];
        yield 'bool false is 0' => [false, 0];

        // null is a canonical false marker.
        yield 'null is 0' => [null, 0];

        // Numeric int / float: non-zero is truthy, zero is falsy. The
        // int-zero and float-zero cases pin the `!== 0 && !== 0.0` guard
        // (a `0.0 → 1.0` mutation would make zero report as truthy).
        yield 'int non-zero is 1' => [5, 1];
        yield 'int zero is 0' => [0, 0];
        yield 'negative int is 1' => [-3, 1];
        yield 'float non-zero is 1' => [2.5, 1];
        yield 'float one is 1' => [1.0, 1];
        yield 'float zero is 0' => [0.0, 0];

        // String false-markers — the exact set the `in_array` guard lists.
        // Removing any one of these would make that token report truthy.
        yield 'empty string is 0' => ['', 0];
        yield 'string zero is 0' => ['0', 0];
        yield 'string f is 0' => ['f', 0];
        yield 'string false is 0' => ['false', 0];

        // Case-folding and trimming are both applied before the lookup.
        yield 'uppercase FALSE is 0' => ['FALSE', 0];
        yield 'mixed-case False is 0' => ['False', 0];
        yield 'padded false is 0' => ['  false  ', 0];
        yield 'padded f is 0' => [" f\t", 0];

        // Any other string is truthy.
        yield 'string true is 1' => ['true', 1];
        yield 'arbitrary string is 1' => ['yes', 1];
        yield 'numeric string is 1' => ['5', 1];
    }

    #[DataProvider('asIntCases')]
    #[Test]
    public function as_int_matches_sql_case_when_truthiness(mixed $value, int $expected): void
    {
        $this->assertSame($expected, CompanionSourceTransform::AsInt->applyPhp($value));
    }

    #[Test]
    public function identity_returns_the_numeric_value_or_zero(): void
    {
        $this->assertEqualsWithDelta(7.0, CompanionSourceTransform::Identity->applyPhp(7), 1e-9);
        $this->assertEqualsWithDelta(-5.0, CompanionSourceTransform::Identity->applyPhp(-5), 1e-9);
        // Numeric zero coerces to float 0.0; only the non-numeric fallback returns int 0.
        $this->assertSame(0.0, CompanionSourceTransform::Identity->applyPhp(0));
        $this->assertSame(0, CompanionSourceTransform::Identity->applyPhp(null));
        $this->assertSame(0, CompanionSourceTransform::Identity->applyPhp('not-numeric'));
    }

    #[Test]
    public function square_multiplies_the_value_by_itself(): void
    {
        $this->assertEqualsWithDelta(9.0, CompanionSourceTransform::Square->applyPhp(3), 1e-9);
        $this->assertEqualsWithDelta(9.0, CompanionSourceTransform::Square->applyPhp(-3), 1e-9);
        // Numeric zero coerces to float 0.0; only the non-numeric fallback returns int 0.
        $this->assertSame(0.0, CompanionSourceTransform::Square->applyPhp(0));
        $this->assertSame(0, CompanionSourceTransform::Square->applyPhp(null));
        $this->assertSame(0, CompanionSourceTransform::Square->applyPhp('not-numeric'));
    }
}
