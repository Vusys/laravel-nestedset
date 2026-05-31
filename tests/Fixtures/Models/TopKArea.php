<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * Fixture for the TopK aggregate kind.
 *
 * `top_revenue_ids` stores the three descendants with the highest
 * `revenue`, as a JSON array of `[id, revenue]` pairs. The filtered
 * variant `top_active_ids` restricts the subtree scan to rows whose
 * `category = 'active'` so the filter-handling path is exercised too.
 *
 * @property int $id
 * @property string $name
 * @property int|null $revenue
 * @property string|null $category
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property array<int, array<int, int>>|null $top_revenue_ids
 * @property array<int, array<int, int>>|null $top_active_ids
 * @property-read Collection<int, TopKArea> $children
 * @property-read TopKArea|null $parent
 */
#[NestedSetAggregate(column: 'top_revenue_ids', topK: 'id', k: 3, by: 'revenue')]
#[NestedSetAggregate(
    column: 'top_active_ids',
    topK: 'id',
    k: 3,
    by: 'revenue',
    filter: ['category' => 'active'],
)]
final class TopKArea extends Model implements HasNestedSet
{
    use NodeTrait;

    protected $table = 'top_k_areas';

    /** @var list<string> */
    protected $fillable = ['name', 'revenue', 'category'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'revenue' => 'integer',
        'top_revenue_ids' => 'array',
        'top_active_ids' => 'array',
    ];
}
