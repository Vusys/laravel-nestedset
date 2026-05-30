<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Unit\Walker;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;

/**
 * Minimal in-memory Model that satisfies the HasNestedSet contract.
 *
 * Unit walker tests need Model & HasNestedSet instances but should not
 * boot the full NodeTrait (which wires aggregate / mutation / soft-
 * delete listeners that depend on a Laravel container). The stub keeps
 * the surface area down to the four columns the walker actually reads.
 *
 * @property int|null $id
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property string|null $name
 */
final class StubNode extends Model implements HasNestedSet
{
    protected $guarded = [];

    public $timestamps = false;

    public $table = 'stub_nodes';

    /** @var array<string, string> */
    protected $casts = [
        'id' => 'integer',
        'lft' => 'integer',
        'rgt' => 'integer',
        'depth' => 'integer',
        'parent_id' => 'integer',
    ];

    public function getLft(): int
    {
        return $this->asInt($this->getAttribute('lft'));
    }

    public function getRgt(): int
    {
        return $this->asInt($this->getAttribute('rgt'));
    }

    public function getDepth(): int
    {
        return $this->asInt($this->getAttribute('depth'));
    }

    /**
     * StubNode uses integer parent ids exclusively — narrower than the
     * `int|string|null` interface allows, but a valid covariant return.
     */
    public function getParentId(): ?int
    {
        $v = $this->getAttribute('parent_id');
        if ($v === null) {
            return null;
        }

        return $this->asInt($v);
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v;
        }

        return 0;
    }

    public function getBounds(): NodeBounds
    {
        return new NodeBounds(
            lft: $this->getLft(),
            rgt: $this->getRgt(),
            depth: $this->getDepth(),
        );
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
}
