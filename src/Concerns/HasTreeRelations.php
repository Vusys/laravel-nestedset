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
     * Direct children.
     *
     * No scope predicate is applied: `parent_id` references the
     * globally-unique primary key, so a child in another tree cannot
     * point at this node's id unless the data is already corrupt. The
     * old per-instance `where(scope, value)` predicate also broke
     * eager-load / withCount / whereHas, which build the relation on an
     * attribute-less prototype (scope resolved to NULL → matched
     * nothing). Mirrors the plain `parent()` BelongsTo.
     *
     * @return HasMany<static, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, $this->getParentIdName());
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
        return new AncestorsRelation(self::requireTreeBuilder($node), $node);
    }

    /**
     * @return DescendantsRelation<Model&HasNestedSet, Model&HasNestedSet>
     */
    private static function makeDescendantsRelation(Model&HasNestedSet $node): DescendantsRelation
    {
        return new DescendantsRelation(self::requireTreeBuilder($node), $node);
    }

    /**
     * Fresh query builder for $node, narrowed to {@see TreeQueryBuilder}.
     * NodeTrait wires `newEloquentBuilder` to return one — anything
     * else is a misconfigured fixture and signals a real bug, so the
     * cast failure is converted to a LogicException at the call site
     * instead of leaking past as a TypeError on the relation
     * constructor.
     *
     * @return TreeQueryBuilder<Model&HasNestedSet>
     */
    private static function requireTreeBuilder(Model&HasNestedSet $node): TreeQueryBuilder
    {
        $builder = $node->newQuery();

        if (! $builder instanceof TreeQueryBuilder) {
            throw new \LogicException('NodeTrait requires newEloquentBuilder to return TreeQueryBuilder.');
        }

        return $builder;
    }
}
