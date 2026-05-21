<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Fixture exercising geometric-mean and harmonic-mean rollups over a
 * single source column (`value`). Both display columns share the same
 * `__count` companion; each adds its own companion sum (`__sum_log` for
 * geometric, `__sum_recip` for harmonic). Maintenance on every mutation
 * keeps all companions and the derived display columns in sync.
 *
 * @property int $id
 * @property string $name
 * @property float|null $value
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property float|null $value_gmean
 * @property float|null $value_hmean
 * @property-read Collection<int, MeanArea> $children
 * @property-read MeanArea|null $parent
 */
#[NestedSetAggregate(column: 'value_gmean', geometricMean: 'value')]
#[NestedSetAggregate(column: 'value_hmean', harmonicMean: 'value')]
final class MeanArea extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @var list<string> */
    protected $fillable = ['name', 'value'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'value' => 'decimal:4',
        'value_gmean' => 'decimal:4',
        'value_hmean' => 'decimal:4',
    ];
}
