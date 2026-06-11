<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Repair;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vusys\NestedSet\Aggregates\Strategy\DeltaMaintenance;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\Diagnostics\ScopeViolationDetected;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Exceptions\NestedSetInvalidArgumentException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Shared anchor-resolution + scope-validation helpers used by every
 * aggregate-repair entry point ({@see AggregateRepair},
 * {@see DeferredMaintenanceRunner}, the queue runner, and
 * `HasTreeRepair::fixTree()`).
 *
 * Three responsibilities:
 *  - {@see anchorOrFail()} — read-side validation (permissive: stub
 *    anchors carry scope but can be unsaved).
 *  - {@see writeAnchorOrFail()} — write-side validation (strict:
 *    unsaved anchors rejected because a null PK would silently widen
 *    to whole-table/whole-scope).
 *  - {@see rootIdOf()} / {@see softDeleteColumn()} — small accessors
 *    that don't fit elsewhere but are needed throughout the repair
 *    surface.
 */
final class AggregateAnchor
{
    /**
     * @template TModel of Model&HasNestedSet
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel
     */
    public static function anchorOrFail(string $modelClass, ?HasNestedSet $anchor): Model
    {
        $scopeColumns = NestedSetScopeResolver::columns($modelClass);

        if ($scopeColumns !== [] && ! $anchor instanceof HasNestedSet) {
            $message = sprintf(
                '%s declares a scope (%s); pass an anchor node to scope this operation.',
                $modelClass,
                implode(', ', $scopeColumns),
            );
            EventDispatcher::dispatch(new ScopeViolationDetected(
                modelClass: $modelClass,
                stage: 'repair',
                message: $message,
            ));
            throw new ScopeViolationException($message);
        }

        if ($anchor instanceof HasNestedSet && ! $anchor instanceof $modelClass) {
            throw new NestedSetInvalidArgumentException(sprintf(
                '%s aggregate repair: $anchor must be an instance of %s, got %s. '
                .'A cross-class anchor would silently target a different table (or no rows at all).',
                $modelClass,
                $modelClass,
                $anchor::class,
            ));
        }

        /** @var TModel $instance */
        $instance = new $modelClass;

        return $instance;
    }

    /**
     * Variant of {@see anchorOrFail()} for mutating-repair entry
     * points. Adds an unsaved-anchor rejection: a null PK silently
     * widens the operation to whole-table/whole-scope, which is almost
     * never what `fixAggregates($anchor)` callers intend. Read paths
     * (`aggregateErrors`) stay permissive — stub anchors are a
     * legitimate scope-carrier pattern there.
     *
     * @template TModel of Model&HasNestedSet
     *
     * @param  class-string<TModel>  $modelClass
     * @return TModel
     */
    public static function writeAnchorOrFail(string $modelClass, ?HasNestedSet $anchor): Model
    {
        $instance = self::anchorOrFail($modelClass, $anchor);

        if ($anchor instanceof Model && $anchor->getKey() === null) {
            throw new NestedSetInvalidArgumentException(sprintf(
                '%s::fixAggregates: $anchor has no primary key — was it saved? '
                .'Pass a persisted anchor to scope the repair to its subtree, '
                .'or omit the anchor to repair the whole table.',
                $modelClass,
            ));
        }

        return $instance;
    }

    public static function rootIdOf(?HasNestedSet $anchor): int|string|null
    {
        if (! $anchor instanceof Model) {
            return null;
        }

        $key = $anchor->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }

    /**
     * Returns the soft-delete column name for models that use Eloquent's
     * SoftDeletes trait, or null otherwise. The aggregate-maintenance
     * delta path passes this to {@see DeltaMaintenance}
     * so per-mutation updates skip trashed ancestors — snapshot
     * semantics: a trashed ancestor's stored aggregate stays frozen at
     * trash time, then gets re-synced on restore.
     *
     * @param  Model&HasNestedSet  $node
     */
    public static function softDeleteColumn(Model $node): ?string
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($node::class), true)) {
            return null;
        }

        // Reflection: SoftDeletes is in the hierarchy at runtime, but
        // PHPStan analyses each concrete model class in isolation and
        // can prove `getDeletedAtColumn` doesn't exist for non-soft-
        // delete fixtures. Reflection bypasses that static check
        // without an ignore.
        $column = (new \ReflectionMethod($node, 'getDeletedAtColumn'))->invoke($node);

        return is_string($column) ? $column : null;
    }
}
