<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPath;
use Vusys\NestedSet\Attributes\NestedSetScope;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * @property int $id
 * @property int $menu_id
 * @property string $name
 * @property string|null $url_path
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 */
#[NestedSetScope('menu_id')]
#[NestedSetMaterialisedPath(column: 'url_path', slug: 'name')]
final class ScopedSluggedMenuItem extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    public $timestamps = true;

    protected $table = 'scoped_slugged_menu_items';

    /** @var list<string> */
    protected $fillable = ['menu_id', 'name'];

    /** @var array<string, string> */
    protected $casts = [
        'menu_id' => 'integer',
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
    ];
}
