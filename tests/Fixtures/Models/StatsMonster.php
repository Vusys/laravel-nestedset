<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\ScoreListener;

/**
 * Fixture exercising the listener-side companion-derived operations
 * (Variance, Stddev, GeometricMean, HarmonicMean) plus the listener
 * `filter:` / `filterNotNull:` attribute parameters.
 *
 * Migration columns for each variance/geomean/harmonic declaration
 * include the auto-promoted companion columns — see the migration for
 * the full layout. Filter-only declarations (fire_score_sum,
 * non_null_score_avg) demonstrate that filters compose with both Sum
 * and the AVG companion shape.
 *
 * @property int $id
 * @property string $name
 * @property string|null $type
 * @property float|null $score
 * @property bool $active
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property float|null $score_variance
 * @property float $score_variance__sum
 * @property float $score_variance__sum_sq
 * @property int $score_variance__count
 * @property float|null $score_stddev
 * @property float $score_stddev__sum
 * @property float $score_stddev__sum_sq
 * @property int $score_stddev__count
 * @property float|null $score_geomean
 * @property float $score_geomean__sum_log
 * @property int $score_geomean__count
 * @property float|null $score_harmean
 * @property float $score_harmean__sum_recip
 * @property int $score_harmean__count
 * @property int $fire_score_sum
 * @property float|null $non_null_score_avg
 * @property float $non_null_score_avg__sum
 * @property int $non_null_score_avg__count
 * @property float|null $score_min
 */
#[NestedSetAggregateListener(column: 'score_variance', listener: ScoreListener::class, operation: AggregateFunction::Variance)]
#[NestedSetAggregateListener(column: 'score_stddev', listener: ScoreListener::class, operation: AggregateFunction::Stddev)]
#[NestedSetAggregateListener(column: 'score_geomean', listener: ScoreListener::class, operation: AggregateFunction::GeometricMean)]
#[NestedSetAggregateListener(column: 'score_harmean', listener: ScoreListener::class, operation: AggregateFunction::HarmonicMean)]
#[NestedSetAggregateListener(column: 'fire_score_sum', listener: ScoreListener::class, operation: AggregateFunction::Sum, filter: ['type' => 'fire'])]
#[NestedSetAggregateListener(column: 'non_null_score_avg', listener: ScoreListener::class, operation: AggregateFunction::Avg, filterNotNull: 'score')]
#[NestedSetAggregateListener(column: 'score_min', listener: ScoreListener::class, operation: AggregateFunction::Min)]
final class StatsMonster extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $table = 'stats_monsters';

    /** @var list<string> */
    protected $fillable = ['name', 'type', 'score', 'active'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'score' => 'float',
        'active' => 'boolean',
        'score_variance' => 'float',
        'score_variance__sum' => 'float',
        'score_variance__sum_sq' => 'float',
        'score_variance__count' => 'integer',
        'score_stddev' => 'float',
        'score_stddev__sum' => 'float',
        'score_stddev__sum_sq' => 'float',
        'score_stddev__count' => 'integer',
        'score_geomean' => 'float',
        'score_geomean__sum_log' => 'float',
        'score_geomean__count' => 'integer',
        'score_harmean' => 'float',
        'score_harmean__sum_recip' => 'float',
        'score_harmean__count' => 'integer',
        'fire_score_sum' => 'float',
        'non_null_score_avg' => 'float',
        'non_null_score_avg__sum' => 'float',
        'non_null_score_avg__count' => 'integer',
        'score_min' => 'float',
    ];
}
