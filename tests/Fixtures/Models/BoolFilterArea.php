<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * A boolean-cast column used in an Equality filter on a SUM/COUNT
 * aggregate. Exercises the cast-aware filter read on the create path:
 * `active = 1` (int) must satisfy `filter: ['active' => true]` once cast.
 *
 * @property int $id
 * @property string $name
 * @property int $tickets
 * @property bool $active
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int $active_tickets
 * @property int $active_count
 */
#[NestedSetAggregate(column: 'active_tickets', sum: 'tickets', filter: ['active' => true])]
#[NestedSetAggregate(column: 'active_count', count: true, filter: ['active' => true])]
final class BoolFilterArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'bool_filter_areas';

    /** @var list<string> */
    protected $fillable = ['name', 'tickets', 'active'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'tickets' => 'integer',
        'active' => 'boolean',
        'active_tickets' => 'integer',
        'active_count' => 'integer',
    ];
}
