<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Regression fixture for MIN/MAX over a decimal-typed source column.
 * Earlier versions cast the stored extremum to int when composing the
 * cheap-skip WHERE clause, so a `decimal(10,2)` source like 9.99 was
 * compared against the truncated 9 and the recompute silently no-op'd
 * — leaving ancestors with stale extremes. See
 * `HasNestedSetAggregates::applyAggregateOnDelete` and
 * `…::captureMoveSubtreeContributions`.
 *
 * @property int $id
 * @property string $name
 * @property string $price
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property string|null $price_min
 * @property string|null $price_max
 * @property-read Collection<int, PricedArea> $children
 * @property-read PricedArea|null $parent
 */
#[NestedSetAggregate(column: 'price_min', min: 'price')]
#[NestedSetAggregate(column: 'price_max', max: 'price')]
final class PricedArea extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $table = 'priced_areas';

    /** @var list<string> */
    protected $fillable = ['name', 'price'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'price' => 'decimal:2',
        'price_min' => 'decimal:2',
        'price_max' => 'decimal:2',
    ];
}
