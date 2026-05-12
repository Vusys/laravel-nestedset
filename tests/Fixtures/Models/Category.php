<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Query\TreeQueryBuilder;
use Vusys\NestedSet\Relations\AncestorsRelation;
use Vusys\NestedSet\Relations\DescendantsRelation;

/**
 * @property int $id
 * @property string $name
 * @property int $lft
 * @property int $rgt
 * @property int $depth
 * @property int|null $parent_id
 * @property int|null $level computed alias for the depth column (see withDepth())
 * @property-read Collection<int, Category> $ancestors
 * @property-read Collection<int, Category> $descendants
 */
final class Category extends Model implements HasNestedSet
{
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

    public function getLft(): int
    {
        return $this->lft;
    }

    public function getRgt(): int
    {
        return $this->rgt;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function getParentId(): ?int
    {
        return $this->parent_id;
    }

    public function getBounds(): NodeBounds
    {
        return new NodeBounds(
            lft: $this->lft,
            rgt: $this->rgt,
            depth: $this->depth,
        );
    }

    /**
     * @return AncestorsRelation<self, self>
     */
    public function ancestors(): AncestorsRelation
    {
        return $this->buildAncestors($this);
    }

    /**
     * @return DescendantsRelation<self, self>
     */
    public function descendants(): DescendantsRelation
    {
        return $this->buildDescendants($this);
    }

    /**
     * Widens $this to self so the relation's invariant TDeclaringModel
     * matches self rather than $this(self).
     *
     * @return AncestorsRelation<self, self>
     */
    private function buildAncestors(self $node): AncestorsRelation
    {
        /** @var TreeQueryBuilder<self> $builder */
        $builder = $node->newQuery();

        return new AncestorsRelation($builder, $node);
    }

    /** @return DescendantsRelation<self, self> */
    private function buildDescendants(self $node): DescendantsRelation
    {
        /** @var TreeQueryBuilder<self> $builder */
        $builder = $node->newQuery();

        return new DescendantsRelation($builder, $node);
    }

    /**
     * Use TreeQueryBuilder so relation classes can reach the tree methods.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return TreeQueryBuilder<self>
     */
    #[\Override]
    public function newEloquentBuilder($query): Builder
    {
        /** @var TreeQueryBuilder<self> $builder */
        $builder = new TreeQueryBuilder($query);

        return $builder;
    }
}
