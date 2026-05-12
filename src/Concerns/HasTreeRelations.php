<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Query\TreeQueryBuilder;
use Vusys\NestedSet\Relations\AncestorsRelation;
use Vusys\NestedSet\Relations\DescendantsRelation;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Eloquent relations between a nested-set node and its tree neighbours.
 *
 * @mixin Model
 * @mixin HasNestedSet
 */
trait HasTreeRelations
{
    /**
     * @return BelongsTo<static, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, $this->getParentIdName());
    }

    /**
     * Direct children. Scope columns are applied so a multi-tree table
     * doesn't return rows from another tree that happen to share a
     * parent_id value.
     *
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        $relation = $this->hasMany(static::class, $this->getParentIdName());

        foreach (NestedSetScopeResolver::valuesFor($this) as $column => $value) {
            $relation->where($column, '=', $value);
        }

        return $relation;
    }

    /**
     * @return AncestorsRelation<Model&HasNestedSet, Model&HasNestedSet>
     */
    public function ancestors(): AncestorsRelation
    {
        return self::makeAncestorsRelation($this);
    }

    /**
     * @return DescendantsRelation<Model&HasNestedSet, Model&HasNestedSet>
     */
    public function descendants(): DescendantsRelation
    {
        return self::makeDescendantsRelation($this);
    }

    /**
     * Widens $this back to its declared interface type so the invariant
     * relation generics match the declared @return.
     *
     * @return AncestorsRelation<Model&HasNestedSet, Model&HasNestedSet>
     */
    private static function makeAncestorsRelation(Model&HasNestedSet $node): AncestorsRelation
    {
        $builder = $node->newQuery();

        if (! $builder instanceof TreeQueryBuilder) {
            throw new \LogicException('NodeTrait requires newEloquentBuilder to return TreeQueryBuilder.');
        }

        return new AncestorsRelation($builder, $node);
    }

    /**
     * @return DescendantsRelation<Model&HasNestedSet, Model&HasNestedSet>
     */
    private static function makeDescendantsRelation(Model&HasNestedSet $node): DescendantsRelation
    {
        $builder = $node->newQuery();

        if (! $builder instanceof TreeQueryBuilder) {
            throw new \LogicException('NodeTrait requires newEloquentBuilder to return TreeQueryBuilder.');
        }

        return new DescendantsRelation($builder, $node);
    }
}
