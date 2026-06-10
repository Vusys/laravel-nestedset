<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Attributes\NestedSetScope;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;

/**
 * Minimal scoped base used only to verify that #[NestedSetScope] is
 * inherited by subclasses — ReflectionClass does not traverse parents on
 * its own. Implements the {@see HasNestedSet} contract by hand (no
 * NodeTrait) so the static-analysis `new static()` safety doesn't require
 * the class to be final, which would defeat the subclass under test.
 *
 * @property int $menu_id
 */
#[NestedSetScope('menu_id')]
abstract class ScopedBaseItem extends Model implements HasNestedSet
{
    protected $table = 'menu_items';

    public function getLft(): int
    {
        return 0;
    }

    public function getRgt(): int
    {
        return 0;
    }

    public function getDepth(): int
    {
        return 0;
    }

    public function getParentId(): int|string|null
    {
        return null;
    }

    public function getBounds(): NodeBounds
    {
        return new NodeBounds(0, 0, 0);
    }

    public function getLftName(): string
    {
        return 'lft';
    }

    public function getRgtName(): string
    {
        return 'rgt';
    }

    public function getDepthName(): string
    {
        return 'depth';
    }

    public function getParentIdName(): string
    {
        return 'parent_id';
    }

    public function isPlacedInTree(): bool
    {
        return false;
    }
}
