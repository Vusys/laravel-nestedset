<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Fixture model for exclusive aggregate coverage and the raw-SQL
 * filter path. Exclusive aggregates skip incremental delta maintenance —
 * the value lives at zero until `fixAggregates()` (or
 * `withFreshAggregates()` on read) computes the descendants-only
 * rollup. The `active_tickets_total` column uses a raw SQL filter
 * (`active = 1`), which is also incremental-maintenance-skipped because
 * the predicate cannot be evaluated in PHP. Both rely on
 * `fixAggregates()` for recovery.
 *
 * @property int $id
 * @property string $name
 * @property int $tickets
 * @property int $active 0 or 1 (kept integer for cross-DB raw-SQL portability)
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int $tickets_total
 * @property int $descendants_total
 * @property int $descendants_count
 * @property int|null $descendants_max
 * @property int $active_tickets_total
 * @property int $active_count
 * @property int|null $active_min_tickets
 * @property int|null $active_max_tickets
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
#[NestedSetAggregate(
    column: 'active_count',
    count: true,
    filterRaw: 'active = 1',
    filterRawWatches: ['active'],
)]
#[NestedSetAggregate(
    column: 'active_min_tickets',
    min: 'tickets',
    filterRaw: 'active = 1',
    filterRawWatches: ['active'],
)]
#[NestedSetAggregate(
    column: 'active_max_tickets',
    max: 'tickets',
    filterRaw: 'active = 1',
    filterRawWatches: ['active'],
)]
final class Branch extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

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
        'active_count' => 'integer',
        'active_min_tickets' => 'integer',
        'active_max_tickets' => 'integer',
    ];
}
