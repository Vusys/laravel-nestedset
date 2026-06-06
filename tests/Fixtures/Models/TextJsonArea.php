<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Fixture for the collection-aggregate kinds — exercises every code
 * path through DistinctCount / StringAgg (plain + DISTINCT) / JsonAgg
 * (scalar + multi-column) / JsonObjectAgg.
 *
 * @property int $id
 * @property string $name
 * @property string|null $tag
 * @property string|null $owner
 * @property bool $published
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int $distinct_owners
 * @property string|null $child_names
 * @property string|null $distinct_tags
 * @property array<int, int|string>|null $descendant_ids
 * @property array<int, array<string, mixed>>|null $descendant_summary
 * @property array<string, string>|null $name_lookup
 * @property-read Collection<int, TextJsonArea> $children
 * @property-read TextJsonArea|null $parent
 */
#[NestedSetAggregate(column: 'distinct_owners', distinctCount: 'owner')]
#[NestedSetAggregate(column: 'child_names', stringAgg: 'name', separator: ', ')]
#[NestedSetAggregate(column: 'distinct_tags', stringAgg: 'tag', separator: ', ', distinct: true)]
#[NestedSetAggregate(column: 'descendant_ids', jsonAgg: 'id')]
#[NestedSetAggregate(column: 'descendant_summary', jsonAgg: ['id', 'name'])]
#[NestedSetAggregate(column: 'name_lookup', jsonObjectAgg: ['key' => 'name', 'value' => 'tag'])]
final class TextJsonArea extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'text_json_areas';

    /** @var list<string> */
    protected $fillable = ['name', 'tag', 'owner', 'published'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
        'published' => 'boolean',
        'distinct_owners' => 'integer',
        'descendant_ids' => 'array',
        'descendant_summary' => 'array',
        'name_lookup' => 'array',
    ];
}
