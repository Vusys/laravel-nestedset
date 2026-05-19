<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Events\FixTreeCompleted;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\NodeTrait;
use Vusys\NestedSet\Query\TreeRepairBuilder;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;
use Vusys\NestedSet\TreeFixResult;

/**
 * Static API for detecting and repairing structural nested-set
 * invariants — `lft` / `rgt` / `depth` / `parent_id` consistency.
 * Aggregate-column drift is a separate concern, handled by
 * {@see HasNestedSetAggregates::fixAggregates()}.
 *
 * The trait is composed by {@see NodeTrait} so users
 * who `use NodeTrait` get the full public surface without thinking
 * about which file each method lives in.
 *
 * @see TreeRepairBuilder for the underlying
 *      rebuild walk.
 */
trait HasTreeRepair
{
    /**
     * Reports whether any structural invariant is violated. Cheap on
     * tables with healthy indexes — index range scans plus a GROUP BY
     * for the duplicate counts.
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
     * Followed by an aggregate-rebuild pass — `fixTree()` calls
     * `fixAggregates()` internally so post-repair stored aggregates
     * match the post-repair structure.
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

        // Time the structural repair + the aggregate rebuild as one
        // unit so the FixTreeCompleted event's duration matches the
        // user-observable wall-clock of the call. The aggregate
        // rebuild lives on HasNestedSetAggregates — trait-private
        // methods are visible to any other method of the using class,
        // including methods composed in from sibling traits.
        $startNs = hrtime(true);

        $treeResult = $builder->fixTree($rootId);
        $aggregatesFixed = self::runFixAggregates($anchor, $rootId);

        $durationMs = (hrtime(true) - $startNs) / 1_000_000;

        $result = $aggregatesFixed === null
            ? $treeResult
            : new TreeFixResult(
                nodesUpdated: $treeResult->nodesUpdated,
                errors: $treeResult->errors,
                aggregatesFixed: $aggregatesFixed,
            );

        EventDispatcher::dispatch(new FixTreeCompleted(
            modelClass: static::class,
            anchorId: $rootId,
            nodesUpdated: $treeResult->nodesUpdated,
            durationMs: $durationMs,
            aggregatesFixed: $aggregatesFixed?->totalRowsUpdated,
        ));

        return $result;
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
            idCol: $instance->getKeyName(),
        );
    }
}
