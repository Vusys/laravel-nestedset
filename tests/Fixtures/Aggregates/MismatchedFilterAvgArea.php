<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Aggregates;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Captures the trap of declaring SUM with a filter alongside AVG over
 * the same source with NO filter (or a different filter).
 *
 * Today the registry indexes by source column alone, so the filtered
 * Sum is treated as a valid companion for the unfiltered Avg — meaning
 * the AVG display column ends up reading from the filtered Sum, which
 * is semantically wrong. The expected behaviour: auto-promote a
 * separate (internal) Sum whose filter matches the AVG, rather than
 * adopting the mismatched user Sum.
 */
final class MismatchedFilterAvgArea extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @return list<AggregateDefinition> */
    protected function nestedSetAggregates(): array
    {
        return [
            Aggregate::sum('tickets')->filter(['type' => 'fire'])->into('fire_total'),
            Aggregate::avg('tickets')->into('tickets_avg'),
        ];
    }
}
