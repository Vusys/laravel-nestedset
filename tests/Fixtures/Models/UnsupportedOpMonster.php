<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\FireCountListener;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\WeightedPowerListener;

/**
 * Misconfiguration fixture: declares listener aggregates with operations
 * the PHP listener path cannot compute (Variance is rejected with a
 * LogicException; Median with an AggregateConfigurationException). The
 * definitions are constructible — only bitwise ops are refused at
 * construction — so the guard fires lazily when a fresh read is taken.
 *
 * Reuses the `monsters` table and the existing listener classes; it is
 * never saved through Eloquent (that would trip the same guard during
 * maintenance), only read against a pre-seeded row.
 */
// One column per unsupported listener op, so a freshAggregate('…')
// call drives a different match arm each time. Column names are read
// by the resolver — the underlying table doesn't need the columns to
// exist, only that we never try to write the computed value back.
#[NestedSetAggregateListener(column: 'op_variance', listener: WeightedPowerListener::class, operation: AggregateFunction::Variance)]
#[NestedSetAggregateListener(column: 'op_stddev', listener: WeightedPowerListener::class, operation: AggregateFunction::Stddev)]
#[NestedSetAggregateListener(column: 'op_weighted_avg', listener: WeightedPowerListener::class, operation: AggregateFunction::WeightedAvg)]
#[NestedSetAggregateListener(column: 'op_bool_or', listener: WeightedPowerListener::class, operation: AggregateFunction::BoolOr)]
#[NestedSetAggregateListener(column: 'op_bool_and', listener: WeightedPowerListener::class, operation: AggregateFunction::BoolAnd)]
#[NestedSetAggregateListener(column: 'op_geometric_mean', listener: WeightedPowerListener::class, operation: AggregateFunction::GeometricMean)]
#[NestedSetAggregateListener(column: 'op_harmonic_mean', listener: WeightedPowerListener::class, operation: AggregateFunction::HarmonicMean)]
#[NestedSetAggregateListener(column: 'op_distinct_count', listener: WeightedPowerListener::class, operation: AggregateFunction::DistinctCount)]
#[NestedSetAggregateListener(column: 'op_string_agg', listener: WeightedPowerListener::class, operation: AggregateFunction::StringAgg)]
#[NestedSetAggregateListener(column: 'op_json_agg', listener: WeightedPowerListener::class, operation: AggregateFunction::JsonAgg)]
#[NestedSetAggregateListener(column: 'op_json_object_agg', listener: WeightedPowerListener::class, operation: AggregateFunction::JsonObjectAgg)]
#[NestedSetAggregateListener(column: 'op_median', listener: FireCountListener::class, operation: AggregateFunction::Median)]
#[NestedSetAggregateListener(column: 'op_percentile', listener: FireCountListener::class, operation: AggregateFunction::Percentile)]
final class UnsupportedOpMonster extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $table = 'monsters';

    /** @var list<string> */
    protected $fillable = ['name', 'type', 'base_power', 'level'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'base_power' => 'integer',
        'level' => 'integer',
    ];
}
