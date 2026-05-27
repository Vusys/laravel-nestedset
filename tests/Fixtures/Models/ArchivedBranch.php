<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * SoftDeletes + custom DELETED_AT column. Pins that every package
 * code path resolves the soft-delete column via getDeletedAtColumn()
 * rather than hard-coding `'deleted_at'`:
 *  - HasSoftDeleteTree cascade (soft-delete + restore)
 *  - NodeTrait::deleted guard against double-decrement on
 *    forceDelete-after-soft-delete
 *  - replicate() clearing the custom column on the clone
 *  - FreshAggregateProjector bulk fresh-aggregate paths excluding
 *    trashed descendants from withFreshAggregates()
 *
 * @property int $id
 * @property string $name
 * @property int $tickets
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int $tickets_total
 * @property Carbon|null $archived_at
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
final class ArchivedBranch extends Model implements HasNestedSet
{
    use NodeTrait;
    use SoftDeletes;

    public const string DELETED_AT = 'archived_at';

    protected $table = 'archived_branches';

    /** @var list<string> */
    protected $fillable = ['name', 'tickets'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'tickets' => 'integer',
        'tickets_total' => 'integer',
        'archived_at' => 'datetime',
    ];
}
