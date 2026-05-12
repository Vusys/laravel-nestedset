<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;

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

        $this->treeQuery()->whereDescendantOf($this->parent->getBounds());
    }

    protected function matches(HasNestedSet $model, HasNestedSet $related): bool
    {
        return $model->getBounds()->contains($related->getBounds());
    }

    protected function addEagerConstraint(
        Builder $query,
        HasNestedSet $model,
        string $lftColumn,
        string $rgtColumn,
    ): void {
        $bounds = $model->getBounds();

        $query->orWhere(static function (Builder $q) use ($lftColumn, $rgtColumn, $bounds): void {
            $q->where($lftColumn, '>', $bounds->lft)
                ->where($rgtColumn, '<', $bounds->rgt);
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
