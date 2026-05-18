<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\FilterPredicate;

final class FilterPredicateEvaluateTest extends TestCase
{
    public function test_equality_returns_true_when_all_conditions_match(): void
    {
        $predicate = FilterPredicate::equality(['type' => 'fire', 'active' => true]);

        $result = $predicate->evaluateFor(['type' => 'fire', 'active' => true, 'extra' => 'ignored']);

        $this->assertTrue($result);
    }

    public function test_equality_returns_false_when_one_condition_does_not_match(): void
    {
        $predicate = FilterPredicate::equality(['type' => 'fire', 'active' => true]);

        $result = $predicate->evaluateFor(['type' => 'fire', 'active' => false]);

        $this->assertFalse($result);
    }

    public function test_equality_uses_loose_comparison_for_type_coercion(): void
    {
        $predicate = FilterPredicate::equality(['level' => 5]);

        // String '5' should loosely equal int 5.
        $result = $predicate->evaluateFor(['level' => '5']);

        $this->assertTrue($result);
    }

    public function test_not_null_returns_true_when_column_is_set(): void
    {
        $predicate = FilterPredicate::notNull('tickets');

        $result = $predicate->evaluateFor(['tickets' => 42]);

        $this->assertTrue($result);
    }

    public function test_not_null_returns_false_when_column_is_null_or_missing(): void
    {
        $predicate = FilterPredicate::notNull('tickets');

        $this->assertFalse($predicate->evaluateFor(['tickets' => null]));
        $this->assertFalse($predicate->evaluateFor([]));
    }

    public function test_raw_always_returns_null(): void
    {
        $predicate = FilterPredicate::raw('status = 1', ['status']);

        $result = $predicate->evaluateFor(['status' => 1]);

        $this->assertNull($result);
    }
}
