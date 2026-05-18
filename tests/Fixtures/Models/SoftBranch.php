<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Vusys\NestedSet\Aggregates\Strategy\RecomputeMaintenance;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Branch + SoftDeletes. Exercises the snapshot-semantics path through
 * {@see RecomputeMaintenance} —
 * the chain-recompute path used by exclusive and raw-filter SQL
 * aggregates needs to filter trashed rows on both sides of the
 * recompute (inner subquery + outer ancestor scan).
 *
 * @property int $id
 * @property string $name
 * @property int $tickets
 * @property int $active
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int $tickets_total
 * @property int $descendants_total
 * @property int $descendants_count
 * @property int|null $descendants_max
 * @property int $active_tickets_total
 * @property Carbon|null $deleted_at
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
final class SoftBranch extends Model implements HasNestedSet
{
    use NodeTrait;
    use SoftDeletes;

    protected $table = 'soft_branches';

    /** @var list<string> */
    protected $fillable = ['name', 'tickets', 'active'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'tickets' => 'integer',
        'active' => 'integer',
        'tickets_total' => 'integer',
        'descendants_total' => 'integer',
        'descendants_count' => 'integer',
        'descendants_max' => 'integer',
        'active_tickets_total' => 'integer',
    ];
}
