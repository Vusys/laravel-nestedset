<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPath;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * @property int $id
 * @property string $name
 * @property string $display_name
 * @property string|null $url_path
 * @property string|null $crumb_path
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 */
#[NestedSetMaterialisedPath(column: 'url_path', slug: 'name')]
#[NestedSetMaterialisedPath(column: 'crumb_path', attribute: 'display_name', separator: ' > ', wrap: false)]
final class MultiPathCategory extends Model implements HasNestedSet
{
    use NodeTrait;

    /** @var list<string> */
    protected $fillable = ['name', 'display_name'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
    ];
}
