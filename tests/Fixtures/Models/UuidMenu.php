<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Parent table for the scoped UUID fixture {@see UuidMenuItem}. Both
 * the menu's PK and the menu_items' foreign-key column are UUID-typed,
 * so the scope predicate string-matches end-to-end.
 *
 * @property string $id
 * @property string $name
 */
class UuidMenu extends Model
{
    use HasUuids;

    protected $table = 'uuid_menus';

    protected $fillable = ['name'];

    /** @return HasMany<UuidMenuItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(UuidMenuItem::class, 'menu_id');
    }
}
