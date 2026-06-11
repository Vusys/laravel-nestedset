<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * SoftDeletes with the `immutable_datetime` cast on deleted_at — the cast
 * stores a CarbonImmutable, which does NOT extend Illuminate's Carbon. The
 * cascade's timestamp stringifier had to broaden from `Carbon` to
 * `DateTimeInterface` or it silently no-opped here.
 *
 * @property int $id
 * @property string $name
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property CarbonImmutable|null $deleted_at
 */
final class ImmutableSoftNode extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;
    use SoftDeletes;

    protected $table = 'immutable_soft_nodes';

    /** @var list<string> */
    protected $fillable = ['name'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'deleted_at' => 'immutable_datetime',
    ];
}
