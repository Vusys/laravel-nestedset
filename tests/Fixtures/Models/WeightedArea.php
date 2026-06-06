<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Fixture exercising weighted-average aggregates. The display column
 * `value_wavg` rides on two auto-promoted companion sums —
 * `value_wavg__sum_wx` (= `Σ weight · value`) and
 * `value_wavg__sum_w` (= `Σ weight`). Maintenance on every mutation
 * keeps both companions and the derived display column in sync.
 *
 * @property int $id
 * @property string $name
 * @property float|null $value
 * @property float|null $weight
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property float|null $value_wavg
 * @property-read Collection<int, WeightedArea> $children
 * @property-read WeightedArea|null $parent
 */
#[NestedSetAggregate(column: 'value_wavg', weightedAvg: 'value', weight: 'weight')]
final class WeightedArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    /** @var list<string> */
    protected $fillable = ['name', 'value', 'weight'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'value' => 'decimal:2',
        'weight' => 'decimal:2',
        'value_wavg' => 'decimal:4',
    ];
}
