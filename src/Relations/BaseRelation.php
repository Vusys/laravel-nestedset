<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\NestedSetLogicException;
use Vusys\NestedSet\Query\TreeExpression;
use Vusys\NestedSet\Query\TreeQueryBuilder;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * @template TRelatedModel of Model&HasNestedSet
 * @template TDeclaringModel of Model&HasNestedSet
 *
 * @extends Relation<TRelatedModel, TDeclaringModel, EloquentCollection<int, TRelatedModel>>
 */
abstract class BaseRelation extends Relation
{
    /**
     * @param  TreeQueryBuilder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     */
    public function __construct(TreeQueryBuilder $query, Model&HasNestedSet $parent)
    {
        parent::__construct($query, $parent);
    }

    /**
     * Returns true when $related is the ancestor/descendant the subclass
     * expects, given $model as the parent perspective. Used during match()
     * to attach eagerly loaded results to the right parent.
     */
    abstract protected function matches(HasNestedSet $model, HasNestedSet $related): bool;

    /**
     * Adds an OR-constraint to $query for $model so that one query can fetch
     * the union of ancestors/descendants across many parent models.
     *
     * Receives a {@see Builder} (not {@see TreeQueryBuilder}) because the
     * inner where() closure rebinds to a fresh query builder whose specific
     * subtype the type system can't track through `setQuery()`. Subclasses
     * use only plain Builder methods on this argument; tree-specific column
     * names come in via $lftColumn/$rgtColumn.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  array<string, mixed>  $scope  scope column → value pairs for $model; the
     *                                       subclass must add equality predicates so
     *                                       cross-scope rows don't leak into the OR
     *                                       block.
     */
    abstract protected function addEagerConstraint(
        Builder $query,
        HasNestedSet $model,
        string $lftColumn,
        string $rgtColumn,
        array $scope,
    ): void;

    public function getResults(): EloquentCollection
    {
        /** @var EloquentCollection<int, TRelatedModel> */
        return $this->query->get();
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     * @return array<int, TDeclaringModel>
     */
    public function initRelation(array $models, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     */
    public function addEagerConstraints(array $models): void
    {
        $tree = $this->treeQuery();
        $lft = $tree->qualifyColumn($tree->lftColumn());
        $rgt = $tree->qualifyColumn($tree->rgtColumn());

        $tree->where(function (Builder $inner) use ($models, $lft, $rgt): void {
            foreach ($models as $model) {
                $scope = NestedSetScopeResolver::valuesFor($model);
                $this->addEagerConstraint($inner, $model, $lft, $rgt, $scope);
            }
        });

        // Order by lft so the documented order holds: ancestors
        // root-to-parent, descendants DFS pre-order. match() distributes
        // results by scope/bounds and preserves this relative order
        // within each parent's collection.
        $tree->orderBy($lft);
    }

    /**
     * Narrows $this->query to TreeQueryBuilder. The constructor enforces this
     * — this method exists only to give the type system the same guarantee.
     *
     * @return TreeQueryBuilder<TRelatedModel>
     */
    protected function treeQuery(): TreeQueryBuilder
    {
        if (! $this->query instanceof TreeQueryBuilder) {
            throw new NestedSetLogicException(
                'NestedSet relations require the related model to use TreeQueryBuilder.',
            );
        }

        return $this->query;
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     * @param  EloquentCollection<int, TRelatedModel>  $results
     * @return array<int, TDeclaringModel>
     */
    public function match(array $models, EloquentCollection $results, $relation): array
    {
        foreach ($models as $model) {
            $matched = $this->related->newCollection();

            foreach ($results as $related) {
                if ($this->matches($model, $related)) {
                    $matched->push($related);
                }
            }

            $model->setRelation($relation, $matched);
        }

        return $models;
    }

    /**
     * Supports `whereHas('ancestors'|'descendants', ...)`. The condition is
     * a self-join expressed in raw SQL because the comparison is between
     * two aliases of the same table.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  array<int, string>|string  $columns
     * @return Builder<TRelatedModel>
     */
    #[\Override]
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        // Replicate so setTable() doesn't corrupt the shared $this->parent —
        // soft-delete scopes etc. read the table name at scope-apply time and
        // a mutation on the original model leaks into the outer query.
        /** @var TreeQueryBuilder<TRelatedModel> $sub */
        $sub = $this->parent->replicate()->newQuery()->select($columns);

        $relatedTable = $sub->getModel()->getTable();
        $hash = $this->getRelationCountHash();

        $sub->from("{$relatedTable} as {$hash}");
        $sub->getModel()->setTable($hash);

        $grammar = $sub->getQuery()->getGrammar();

        $condition = $this->relationExistenceCondition(
            $grammar->wrapTable($hash),
            $grammar->wrapTable($relatedTable),
            $grammar->wrap($sub->lftColumn()),
            $grammar->wrap($sub->rgtColumn()),
        );

        // Scope columns must equality-join across the two aliases so a
        // whereHas() on a multi-tree model doesn't surface "ancestors /
        // descendants" rows from another tree whose lft/rgt bounds happen
        // to overlap (each scope restarts its lft at 1, so overlaps are
        // the common case).
        foreach (NestedSetScopeResolver::columns($this->parent::class) as $scopeCol) {
            $scopeWrapped = $grammar->wrap($scopeCol);
            $condition .= " and {$grammar->wrapTable($hash)}.{$scopeWrapped} = {$grammar->wrapTable($relatedTable)}.{$scopeWrapped}";
        }

        $sub->whereRaw(new TreeExpression($condition));

        return $sub;
    }

    /**
     * Returns a SQL fragment that constrains the related rows (aliased $hash)
     * relative to the parent rows (aliased $table) for the whereHas() join.
     */
    abstract protected function relationExistenceCondition(
        string $hash,
        string $table,
        string $lft,
        string $rgt,
    ): string;

    #[\Override]
    public function getRelationCountHash($incrementJoinCount = true): string
    {
        $count = $incrementJoinCount ? self::$selfJoinCount++ : self::$selfJoinCount;

        return "vusys_nestedset_{$count}";
    }
}
