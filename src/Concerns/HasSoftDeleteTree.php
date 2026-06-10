<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Events\SoftDelete\SoftDeleteMarkerCaptured;
use Vusys\NestedSet\Events\SoftDelete\SubtreeRestored;
use Vusys\NestedSet\Events\SoftDelete\SubtreeRestoring;
use Vusys\NestedSet\Events\SoftDelete\SubtreeSoftDeleted;
use Vusys\NestedSet\Events\SoftDelete\SubtreeSoftDeleting;
use Vusys\NestedSet\Query\Aggregates\Read\FreshAggregateProjector;
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

        if ($node->getAttribute($deletedAtColumn) === null) {
            return;
        }

        // Eloquent's runSoftDelete() already stamped the anchor row, but
        // fromDateTime() truncated it to the model's date format (default
        // 'Y-m-d H:i:s', no microseconds) and reading it back through the
        // datetime cast strips any sub-second part. Build one
        // microsecond-precision marker and re-stamp BOTH the anchor and
        // the cascade with it, so (a) the anchor row and its descendants
        // always carry an identical string — the restore match depends on
        // it — and (b) two cascades in the same wall-clock second stay
        // distinct on a sub-second-capable column. The single shared
        // string makes behaviour identical across backends: the DB
        // truncates each side of the later restore comparison the same way.
        $deletedAt = self::cascadeMarker($node);

        $bounds = $node->getBounds();
        $scope = NestedSetScopeResolver::valuesFor($node);

        EventDispatcher::dispatch(new SubtreeSoftDeleting(
            modelClass: $node::class,
            anchor: $node,
            bounds: $bounds,
            scope: $scope,
            deletedAt: $deletedAt,
        ));

        $descendantIds = EventDispatcher::hasListeners(SubtreeSoftDeleted::class)
            ? self::collectDescendantIds($node, $bounds->lft, $bounds->rgt, $deletedAtColumn, whereNull: true)
            : [];

        self::descendantQuery($node, $bounds->lft, $bounds->rgt)
            ->whereNull($deletedAtColumn)
            ->update([$deletedAtColumn => $deletedAt]);

        self::restampAnchor($node, $deletedAtColumn, $deletedAt);

        EventDispatcher::dispatch(new SubtreeSoftDeleted(
            modelClass: $node::class,
            anchor: $node,
            bounds: $bounds,
            scope: $scope,
            deletedAt: $deletedAt,
            descendantIds: $descendantIds,
        ));
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

        EventDispatcher::dispatch(new SoftDeleteMarkerCaptured(
            modelClass: $node::class,
            anchor: $node,
            marker: $deletedAt,
        ));
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
        $scope = NestedSetScopeResolver::valuesFor($node);

        EventDispatcher::dispatch(new SubtreeRestoring(
            modelClass: $node::class,
            anchor: $node,
            bounds: $bounds,
            scope: $scope,
            marker: $marker,
        ));

        $descendantIds = EventDispatcher::hasListeners(SubtreeRestored::class)
            ? self::collectDescendantIds($node, $bounds->lft, $bounds->rgt, $deletedAtColumn, whereNull: false, equalsValue: $marker)
            : [];

        self::descendantQuery($node, $bounds->lft, $bounds->rgt)
            ->where($deletedAtColumn, $marker)
            ->update([$deletedAtColumn => null]);

        EventDispatcher::dispatch(new SubtreeRestored(
            modelClass: $node::class,
            anchor: $node,
            bounds: $bounds,
            scope: $scope,
            marker: $marker,
            descendantIds: $descendantIds,
        ));
    }

    /**
     * Gathers descendant primary keys that match the cascade's
     * filter, used to populate the cascade event's `descendantIds`
     * field. Cheap on the same bounds index the cascade UPDATE
     * uses. Gated by {@see EventDispatcher::enabled()} at the
     * call site so disabled telemetry pays no extra query.
     *
     * @return list<int|string>
     */
    private static function collectDescendantIds(
        Model&HasNestedSet $node,
        int $lft,
        int $rgt,
        string $deletedAtColumn,
        bool $whereNull,
        ?string $equalsValue = null,
    ): array {
        $query = self::descendantQuery($node, $lft, $rgt);

        if ($whereNull) {
            $query->whereNull($deletedAtColumn);
        } elseif ($equalsValue !== null) {
            $query->where($deletedAtColumn, $equalsValue);
        }

        $keyName = $node->getKeyName();
        $isIntKey = $node->getKeyType() === 'int';

        $rows = $query->select([$keyName])->get();

        $ids = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $value = $row->{$keyName} ?? null;
            if ($value === null) {
                continue;
            }
            $ids[] = $isIntKey ? (int) $value : (string) $value;
        }

        return $ids;
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

    /**
     * Builds the microsecond-precision cascade marker for a soft-delete.
     *
     * Eloquent's runSoftDelete() stamps the anchor with a freshTimestamp()
     * truncated to the model's date format, so by the time this cascade
     * runs the in-memory `deleted_at` has already lost its sub-second
     * component. Re-derive a full-precision marker from a fresh timestamp
     * (the same clock runSoftDelete used, including any test-now override)
     * and re-stamp the anchor row with it via {@see restampAnchor()} so the
     * anchor and every cascade descendant carry an identical string.
     */
    private static function cascadeMarker(Model&HasNestedSet $node): string
    {
        return $node->freshTimestamp()->format('Y-m-d H:i:s.u');
    }

    /**
     * Overwrites the anchor's own `deleted_at` with the full-precision
     * cascade marker (runSoftDelete wrote the date-format-truncated form)
     * and syncs the in-memory attribute so an immediate restore on the
     * same instance reads the matching value.
     */
    private static function restampAnchor(Model&HasNestedSet $node, string $deletedAtColumn, string $marker): void
    {
        $key = $node->getKey();
        if ($key === null) {
            return;
        }

        $node->newQuery()
            ->getQuery()
            ->from($node->getTable())
            ->where($node->getKeyName(), '=', $key)
            ->update([$deletedAtColumn => $marker]);

        $node->setRawAttributes(
            array_merge($node->getAttributes(), [$deletedAtColumn => $marker]),
            sync: true,
        );
    }

    /**
     * Stringifies a stored soft-delete timestamp when matching it back on
     * restore. The cascade re-stamps the anchor and descendants with one
     * microsecond-precision marker (see {@see cascadeMarker()}), so reading
     * it back with `Y-m-d H:i:s.u` reproduces the exact stored string on a
     * sub-second column (SQLite text, `DATETIME(6)`, PostgreSQL `TIMESTAMP`).
     *
     * On a `DATETIME(0)` column the DB truncates to seconds on both write
     * and read, so the WHERE still matches — same-second cascades can
     * collide there, but that's a schema limitation, not a package one.
     */
    private static function stringifyTimestamp(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->format('Y-m-d H:i:s.u');
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Resolves the model's soft-delete column. Returns null for models
     * that don't use SoftDeletes. Matches the reflection pattern used in
     * {@see FreshAggregateProjector::softDeletedColumnFor()}
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
