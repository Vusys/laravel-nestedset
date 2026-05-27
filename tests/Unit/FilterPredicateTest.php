<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicateKind;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

final class FilterPredicateTest extends TestCase
{
    public function test_equality_stores_conditions_kind_and_watch_columns(): void
    {
        $predicate = FilterPredicate::equality(['type' => 'fire', 'active' => true]);

        $this->assertSame(FilterPredicateKind::Equality, $predicate->getKind());
        $this->assertSame(['type' => 'fire', 'active' => true], $predicate->getConditions());
        $this->assertSame(['type', 'active'], $predicate->watchColumns());
    }

    public function test_equality_with_empty_array_throws(): void
    {
        $this->expectException(AggregateConfigurationException::class);
        $this->expectExceptionMessage('at least one condition');

        FilterPredicate::equality([]);
    }

    public function test_not_null_stores_kind_and_watch_column(): void
    {
        $predicate = FilterPredicate::notNull('deleted_at');

        $this->assertSame(FilterPredicateKind::NotNull, $predicate->getKind());
        $this->assertSame('deleted_at', $predicate->getNotNullColumn());
        $this->assertSame(['deleted_at'], $predicate->watchColumns());
    }

    public function test_raw_stores_sql_and_explicit_watches(): void
    {
        $predicate = FilterPredicate::raw('status = 1', ['status']);

        $this->assertSame(FilterPredicateKind::Raw, $predicate->getKind());
        $this->assertSame('status = 1', $predicate->getRawSql());
        $this->assertSame(['status'], $predicate->watchColumns());
    }

    public function test_raw_with_explicit_empty_watches_has_no_watch_columns(): void
    {
        // `[]` is valid for genuinely column-free predicates — the
        // parameter is just required so callers can't silently omit
        // it and assume the package will infer column dependencies
        // from the SQL.
        $predicate = FilterPredicate::raw('1 = 1', []);

        $this->assertSame([], $predicate->watchColumns());
    }

    public function test_equality_getters_return_null_for_non_equality_fields(): void
    {
        $predicate = FilterPredicate::equality(['type' => 'fire']);

        $this->assertNull($predicate->getNotNullColumn());
        $this->assertNull($predicate->getRawSql());
    }

    public function test_not_null_getters_return_empty_conditions_and_null_raw(): void
    {
        $predicate = FilterPredicate::notNull('col');

        $this->assertSame([], $predicate->getConditions());
        $this->assertNull($predicate->getRawSql());
    }

    public function test_raw_getters_return_empty_conditions_and_null_not_null_column(): void
    {
        $predicate = FilterPredicate::raw('1 = 1', []);

        $this->assertSame([], $predicate->getConditions());
        $this->assertNull($predicate->getNotNullColumn());
    }
}
