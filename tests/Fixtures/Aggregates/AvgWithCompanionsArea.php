<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * User declared SUM and COUNT(source) alongside AVG over the same
 * source. Registry should NOT auto-promote — it should leave the AVG
 * to reference the user's declarations at maintenance time.
 *
 * Note: COUNT(source) (not COUNT(*)) is the SQL-correct companion for
 * AVG since standard SQL defines `AVG(x) = SUM(x) / COUNT(x)`. The
 * attribute form only exposes `count: true` (= COUNT(*)); the
 * non-null-skipping variant is reachable only via the method override.
 */
final class AvgWithCompanionsArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    /** @return list<AggregateDefinition> */
    protected function nestedSetAggregates(): array
    {
        return [
            Aggregate::sum('tickets')->into('tickets_sum'),
            Aggregate::count('tickets')->into('tickets_count'),
            Aggregate::avg('tickets')->into('tickets_avg'),
        ];
    }
}
