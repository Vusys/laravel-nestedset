<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * @template TRelatedModel of Model&HasNestedSet
 * @template TDeclaringModel of Model&HasNestedSet
 *
 * @extends BaseRelation<TRelatedModel, TDeclaringModel>
 */
final class DescendantsRelation extends BaseRelation
{
    public function addConstraints(): void
    {
        if (! self::$constraints) {
            return;
        }

        $query = $this->treeQuery();
        $query->whereDescendantOf($this->parent->getBounds());

        // Each scope (per-tenant menu, per-post comment thread, …)
        // restarts its lft sequence at 1, so two trees with
        // overlapping bounds are the common case. Without these
        // predicates the relation would return descendants from any
        // tree whose bounds fall inside the parent's lft/rgt.
        foreach (NestedSetScopeResolver::valuesFor($this->parent) as $col => $value) {
            $query->where($query->qualifyColumn($col), '=', $value);
        }
    }

    protected function matches(HasNestedSet $model, HasNestedSet $related): bool
    {
        // Cross-scope rows can't match — the eager-load query already
        // filters them out, but `match()` runs against the returned
        // collection without knowing about scope. Guard here too so
        // that even hand-fed result sets don't attach to the wrong
        // declaring model.
        if ($model instanceof Model && $related instanceof Model) {
            foreach (NestedSetScopeResolver::columns($model::class) as $col) {
                if ($model->getAttribute($col) !== $related->getAttribute($col)) {
                    return false;
                }
            }
        }

        return $model->getBounds()->contains($related->getBounds());
    }

    protected function addEagerConstraint(
        Builder $query,
        HasNestedSet $model,
        string $lftColumn,
        string $rgtColumn,
        array $scope,
    ): void {
        $bounds = $model->getBounds();

        $query->orWhere(static function (Builder $q) use ($lftColumn, $rgtColumn, $bounds, $scope): void {
            $q->where($lftColumn, '>', $bounds->lft)
                ->where($rgtColumn, '<', $bounds->rgt);

            foreach ($scope as $col => $value) {
                $q->where($col, '=', $value);
            }
        });
    }

    protected function relationExistenceCondition(
        string $hash,
        string $table,
        string $lft,
        string $rgt,
    ): string {
        return "{$hash}.{$lft} between {$table}.{$lft} + 1 and {$table}.{$rgt}";
    }
}
