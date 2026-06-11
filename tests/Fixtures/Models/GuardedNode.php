<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * A fixture with a guarded (non-fillable) column. Every other fixture
 * declares all columns fillable, so the deep-copy force-fill path —
 * which must reproduce guarded columns rather than mass-assigning and
 * silently zeroing them — was untestable.
 *
 * @property int $id
 * @property string $name
 * @property int $tickets
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 */
final class GuardedNode extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'guarded_nodes';

    /** @var list<string> `tickets` deliberately omitted — guarded. */
    protected $fillable = ['name'];

    /** @var array<string, string> */
    protected $casts = [
        'tickets' => 'integer',
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
    ];
}
