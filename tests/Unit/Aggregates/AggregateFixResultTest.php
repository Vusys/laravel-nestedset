<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Aggregates;

use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\AggregateFixResult;

final class AggregateFixResultTest extends TestCase
{
    public function test_records_total_rows_updated_and_per_column_breakdown(): void
    {
        $result = new AggregateFixResult(
            totalRowsUpdated: 12,
            perColumn: ['tickets_total' => 8, 'tickets_max' => 4],
        );

        $this->assertSame(12, $result->totalRowsUpdated);
        $this->assertSame(['tickets_total' => 8, 'tickets_max' => 4], $result->perColumn);
    }

    public function test_has_drift_when_at_least_one_row_was_updated(): void
    {
        $this->assertTrue(
            (new AggregateFixResult(totalRowsUpdated: 1, perColumn: ['tickets_total' => 1]))->hasDrift(),
        );
    }

    public function test_no_drift_when_zero_rows_updated(): void
    {
        $this->assertFalse(
            (new AggregateFixResult(totalRowsUpdated: 0, perColumn: []))->hasDrift(),
        );
    }

    public function test_per_column_can_be_empty_for_a_model_with_no_aggregates(): void
    {
        $result = new AggregateFixResult(totalRowsUpdated: 0, perColumn: []);

        $this->assertSame([], $result->perColumn);
        $this->assertFalse($result->hasDrift());
    }
}
