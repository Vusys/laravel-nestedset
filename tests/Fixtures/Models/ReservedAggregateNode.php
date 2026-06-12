<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Reserved-word structural columns (`left`/`right`/`order`) *plus*
 * aggregates, so the aggregate maintenance (delta + recompute) and
 * fresh-read SQL paths are forced to grammar-quote the structural columns
 * they interpolate into subqueries. Companion to {@see ReservedColumnNode}
 * (which has no aggregates and covers the bare mutation/repair path).
 *
 * `weight_total` is an inclusive SUM (DeltaMaintenance); `weight_sub` is an
 * exclusive SUM and `weight_max` a MAX, both of which take the
 * chain-recompute path (RecomputeMaintenance) that interpolates the bounds
 * predicate raw.
 *
 * @property int $id
 * @property string $name
 * @property int $weight
 * @property int $left
 * @property int $right
 * @property int $order
 * @property int|null $parent
 * @property int $weight_total
 * @property int $weight_sub
 * @property int|null $weight_max
 */
#[NestedSetAggregate(column: 'weight_total', sum: 'weight')]
#[NestedSetAggregate(column: 'weight_sub', sum: 'weight', exclusive: true)]
#[NestedSetAggregate(column: 'weight_max', max: 'weight', exclusive: true)]
final class ReservedAggregateNode extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'reserved_aggregate_nodes';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['name', 'weight'];

    /** @var array<string, string> */
    protected $casts = [
        'left' => 'integer',
        'right' => 'integer',
        'order' => 'integer',
        'parent' => 'integer',
        'weight' => 'integer',
        'weight_total' => 'integer',
        'weight_sub' => 'integer',
        'weight_max' => 'integer',
    ];

    public function getLftName(): string
    {
        return 'left';
    }

    public function getRgtName(): string
    {
        return 'right';
    }

    public function getDepthName(): string
    {
        return 'order';
    }

    public function getParentIdName(): string
    {
        return 'parent';
    }
}
