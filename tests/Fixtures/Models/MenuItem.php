<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\NestedSet\Attributes\NestedSetScope;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\NodeCollection;
use Vusys\NestedSet\Query\TreeQueryBuilder;
use Vusys\NestedSet\Relations\AncestorsRelation;
use Vusys\NestedSet\Relations\DescendantsRelation;

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
 */
#[NestedSetScope('menu_id')]
final class MenuItem extends Model implements HasNestedSet
{
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

    /** @return AncestorsRelation<self, self> */
    public function ancestors(): AncestorsRelation
    {
        return $this->buildAncestors($this);
    }

    /** @return DescendantsRelation<self, self> */
    public function descendants(): DescendantsRelation
    {
        return $this->buildDescendants($this);
    }

    /** @return AncestorsRelation<self, self> */
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

    /**
     * @param  array<int, self>  $models
     * @return NodeCollection<int, self>
     */
    #[\Override]
    public function newCollection(array $models = []): Collection
    {
        /** @var NodeCollection<int, self> $collection */
        $collection = new NodeCollection($models);

        return $collection;
    }
}
