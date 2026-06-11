<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPath;
use Vusys\NestedSet\Attributes\NestedSetScope;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * A materialised-path model with TWO scope columns. Exercises the
 * subtree path-rewrite scope predicates against more than one column —
 * the single-column path fixtures couldn't catch a binding-order bug
 * because reversing one column is indistinguishable from forward order.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $menu_id
 * @property string $name
 * @property string|null $url_path
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 */
#[NestedSetScope(['tenant_id', 'menu_id'])]
#[NestedSetMaterialisedPath(column: 'url_path', slug: 'name')]
final class MultiScopedPathItem extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    protected $table = 'multi_scoped_path_items';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'menu_id', 'name'];

    /** @var array<string, string> */
    protected $casts = [
        'tenant_id' => 'integer',
        'menu_id' => 'integer',
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
    ];
}
