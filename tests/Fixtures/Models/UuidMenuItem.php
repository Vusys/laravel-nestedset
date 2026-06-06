<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\NestedSet\Attributes\NestedSetScope;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\NodeTrait;

/**
 * Scoped UUID-PK fixture. Both the model's PK and the scope column
 * (`menu_id`, a UUID referencing {@see UuidMenu}) are string-typed —
 * pins that `NestedSetScopeResolver` and the per-scope index work
 * end-to-end with string identifiers.
 *
 * @property string $id
 * @property string $name
 * @property string $menu_id
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property string|null $parent_id
 * @property-read Collection<int, UuidMenuItem> $ancestors
 * @property-read Collection<int, UuidMenuItem> $descendants
 * @property-read Collection<int, UuidMenuItem> $children
 * @property-read UuidMenuItem|null $parent
 */
#[NestedSetScope('menu_id')]
final class UuidMenuItem extends Model implements MaintainsTreeAggregates
{
    use HasUuids;
    use NodeTrait;

    protected $table = 'uuid_menu_items';

    /** @var list<string> */
    protected $fillable = ['name', 'menu_id'];

    /** @var array<string, string> */
    protected $casts = [
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
    ];

    /** @return BelongsTo<UuidMenu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(UuidMenu::class, 'menu_id');
    }
}
