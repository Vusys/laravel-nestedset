<?php

declare(strict_types=1);

namespace Vusys\NestedSet;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Vusys\NestedSet\Concerns\HasNodeInspection;
use Vusys\NestedSet\Concerns\HasSoftDeleteTree;
use Vusys\NestedSet\Concerns\HasTreeMutation;
use Vusys\NestedSet\Concerns\HasTreeRelations;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Query\TreeQueryBuilder;
use Vusys\NestedSet\Query\TreeRepairBuilder;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Adds the full nested-set API to an Eloquent model.
 *
 * Models that use this trait must also `implements HasNestedSet`. The
 * trait provides default implementations of all five interface methods
 * (getLft/getRgt/getDepth/getParentId/getBounds) so the contract is
 * satisfied out of the box; user code can override the column-name
 * accessors below to point at non-default columns.
 *
 * @mixin Model
 */
trait NodeTrait
{
    use HasNodeInspection;
    use HasSoftDeleteTree;
    use HasTreeMutation;
    use HasTreeRelations;

    /**
     * Wires the model's `saving` event so any operation queued by
     * appendToNode/etc. is dispatched right before Eloquent issues the
     * INSERT or UPDATE — preserving Laravel's auto-boot convention.
     */
    public static function bootNodeTrait(): void
    {
        static::saving(static function (Model $node): void {
            if ($node instanceof HasNestedSet && method_exists($node, 'callPendingAction')) {
                $node->callPendingAction();
            }
        });
    }

    // ----------------------------------------------------------------
    // HasNestedSet defaults (column accessors)
    // ----------------------------------------------------------------

    public function getLft(): int
    {
        return $this->vusysIntAttr($this->getLftName());
    }

    public function getRgt(): int
    {
        return $this->vusysIntAttr($this->getRgtName());
    }

    public function getDepth(): int
    {
        return $this->vusysIntAttr($this->getDepthName());
    }

    public function getParentId(): ?int
    {
        $v = $this->getAttribute($this->getParentIdName());

        if ($v === null) {
            return null;
        }

        return $this->vusysIntAttr($this->getParentIdName());
    }

    /**
     * Reads an attribute that we expect to be numeric (the model's casts
     * guarantee this in practice) and returns it as int — narrows mixed
     * for the type system without an unchecked cast.
     */
    private function vusysIntAttr(string $name): int
    {
        $v = $this->getAttribute($name);

        if (is_int($v)) {
            return $v;
        }

        if (is_numeric($v)) {
            return (int) $v;
        }

        throw new \LogicException("Attribute {$name} is not numeric");
    }

    public function getBounds(): NodeBounds
    {
        return new NodeBounds(
            lft: $this->getLft(),
            rgt: $this->getRgt(),
            depth: $this->getDepth(),
        );
    }

    public function getLftName(): string
    {
        $v = config('nestedset.columns.lft');

        return is_string($v) ? $v : Columns::LFT;
    }

    public function getRgtName(): string
    {
        $v = config('nestedset.columns.rgt');

        return is_string($v) ? $v : Columns::RGT;
    }

    public function getParentIdName(): string
    {
        $v = config('nestedset.columns.parent_id');

        return is_string($v) ? $v : Columns::PARENT_ID;
    }

    public function getDepthName(): string
    {
        $v = config('nestedset.columns.depth');

        return is_string($v) ? $v : Columns::DEPTH;
    }

    // ----------------------------------------------------------------
    // Eloquent overrides
    // ----------------------------------------------------------------

    /**
     * Narrowed return type (TreeQueryBuilder rather than the base
     * Eloquent Builder) so Larastan can resolve tree-specific methods —
     * whereDescendantOf, withDepth, defaultOrder, etc. — on
     * `Model::query()` results. Returning the base Builder causes
     * Larastan to forward calls to it and miss every package method
     * that does not happen to match its `where*` dynamic-where pattern.
     *
     * Generic parameter is left open ({@see Model}) because
     * `new TreeQueryBuilder($query)` cannot bind the template to the
     * concrete subclass — Larastan resolves per-model methods through
     * its own builder-helper machinery instead.
     *
     * @param  Builder  $query
     * @return TreeQueryBuilder<Model>
     */
    public function newEloquentBuilder($query): TreeQueryBuilder
    {
        return new TreeQueryBuilder($query);
    }

    /**
     * @param  array<int, static>  $models
     * @return NodeCollection<int, static>
     */
    public function newCollection(array $models = []): EloquentCollection
    {
        return new NodeCollection($models);
    }

    // ----------------------------------------------------------------
    // Tree repair — class-level entry points
    // ----------------------------------------------------------------

    /**
     * Validate the tree. Returns false when every node satisfies the
     * nested-set invariants.
     *
     * On scoped models (e.g. MenuItem with #[NestedSetScope('menu_id')])
     * an $anchor is required so the check stays within one tree —
     * walking the whole table is rarely what you want and is rejected
     * to prevent footguns.
     */
    public static function isBroken(?HasNestedSet $anchor = null): bool
    {
        return self::repairBuilder($anchor)->isBroken();
    }

    /**
     * Returns per-category counts of nested-set invariant violations.
     * Pass an $anchor for scoped models (required when the class declares
     * #[NestedSetScope] or getScopeAttributes()).
     *
     * @return array{invalid_bounds: int, duplicate_lft: int, duplicate_rgt: int, orphans: int}
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function countErrors(?HasNestedSet $anchor = null): array
    {
        return self::repairBuilder($anchor)->countErrors();
    }

    /**
     * Rebuilds lft/rgt/depth from parent_id (the column we treat as
     * authoritative for parent/child relationships) and returns a result
     * summary. On a scoped model, $anchor is required and the repair stays
     * inside that one tree; passing $anchor on an unscoped model is
     * permitted and scopes the rebuild to that anchor's subtree.
     *
     * @throws ScopeViolationException When called without an anchor on a scoped model.
     */
    public static function fixTree(?HasNestedSet $anchor = null): TreeFixResult
    {
        $builder = self::repairBuilder($anchor);

        $rootId = null;

        if ($anchor instanceof Model) {
            $key = $anchor->getKey();
            $rootId = is_numeric($key) ? (int) $key : null;
        }

        return $builder->fixTree($rootId);
    }

    private static function repairBuilder(?HasNestedSet $anchor): TreeRepairBuilder
    {
        $scopeColumns = NestedSetScopeResolver::columns(static::class);

        if ($scopeColumns !== [] && ! $anchor instanceof HasNestedSet) {
            throw new ScopeViolationException(sprintf(
                '%s declares a scope (%s); pass an anchor node to scope this operation.',
                static::class,
                implode(', ', $scopeColumns),
            ));
        }

        $instance = new static;
        $scope = $anchor instanceof HasNestedSet && $anchor instanceof Model
            ? NestedSetScopeResolver::valuesFor($anchor)
            : [];

        return new TreeRepairBuilder(
            connection: $instance->getConnection(),
            table: $instance->getTable(),
            lft: $instance->getLftName(),
            rgt: $instance->getRgtName(),
            parentId: $instance->getParentIdName(),
            depth: $instance->getDepthName(),
            scope: $scope,
        );
    }
}
