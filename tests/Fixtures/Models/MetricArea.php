<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Fixture exercising the maths-aggregate kinds added in M1 — variance
 * and stddev, both in population (default) and sample variants.
 *
 * The four display columns share a single source column (`tickets`),
 * so the registry auto-promotes one Sum, one SumSq, and one Count
 * companion per declaration; all four display columns are
 * delta-maintained from those companions on every mutation.
 *
 * @property int $id
 * @property string $name
 * @property int $tickets
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property float|null $tickets_variance
 * @property float|null $tickets_stddev
 * @property float|null $tickets_variance_samp
 * @property float|null $tickets_stddev_samp
 * @property-read Collection<int, MetricArea> $children
 * @property-read MetricArea|null $parent
 */
#[NestedSetAggregate(column: 'tickets_variance', variance: 'tickets')]
#[NestedSetAggregate(column: 'tickets_stddev', stddev: 'tickets')]
#[NestedSetAggregate(column: 'tickets_variance_samp', variance: 'tickets', sample: true)]
#[NestedSetAggregate(column: 'tickets_stddev_samp', stddev: 'tickets', sample: true)]
final class MetricArea extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $table = 'metric_areas';

    /** @var list<string> */
    protected $fillable = ['name', 'tickets'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'tickets' => 'integer',
        'tickets_variance' => 'decimal:6',
        'tickets_stddev' => 'decimal:6',
        'tickets_variance_samp' => 'decimal:6',
        'tickets_stddev_samp' => 'decimal:6',
    ];
}
