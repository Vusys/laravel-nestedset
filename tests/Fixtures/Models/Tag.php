<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Custom-primary-key fixture. The PK column is `tag_id`, not `id`.
 * Every package SQL path must resolve the PK via `getKeyName()` —
 * the original hardcoded `'id'` literals would either error
 * ("column tag_id not found") or silently target a phantom `id`
 * column on backends that tolerate it.
 *
 * @property int $tag_id
 * @property string $name
 * @property int $tickets
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int $tickets_total
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
final class Tag extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $primaryKey = 'tag_id';

    /** @var list<string> */
    protected $fillable = ['name', 'tickets'];

    /** @var array<string, string> */
    protected $casts = [
        'tag_id' => 'integer',
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'tickets' => 'integer',
        'tickets_total' => 'integer',
    ];
}
