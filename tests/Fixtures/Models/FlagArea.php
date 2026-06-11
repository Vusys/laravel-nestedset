<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Fixture exercising boolean rollups (`boolOr` and `boolAnd`) over a
 * single source column. Both display columns share the same
 * companion pair (`__sum` of the bool-as-int + `__count`) so flipping
 * a node's `active` value updates both display rollups in a single
 * delta UPDATE.
 *
 * @property int $id
 * @property string $name
 * @property bool $active
 * @property int $value
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property bool|null $any_active
 * @property bool|null $all_active
 * @property int $active_value_total
 * @property-read Collection<int, FlagArea> $children
 * @property-read FlagArea|null $parent
 */
#[NestedSetAggregate(column: 'any_active', boolOr: 'active')]
#[NestedSetAggregate(column: 'all_active', boolAnd: 'active')]
#[NestedSetAggregate(column: 'active_value_total', sum: 'value', filter: ['active' => true])]
final class FlagArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    /** @var list<string> */
    protected $fillable = ['name', 'active', 'value'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'active' => 'boolean',
        'value' => 'integer',
        'any_active' => 'boolean',
        'all_active' => 'boolean',
        'active_value_total' => 'integer',
    ];
}
