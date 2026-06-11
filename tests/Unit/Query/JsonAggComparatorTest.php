<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Query\Aggregates\Maintenance\AggregateValueComparator;

/**
 * JsonAgg / JsonObjectAgg element order is not guaranteed across
 * backends — MySQL's JSON_ARRAYAGG ignores ORDER BY, so a freshly
 * recomputed array can hold the same members in a different order from
 * the incrementally-maintained stored value. Drift detection must
 * therefore compare them as multisets. TopK is a genuine ranking and
 * stays order-sensitive.
 */
final class JsonAggComparatorTest extends TestCase
{
    #[Test]
    public function json_agg_ignores_element_order(): void
    {
        $def = Aggregate::jsonAgg('id')->into('descendant_ids');

        $this->assertTrue(
            AggregateValueComparator::aggregateValuesEqual($def, '[1,2,3]', '[3,1,2]'),
            'same members in a different order must not be reported as drift',
        );
    }

    #[Test]
    public function json_agg_of_objects_ignores_element_order(): void
    {
        $def = Aggregate::jsonAgg(['id' => 'id', 'label' => 'name'])->into('descendant_summary');

        $stored = '[{"id":1,"label":"a"},{"id":2,"label":"b"}]';
        $fresh = '[{"label":"b","id":2},{"id":1,"label":"a"}]';

        $this->assertTrue(
            AggregateValueComparator::aggregateValuesEqual($def, $stored, $fresh),
            'same objects in a different order (and different key order) must compare equal',
        );
    }

    #[Test]
    public function json_agg_still_detects_a_genuinely_different_member_set(): void
    {
        $def = Aggregate::jsonAgg('id')->into('descendant_ids');

        $this->assertFalse(
            AggregateValueComparator::aggregateValuesEqual($def, '[1,2,3]', '[1,2,4]'),
            'a changed member is real drift',
        );
        $this->assertFalse(
            AggregateValueComparator::aggregateValuesEqual($def, '[1,2,3]', '[1,2]'),
            'a missing member is real drift',
        );
    }

    #[Test]
    public function top_k_remains_order_sensitive(): void
    {
        $def = Aggregate::topK('score', 3)->into('top_scores');

        $this->assertFalse(
            AggregateValueComparator::aggregateValuesEqual($def, '[3,2,1]', '[1,2,3]'),
            'TopK is a ranking — element order is significant',
        );
        $this->assertTrue(
            AggregateValueComparator::aggregateValuesEqual($def, '[3,2,1]', '[3,2,1]'),
        );
    }
}
