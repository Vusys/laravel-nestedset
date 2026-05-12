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
final class AncestorsRelation extends BaseRelation
{
    public function addConstraints(): void
    {
        if (! self::$constraints) {
            return;
        }

        $this->treeQuery()->whereAncestorOf($this->parent->getBounds());
    }

    protected function matches(HasNestedSet $model, HasNestedSet $related): bool
    {
        return $related->getBounds()->contains($model->getBounds());
    }

    protected function addEagerConstraint(
        Builder $query,
        HasNestedSet $model,
        string $lftColumn,
        string $rgtColumn,
    ): void {
        $bounds = $model->getBounds();

        $query->orWhere(static function (Builder $q) use ($lftColumn, $rgtColumn, $bounds): void {
            $q->where($lftColumn, '<', $bounds->lft)
                ->where($rgtColumn, '>', $bounds->rgt);
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
