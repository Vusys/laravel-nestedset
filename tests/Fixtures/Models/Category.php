<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int|null $level computed alias for the depth column (see withDepth())
 */
class Category extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['name'];
}
