<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Tests\Fixtures\Factories\AreaFactory;

/**
 * Fixture model exercising the aggregate column feature. The five
 * declared aggregates exercise every supported function. The migration
 * also provides the internal AVG companion columns
 * (`tickets_avg__sum` / `tickets_avg__count`) so a later phase that
 * actually maintains them has somewhere to write — Phase B itself
 * never reads those columns from a fresh query.
 *
 * @property int $id
 * @property string $name
 * @property int $tickets
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int $tickets_total
 * @property int $tickets_count_all
 * @property float|null $tickets_avg
 * @property int|null $tickets_min
 * @property int|null $tickets_max
 * @property-read Collection<int, Area> $children
 * @property-read Area|null $parent
 */
#[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
#[NestedSetAggregate(column: 'tickets_count_all', count: true)]
#[NestedSetAggregate(column: 'tickets_avg', avg: 'tickets')]
#[NestedSetAggregate(column: 'tickets_min', min: 'tickets')]
#[NestedSetAggregate(column: 'tickets_max', max: 'tickets')]
final class Area extends Model implements MaintainsTreeAggregates
{
    /** @use HasFactory<AreaFactory> */
    use HasFactory;

    use NodeTrait;

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
        'tickets_count_all' => 'integer',
        'tickets_avg' => 'decimal:4',
        'tickets_min' => 'integer',
        'tickets_max' => 'integer',
    ];

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return AreaFactory::new();
    }
}
