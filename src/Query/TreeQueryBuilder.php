<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Columns;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Query\Aggregates\Read\FreshAggregateProjector;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;
use Vusys\NestedSet\Support\Runtime;

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
        $v = Runtime::config('nestedset.columns.lft');

        return is_string($v) ? $v : Columns::LFT;
    }

    public function rgtColumn(): string
    {
        $v = Runtime::config('nestedset.columns.rgt');

        return is_string($v) ? $v : Columns::RGT;
    }

    public function parentIdColumn(): string
    {
        $v = Runtime::config('nestedset.columns.parent_id');

        return is_string($v) ? $v : Columns::PARENT_ID;
    }

    public function depthColumn(): string
    {
        $v = Runtime::config('nestedset.columns.depth');

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

        $this->applyBoundsScope($bounds);

        if ($andSelf) {
            $this->whereBetween($lft, [$bounds->lft, $bounds->rgt]);

            return $this;
        }

        $this->where($lft, '>', $bounds->lft);
        $this->where($rgt, '<', $bounds->rgt);

        return $this;
    }

    /**
     * Applies the scope-column predicates carried on $bounds (populated by
     * a scoped model's getBounds()). A no-op for unscoped models and for
     * bounds built without a model, so it never changes behaviour where
     * there's no scope to enforce.
     */
    private function applyBoundsScope(NodeBounds $bounds): void
    {
        foreach ($bounds->scope as $column => $value) {
            $this->where($this->qualifyColumn($column), $value);
        }
    }

    public function whereDescendantOrSelf(NodeBounds $bounds): static
    {
        return $this->whereDescendantOf($bounds, andSelf: true);
    }

    public function whereAncestorOf(NodeBounds $bounds, bool $andSelf = false): static
    {
        $lft = $this->qualifyColumn($this->lftColumn());
        $rgt = $this->qualifyColumn($this->rgtColumn());

        $this->applyBoundsScope($bounds);

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
        $grammar = $this->getQuery()->getGrammar();
        $lft = $grammar->wrap($this->qualifyColumn($this->lftColumn()));
        $rgt = $grammar->wrap($this->qualifyColumn($this->rgtColumn()));

        $this->whereRaw(new TreeExpression("{$rgt} = {$lft} + 1"));

        return $this;
    }

    public function whereIsAfter(NodeBounds $bounds): static
    {
        $this->applyBoundsScope($bounds);
        $this->where(
            $this->qualifyColumn($this->lftColumn()),
            '>',
            $bounds->rgt,
        );

        return $this;
    }

    public function whereIsBefore(NodeBounds $bounds): static
    {
        $this->applyBoundsScope($bounds);
        $this->where(
            $this->qualifyColumn($this->rgtColumn()),
            '<',
            $bounds->lft,
        );

        return $this;
    }

    public function withDepth(string $as = 'depth'): static
    {
        $grammar = $this->getQuery()->getGrammar();
        $col = $grammar->wrap($this->qualifyColumn($this->depthColumn()));
        $alias = $grammar->wrap($as);

        // Only widen to '*' when no explicit select() has narrowed the
        // columns — otherwise this would silently re-add every column and
        // undo the caller's projection.
        $base = $this->getQuery()->columns === null ? ['*'] : [];
        $this->addSelect([...$base, new TreeExpression("{$col} as {$alias}")]);

        return $this;
    }

    public function defaultOrder(): static
    {
        $this->orderByScopeColumns();
        $this->orderBy($this->qualifyColumn($this->lftColumn()), 'asc');

        return $this;
    }

    public function reversed(): static
    {
        // Scope columns still ascend so each tree stays a contiguous block;
        // only the within-tree lft order reverses.
        $this->orderByScopeColumns();
        $this->orderBy($this->qualifyColumn($this->lftColumn()), 'desc');

        return $this;
    }

    /**
     * Prepends an ascending order on the model's scope columns (if any) so
     * `defaultOrder()`/`reversed()` are deterministic on scoped models —
     * every tree restarts lft at 1, so without grouping by scope first the
     * rows of different trees interleave arbitrarily across backends.
     */
    private function orderByScopeColumns(): void
    {
        $model = $this->getModel();
        if (! $model instanceof HasNestedSet) {
            return;
        }

        foreach (NestedSetScopeResolver::columns($model::class) as $scopeColumn) {
            $this->orderBy($this->qualifyColumn($scopeColumn), 'asc');
        }
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

    /**
     * Adds correlated-subquery SELECT columns that return freshly-
     * computed aggregate values alongside any stored ones. See
     * {@see FreshAggregateProjector::applyFreshSelects()} for accepted
     * `$columns` shapes.
     *
     * Passing `null` or omitting the argument selects every user-facing
     * declared aggregate on the model. Useful for drift detection:
     *
     *     foreach (Area::query()->withFreshAggregates([
     *         'tickets_total_fresh' => Aggregate::sum('tickets'),
     *     ])->get() as $area) {
     *         if ($area->tickets_total !== $area->tickets_total_fresh) {
     *             // stored value disagrees with the source-of-truth
     *         }
     *     }
     *
     * **Aliasing & save() — read-only contract.** When a fresh select
     * reuses an existing column name (the default for the no-arg form
     * and for string-keyed `['tickets_total']` requests), PDO collapses
     * the duplicate output columns and only the fresh value reaches the
     * model. The stored value in `$original` is replaced with the fresh
     * value at hydration time, so subsequent `save()`s
     * - do *not* write the fresh value back to the stored column (it
     *   tracks as unchanged), and
     * - feed the wrong "before" snapshot into aggregate-maintenance
     *   delta computation if a downstream save dirties the source
     *   column.
     *
     * Treat models returned by `withFreshAggregates()` as **read-only
     * snapshots**. To preserve the stored value alongside the fresh
     * value, pass an ad-hoc Aggregate keyed by a distinct alias (as in
     * the example above).
     *
     * @param  array<int|string, string|Aggregate>|null  $columns
     */
    public function withFreshAggregates(?array $columns = null): static
    {
        FreshAggregateProjector::applyFreshSelects($this, $columns);

        return $this;
    }
}
