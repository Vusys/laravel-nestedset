<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Regression fixture for issue #178 — MIN/MAX lifecycle hooks treated
 * a NULL source value as 0, lowering ancestor extrema (or clobbering
 * NULL extrema) instead of contributing nothing as SQL MIN/MAX do.
 *
 * Uses a nullable `score` source so a NULL row can actually round-trip
 * through the DB and reach the create / restore appliers.
 *
 * @property int $id
 * @property string $name
 * @property int|null $score
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int|null $score_min
 * @property int|null $score_max
 * @property-read Collection<int, NullableMetricArea> $children
 * @property-read NullableMetricArea|null $parent
 */
#[NestedSetAggregate(column: 'score_min', min: 'score')]
#[NestedSetAggregate(column: 'score_max', max: 'score')]
final class NullableMetricArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'nullable_metric_areas';

    /** @var list<string> */
    protected $fillable = ['name', 'score'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'score' => 'integer',
        'score_min' => 'integer',
        'score_max' => 'integer',
    ];
}
