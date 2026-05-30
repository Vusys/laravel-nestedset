<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\NestedSet\Attributes\NestedSetScope;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Tests\Fixtures\Factories\MenuItemFactory;

/**
 * @property int $id
 * @property string $name
 * @property int $menu_id
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property-read Collection<int, MenuItem> $ancestors
 * @property-read Collection<int, MenuItem> $descendants
 * @property-read Collection<int, MenuItem> $children
 * @property-read MenuItem|null $parent
 */
#[NestedSetScope('menu_id')]
final class MenuItem extends Model implements HasNestedSet
{
    /** @use HasFactory<MenuItemFactory> */
    use HasFactory;

    use NodeTrait;

    /** @var list<string> */
    protected $fillable = ['name', 'menu_id'];

    /** @var array<string, string> */
    protected $casts = [
        'menu_id' => 'integer',
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
    ];

    /** @return BelongsTo<Menu, $this> */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /** @return Factory<self> */
    protected static function newFactory(): Factory
    {
        return MenuItemFactory::new();
    }
}
