<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates\Filters;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;

final class FilterPredicateEvaluateTest extends TestCase
{
    #[Test]
    public function equality_returns_true_when_all_conditions_match(): void
    {
        $predicate = FilterPredicate::equality(['type' => 'fire', 'active' => true]);

        $result = $predicate->evaluateFor(['type' => 'fire', 'active' => true, 'extra' => 'ignored']);

        $this->assertTrue($result);
    }

    #[Test]
    public function equality_returns_false_when_one_condition_does_not_match(): void
    {
        $predicate = FilterPredicate::equality(['type' => 'fire', 'active' => true]);

        $result = $predicate->evaluateFor(['type' => 'fire', 'active' => false]);

        $this->assertFalse($result);
    }

    #[Test]
    public function equality_uses_strict_comparison(): void
    {
        // Strict comparison so the PHP-side evaluation agrees with the
        // SQL side's `= NULL → unknown → false` semantic. Loose
        // comparison would silently match "" == 0 and false == 0,
        // producing captured-vs-fresh aggregate drift.
        $predicate = FilterPredicate::equality(['level' => 5]);

        $this->assertFalse(
            $predicate->evaluateFor(['level' => '5']),
            'strict comparison: int 5 must not match string "5"',
        );
    }

    #[Test]
    public function equality_with_zero_value_does_not_match_null_attribute(): void
    {
        // The drift case the strict-comparison fix targets: a filter
        // value of int 0 against a NULL attribute. PHP loose `0 == null`
        // is true, but SQL `col = 0` doesn't match NULL — capture and
        // recompute would disagree under loose comparison.
        $predicate = FilterPredicate::equality(['status' => 0]);

        $this->assertFalse($predicate->evaluateFor(['status' => null]));
        $this->assertFalse($predicate->evaluateFor([]));
        $this->assertTrue($predicate->evaluateFor(['status' => 0]));
    }

    #[Test]
    public function not_null_returns_true_when_column_is_set(): void
    {
        $predicate = FilterPredicate::notNull('tickets');

        $result = $predicate->evaluateFor(['tickets' => 42]);

        $this->assertTrue($result);
    }

    #[Test]
    public function not_null_returns_false_when_column_is_null_or_missing(): void
    {
        $predicate = FilterPredicate::notNull('tickets');

        $this->assertFalse($predicate->evaluateFor(['tickets' => null]));
        $this->assertFalse($predicate->evaluateFor([]));
    }

    #[Test]
    public function raw_always_returns_null(): void
    {
        $predicate = FilterPredicate::raw('status = 1', ['status']);

        $result = $predicate->evaluateFor(['status' => 1]);

        $this->assertNull($result);
    }
}
