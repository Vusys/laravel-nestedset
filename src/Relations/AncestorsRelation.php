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
final class AncestorsRelation extends BaseRelation
{
    public function addConstraints(): void
    {
        if (! self::$constraints) {
            return;
        }

        $query = $this->treeQuery();
        $query->whereAncestorOf($this->parent->getBounds());

        // Each scope (per-tenant menu, per-post comment thread, …)
        // restarts its lft sequence at 1, so two trees with
        // overlapping bounds are the common case. Without these
        // predicates the relation would return ancestors from any
        // tree whose bounds happen to span the parent's lft/rgt.
        foreach (NestedSetScopeResolver::valuesFor($this->parent) as $col => $value) {
            $query->where($query->qualifyColumn($col), '=', $value);
        }

        // Root-to-parent order (the documented contract) is lft ascending.
        $query->orderBy($query->qualifyColumn($query->lftColumn()));
    }

    protected function matches(HasNestedSet $model, HasNestedSet $related): bool
    {
        // Cross-scope rows can't match — the eager-load query already
        // filters them out, but `match()` runs against the returned
        // collection without knowing about scope. Guard here too so
        // that even hand-fed result sets don't attach to the wrong
        // declaring model. Use the resolver's permissive comparison
        // (int 5 == "5") so a driver-typed scope value doesn't drop a
        // legitimate match.
        if ($model instanceof Model && $related instanceof Model
            && ! NestedSetScopeResolver::sameScope($model, $related)) {
            return false;
        }

        return $related->getBounds()->contains($model->getBounds());
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
            $q->where($lftColumn, '<', $bounds->lft)
                ->where($rgtColumn, '>', $bounds->rgt);

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
        return "{$table}.{$rgt} between {$hash}.{$lft} and {$hash}.{$rgt} "
            ."and {$table}.{$lft} <> {$hash}.{$lft}";
    }
}
