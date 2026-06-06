<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Query\Aggregates\Sql\FragmentSplicer;

/**
 * Regression fixture for the raw-SQL filter path with single-quoted
 * string literals — the most common shape outside `column = N`. The
 * `open_or_closed_*` aggregates use `status IN ('open', 'closed')`,
 * exercising the sentinel-replacement / parameter-binding stream in
 * {@see FragmentSplicer} on
 * literals whose quote character also serves as the SQL string
 * delimiter.
 *
 * @property int $id
 * @property string $name
 * @property int $points
 * @property string $status
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int $points_total
 * @property int $open_or_closed_points_total
 * @property int $open_or_closed_count
 */
#[NestedSetAggregate(column: 'points_total', sum: 'points')]
#[NestedSetAggregate(
    column: 'open_or_closed_points_total',
    sum: 'points',
    filterRaw: "status IN ('open', 'closed')",
    filterRawWatches: ['status'],
)]
#[NestedSetAggregate(
    column: 'open_or_closed_count',
    count: true,
    filterRaw: "status IN ('open', 'closed')",
    filterRawWatches: ['status'],
)]
final class StatusBranch extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @var list<string> */
    protected $fillable = ['name', 'points', 'status'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'points' => 'integer',
        'points_total' => 'integer',
        'open_or_closed_points_total' => 'integer',
        'open_or_closed_count' => 'integer',
    ];
}
