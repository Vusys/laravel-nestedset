<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * @property int $id
 * @property string $name
 * @property int $tickets
 * @property string|null $type
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int $fire_tickets
 * @property int $fire_count
 * @property int|null $water_max
 * @property int $has_tickets
 */
#[NestedSetAggregate(column: 'fire_tickets', sum: 'tickets', filter: ['type' => 'fire'])]
#[NestedSetAggregate(column: 'fire_count', count: true, filter: ['type' => 'fire'])]
#[NestedSetAggregate(column: 'water_max', max: 'tickets', filter: ['type' => 'water'])]
#[NestedSetAggregate(column: 'has_tickets', count: true, filterNotNull: 'tickets')]
final class TypedArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'typed_areas';

    /** @var list<string> */
    protected $fillable = ['name', 'tickets', 'type'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'tickets' => 'integer',
        'fire_tickets' => 'integer',
        'fire_count' => 'integer',
        'water_max' => 'integer',
        'has_tickets' => 'integer',
    ];
}
