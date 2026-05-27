<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\Diagnostics\ScopeViolationDetected;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Events\Repair\FixTreeCompleted;
use Vusys\NestedSet\Events\Repair\TreeIntegrityChecked;
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
        $errors = self::countErrors($anchor);

        return array_sum($errors) > 0;
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
        $errors = self::repairBuilder($anchor)->countErrors();

        $totalErrors = array_sum($errors);
        $anchorId = null;
        if ($anchor instanceof Model) {
            $key = $anchor->getKey();
            $anchorId = is_int($key) || is_string($key) ? $key : null;
        }

        EventDispatcher::dispatch(new TreeIntegrityChecked(
            modelClass: static::class,
            anchorId: $anchorId,
            errors: $errors,
            totalErrors: $totalErrors,
        ));

        return $errors;
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
        // Reject unsaved anchors at the mutating-repair entry point: a
        // null PK collapses to "no rootId" downstream, silently widening
        // the rebuild to the whole table (or whole scope), which is
        // almost never what the caller meant by passing an anchor. Read
        // paths (isBroken/countErrors) stay permissive — they're used
        // with stub anchors as a scope carrier, and the fallback is safe.
        if ($anchor instanceof Model && $anchor->getKey() === null) {
            throw new InvalidArgumentException(sprintf(
                '%s::fixTree: $anchor has no primary key — was it saved? '
                .'Pass a persisted anchor to scope the rebuild to its subtree, '
                .'or omit the anchor to rebuild the whole table.',
                static::class,
            ));
        }

        $builder = self::repairBuilder($anchor);

        $rootId = null;
        if ($anchor instanceof Model) {
            $key = $anchor->getKey();
            $rootId = is_int($key) || is_string($key) ? $key : null;
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
            $message = sprintf(
                '%s declares a scope (%s); pass an anchor node to scope this operation.',
                static::class,
                implode(', ', $scopeColumns),
            );
            EventDispatcher::dispatch(new ScopeViolationDetected(
                modelClass: static::class,
                stage: 'repair',
                message: $message,
            ));
            throw new ScopeViolationException($message);
        }

        if ($anchor instanceof HasNestedSet && ! $anchor instanceof static) {
            throw new InvalidArgumentException(sprintf(
                '%s repair: $anchor must be an instance of %s, got %s. '
                .'Cross-class anchors silently target the wrong table — pass an anchor of the same model.',
                static::class,
                static::class,
                $anchor::class,
            ));
        }

        $instance = new static;
        $scope = $anchor !== null
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
