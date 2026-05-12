<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;

/**
 * @property int $id
 * @property string $name
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int|null $level computed alias for the depth column (see withDepth())
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Category> $ancestors
 * @property-read Collection<int, Category> $descendants
 * @property-read Collection<int, Category> $children
 * @property-read Category|null $parent
 */
final class Category extends Model implements HasNestedSet
{
    use NodeTrait;
    use SoftDeletes;

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
