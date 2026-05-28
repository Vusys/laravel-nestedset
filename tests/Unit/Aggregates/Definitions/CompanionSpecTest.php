<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates\Definitions;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSpec;

final class CompanionSpecTest extends TestCase
{
    public function test_column_for_appends_suffix_to_display_column(): void
    {
        $spec = new CompanionSpec('__sum', AggregateFunction::Sum);

        $this->assertSame('tickets_avg__sum', $spec->columnFor('tickets_avg'));
    }

    public function test_column_for_does_not_drop_existing_underscores(): void
    {
        $spec = new CompanionSpec('__count', AggregateFunction::Count);

        $this->assertSame('weighted_avg__count', $spec->columnFor('weighted_avg'));
    }

    public function test_avg_promotion_uses_the_existing_avg_companion_suffixes(): void
    {
        $companions = AggregateFunction::Avg->companionSet();

        $columns = array_map(
            static fn (CompanionSpec $spec): string => $spec->columnFor('price_avg'),
            $companions,
        );

        $this->assertSame(['price_avg__sum', 'price_avg__count'], $columns);
    }
}
