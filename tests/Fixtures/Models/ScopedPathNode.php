<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPath;
use Vusys\NestedSet\Attributes\NestedSetScope;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * A materialised-path model with a TWO-column scope and an `attribute:`
 * source (raw, so multibyte is preserved — `slug:` would transliterate it
 * away). Exercises the subtree-path-rewrite UPDATE on the two axes that
 * single-scope slug fixtures can't reach:
 *
 *  - multi-column scope binding order (predicate placeholders must line up
 *    with the appended scope values), and
 *  - character- vs byte-indexed SUBSTRING offset for multibyte prefixes.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $menu_id
 * @property string $name
 * @property string|null $path
 */
#[NestedSetScope(['tenant_id', 'menu_id'])]
#[NestedSetMaterialisedPath(column: 'path', attribute: 'name')]
final class ScopedPathNode extends Model implements MaintainsTreeAggregates
{
    use NodeTrait;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['name', 'tenant_id', 'menu_id'];

    /** @var array<string, string> */
    protected $casts = [
        'tenant_id' => 'integer',
        'menu_id' => 'integer',
    ];
}
