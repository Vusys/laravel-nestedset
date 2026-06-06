<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPath;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * @property int $id
 * @property string $name
 * @property string|null $url_path
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property-read Collection<int, SluggedCategory> $children
 * @property-read SluggedCategory|null $parent
 */
#[NestedSetMaterialisedPath(column: 'url_path', slug: 'name')]
final class SluggedCategory extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    /** @var list<string> */
    protected $fillable = ['name'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
    ];
}
