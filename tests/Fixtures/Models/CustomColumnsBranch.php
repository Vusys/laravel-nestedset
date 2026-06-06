<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Mirrors Branch's aggregate setup (inclusive SUM, exclusive SUM /
 * COUNT / MAX, raw-filter SUM) but renames every nested-set
 * structural column. The model overrides `getLftName` /
 * `getRgtName` / `getDepthName` / `getParentIdName` to point at the
 * renamed columns.
 *
 * Test target: any query builder, raw SQL, or maintenance path that
 * hardcoded the default column names instead of going through the
 * model's overrides will fail against this fixture.
 *
 * @property int $id
 * @property string $name
 * @property int $tickets
 * @property int $active
 * @property int $tree_lft
 * @property int $tree_rgt
 * @property int $tree_depth
 * @property int|null $tree_parent_id
 * @property int $tickets_total
 * @property int $descendants_total
 * @property int $descendants_count
 * @property int|null $descendants_max
 * @property int $active_tickets_total
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
#[NestedSetAggregate(column: 'descendants_total', sum: 'tickets', exclusive: true)]
#[NestedSetAggregate(column: 'descendants_count', count: true, exclusive: true)]
#[NestedSetAggregate(column: 'descendants_max', max: 'tickets', exclusive: true)]
#[NestedSetAggregate(
    column: 'active_tickets_total',
    sum: 'tickets',
    filterRaw: 'active = 1',
    filterRawWatches: ['active'],
)]
final class CustomColumnsBranch extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'custom_column_branches';

    /** @var list<string> */
    protected $fillable = ['name', 'tickets', 'active'];

    /** @var array<string, string> */
    protected $casts = [
        'tree_lft' => 'integer',
        'tree_rgt' => 'integer',
        'tree_depth' => 'integer',
        'tree_parent_id' => 'integer',
        'tickets' => 'integer',
        'active' => 'integer',
        'tickets_total' => 'integer',
        'descendants_total' => 'integer',
        'descendants_count' => 'integer',
        'descendants_max' => 'integer',
        'active_tickets_total' => 'integer',
    ];

    public function getLftName(): string
    {
        return 'tree_lft';
    }

    public function getRgtName(): string
    {
        return 'tree_rgt';
    }

    public function getDepthName(): string
    {
        return 'tree_depth';
    }

    public function getParentIdName(): string
    {
        return 'tree_parent_id';
    }
}
