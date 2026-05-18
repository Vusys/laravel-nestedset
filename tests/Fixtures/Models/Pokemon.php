<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\FireCountListener;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\HalfWeightedPowerListener;
use Vusys\NestedSet\Tests\Fixtures\Aggregates\WeightedPowerListener;

/**
 * @property int $id
 * @property string $name
 * @property string|null $type
 * @property int $base_power
 * @property int $level
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int $weighted_power
 * @property int $fire_count
 * @property float $half_weighted_power
 */
#[NestedSetAggregateListener(column: 'weighted_power', listener: WeightedPowerListener::class, operation: AggregateFunction::Sum)]
#[NestedSetAggregateListener(column: 'fire_count', listener: FireCountListener::class, operation: AggregateFunction::Sum)]
#[NestedSetAggregateListener(column: 'half_weighted_power', listener: HalfWeightedPowerListener::class, operation: AggregateFunction::Sum)]
final class Pokemon extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $table = 'pokemon';

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
        'weighted_power' => 'integer',
        'fire_count' => 'integer',
        'half_weighted_power' => 'float',
    ];
}
