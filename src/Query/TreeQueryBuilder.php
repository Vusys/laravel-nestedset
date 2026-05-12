<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Columns;
use Vusys\NestedSet\NodeBounds;

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class TreeQueryBuilder extends Builder
{
    // ----------------------------------------------------------------
    // Column name resolution — overridable in Phase 8 via NodeTrait
    // ----------------------------------------------------------------

    public function lftColumn(): string
    {
        $v = config('nestedset.columns.lft');

        return is_string($v) ? $v : Columns::LFT;
    }

    public function rgtColumn(): string
    {
        $v = config('nestedset.columns.rgt');

        return is_string($v) ? $v : Columns::RGT;
    }

    public function parentIdColumn(): string
    {
        $v = config('nestedset.columns.parent_id');

        return is_string($v) ? $v : Columns::PARENT_ID;
    }

    public function depthColumn(): string
    {
        $v = config('nestedset.columns.depth');

        return is_string($v) ? $v : Columns::DEPTH;
    }

    // ----------------------------------------------------------------
    // Read queries
    //
    // Each method mutates this builder in place and returns $this so the
    // `: static` return type holds across chained Eloquent calls (which
    // are typed against the parent class, not the subclass).
    // ----------------------------------------------------------------

    public function whereDescendantOf(NodeBounds $bounds, bool $andSelf = false): static
    {
        $lft = $this->qualifyColumn($this->lftColumn());
        $rgt = $this->qualifyColumn($this->rgtColumn());

        if ($andSelf) {
            $this->whereBetween($lft, [$bounds->lft, $bounds->rgt]);

            return $this;
        }

        $this->where($lft, '>', $bounds->lft);
        $this->where($rgt, '<', $bounds->rgt);

        return $this;
    }

    public function whereDescendantOrSelf(NodeBounds $bounds): static
    {
        return $this->whereDescendantOf($bounds, andSelf: true);
    }

    public function whereAncestorOf(NodeBounds $bounds, bool $andSelf = false): static
    {
        $lft = $this->qualifyColumn($this->lftColumn());
        $rgt = $this->qualifyColumn($this->rgtColumn());

        if ($andSelf) {
            $this->where($lft, '<=', $bounds->lft);
            $this->where($rgt, '>=', $bounds->rgt);

            return $this;
        }

        $this->where($lft, '<', $bounds->lft);
        $this->where($rgt, '>', $bounds->rgt);

        return $this;
    }

    public function whereAncestorOrSelf(NodeBounds $bounds): static
    {
        return $this->whereAncestorOf($bounds, andSelf: true);
    }

    public function whereIsRoot(): static
    {
        $this->whereNull($this->qualifyColumn($this->parentIdColumn()));

        return $this;
    }

    public function whereIsLeaf(): static
    {
        $lft = $this->qualifyColumn($this->lftColumn());
        $rgt = $this->qualifyColumn($this->rgtColumn());

        $this->whereRaw(new TreeExpression("{$rgt} = {$lft} + 1"));

        return $this;
    }

    public function whereIsAfter(NodeBounds $bounds): static
    {
        $this->where(
            $this->qualifyColumn($this->lftColumn()),
            '>',
            $bounds->rgt,
        );

        return $this;
    }

    public function whereIsBefore(NodeBounds $bounds): static
    {
        $this->where(
            $this->qualifyColumn($this->rgtColumn()),
            '<',
            $bounds->lft,
        );

        return $this;
    }

    public function withDepth(string $as = 'depth'): static
    {
        $col = $this->qualifyColumn($this->depthColumn());

        $this->addSelect(['*', new TreeExpression("{$col} as {$as}")]);

        return $this;
    }

    public function defaultOrder(): static
    {
        $this->orderBy($this->qualifyColumn($this->lftColumn()), 'asc');

        return $this;
    }

    public function reversed(): static
    {
        $this->orderBy($this->qualifyColumn($this->lftColumn()), 'desc');

        return $this;
    }

    public function withoutRoot(): static
    {
        $this->whereNotNull($this->qualifyColumn($this->parentIdColumn()));

        return $this;
    }

    public function leaves(): static
    {
        return $this->whereIsLeaf();
    }

    /** @return TModel|null */
    public function root(): ?Model
    {
        return $this->whereIsRoot()->first();
    }

    public function ancestorsOf(NodeBounds $bounds): static
    {
        return $this->whereAncestorOf($bounds);
    }

    public function descendantsOf(NodeBounds $bounds): static
    {
        return $this->whereDescendantOf($bounds);
    }
}
