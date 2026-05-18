<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Query\TreeAggregateBuilder;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Cascades soft-delete and restore through a subtree.
 *
 * When a node is soft-deleted, every descendant gets the same deleted_at
 * timestamp. On restore, only descendants that share that timestamp come
 * back — so multiple independent soft-deletes can coexist and undo
 * cleanly in reverse order.
 *
 * No-ops on models that don't use {@see SoftDeletes}.
 *
 * @mixin Model
 * @mixin HasNestedSet
 */
trait HasSoftDeleteTree
{
    private ?string $restoreMarker = null;

    public static function bootHasSoftDeleteTree(): void
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            return;
        }

        // The `deleted` cascade is invoked directly from NodeTrait's
        // deleted listener — it must run *before* the aggregate hook so
        // chain recomputes (listener Min/Max, exclusive aggregates) see
        // descendants as trashed instead of stale-live.
        //
        static::registerModelEvent('restoring', static function (Model $node): void {
            self::captureRestoreMarker($node);
        });

        // `restored` cascade is invoked directly from NodeTrait so it
        // runs *before* the aggregate hook — same reason as the
        // `deleted` cascade: aggregate chain recomputes need the
        // descendants in their final (post-cascade) trashed state.
    }

    /** @internal Called from NodeTrait's deleted listener to keep ordering deterministic. */
    public static function applySoftDeleteCascade(Model $node): void
    {
        if (! $node instanceof HasNestedSet) {
            return;
        }

        $deletedAtColumn = self::deletedAtColumnFor($node);

        if ($deletedAtColumn === null) {
            return;
        }

        $deletedAt = self::stringifyTimestamp($node->getAttribute($deletedAtColumn));

        if ($deletedAt === null) {
            return;
        }

        $bounds = $node->getBounds();

        self::descendantQuery($node, $bounds->lft, $bounds->rgt)
            ->whereNull($deletedAtColumn)
            ->update([$deletedAtColumn => $deletedAt]);
    }

    private static function captureRestoreMarker(Model $node): void
    {
        if (! $node instanceof HasNestedSet) {
            return;
        }

        $deletedAtColumn = self::deletedAtColumnFor($node);

        if ($deletedAtColumn === null) {
            return;
        }

        $deletedAt = self::stringifyTimestamp($node->getAttribute($deletedAtColumn));

        // Static event closures can only access the receiver via Model API;
        // the buffer methods are on the trait so we know they exist when
        // the event was registered by bootHasSoftDeleteTree.
        if (method_exists($node, 'setRestoreMarker')) {
            $node->setRestoreMarker($deletedAt);
        }
    }

    /** @internal Called from NodeTrait's restored listener to keep ordering deterministic. */
    public static function applyRestoreCascade(Model $node): void
    {
        if (! $node instanceof HasNestedSet) {
            return;
        }

        if (! method_exists($node, 'takeRestoreMarker')) {
            return;
        }

        $deletedAtColumn = self::deletedAtColumnFor($node);

        if ($deletedAtColumn === null) {
            return;
        }

        $marker = $node->takeRestoreMarker();

        if ($marker === null) {
            return;
        }

        $bounds = $node->getBounds();

        self::descendantQuery($node, $bounds->lft, $bounds->rgt)
            ->where($deletedAtColumn, $marker)
            ->update([$deletedAtColumn => null]);
    }

    /**
     * Underlying Query\Builder pre-filtered to "strict descendants of this
     * node, within the same scope". Bypasses Eloquent global scopes (so
     * soft-deleted rows are visible for the cascade UPDATE).
     */
    private static function descendantQuery(Model&HasNestedSet $node, int $lft, int $rgt): Builder
    {
        $query = $node->newQuery()
            ->getQuery()
            ->from($node->getTable())
            ->where($node->getLftName(), '>', $lft)
            ->where($node->getRgtName(), '<', $rgt);

        foreach (NestedSetScopeResolver::valuesFor($node) as $column => $value) {
            $query->where($column, '=', $value);
        }

        return $query;
    }

    private static function stringifyTimestamp(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Resolves the model's soft-delete column. Returns null for models
     * that don't use SoftDeletes. Matches the reflection pattern used in
     * {@see TreeAggregateBuilder::softDeletedColumnFor()}
     * and {@see HasNestedSetAggregates::softDeleteColumn()}
     * — reflection avoids a PHPStan complaint on non-soft-delete fixtures.
     */
    private static function deletedAtColumnFor(Model $node): ?string
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($node), true)) {
            return null;
        }

        $column = (new \ReflectionMethod($node, 'getDeletedAtColumn'))->invoke($node);

        return is_string($column) ? $column : null;
    }

    /** @internal */
    public function setRestoreMarker(?string $marker): void
    {
        $this->restoreMarker = $marker;
    }

    /** @internal */
    public function takeRestoreMarker(): ?string
    {
        $marker = $this->restoreMarker;
        $this->restoreMarker = null;

        return $marker;
    }
}
