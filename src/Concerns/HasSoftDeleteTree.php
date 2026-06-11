<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
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

        // Format the cascade marker exactly as Eloquent's runSoftDelete()
        // stamped the anchor row — `fromDateTime()` at the model's date
        // format (default 'Y-m-d H:i:s', no sub-second). Using that same
        // seconds-precision string for the descendants makes the anchor
        // and its cascade byte-identical on a text column (SQLite) and
        // instant-identical on a real timestamp column, so the restore
        // match behaves the same on every backend. (A microsecond marker
        // does NOT help: the default deleted_at column is second-precision,
        // where one side rounds and the other truncates — see the docs
        // note on same-second cascades.)
        $deletedAt = self::stringifyTimestamp($node->getAttribute($deletedAtColumn));

        if ($deletedAt === null) {
            return;
        }

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

        // Re-read the anchor's bounds from the DB before banding the
        // cascade: a sibling's hard delete (or any structural mutation)
        // since this instance was trashed/loaded may have shifted its
        // lft/rgt, and the stale in-memory band would miss the shifted
        // descendants — leaving them trashed under a restored parent.
        // Asymmetric with the hardened delete path until now; same fix.
        self::refreshBoundsFromDatabase($node);

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
     * Re-reads lft/rgt/depth from the DB into $node so the cascade bands
     * on current bounds, not a stale in-memory snapshot. No-op if the
     * row can't be found (the restore would itself be a no-op then).
     */
    private static function refreshBoundsFromDatabase(Model&HasNestedSet $node): void
    {
        $key = $node->getKey();
        if ($key === null) {
            return;
        }

        $columns = [$node->getLftName(), $node->getRgtName(), $node->getDepthName()];

        $row = $node->getConnection()
            ->table($node->getTable())
            ->where($node->getKeyName(), $key)
            ->first($columns);

        if ($row === null) {
            return;
        }

        foreach ($columns as $column) {
            $node->setAttribute($column, $row->{$column});
            $node->syncOriginalAttribute($column);
        }
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
     * Stringifies a stored soft-delete timestamp for both writing the
     * cascade marker onto descendants and matching it back on restore.
     *
     * Uses **seconds** precision (`Y-m-d H:i:s`) — the same shape Eloquent's
     * `fromDateTime()` writes to the anchor row at the default model date
     * format. That keeps the anchor and its descendants carrying an
     * identical value on every backend: byte-identical on a text column
     * (SQLite) and the same instant on a real timestamp column.
     *
     * A finer (microsecond) marker is deliberately avoided: the default
     * `deleted_at` column is second-precision, where a sub-second write
     * rounds on the column while the in-memory cast truncates — so the two
     * sides disagree across backends. Independent or nested cascades that
     * land in the same wall-clock second therefore share a marker; the
     * cascade is bounds-scoped, so disjoint subtrees are always isolated
     * regardless. See `docs/tree-operations/soft-deletes.md`.
     */
    private static function stringifyTimestamp(mixed $value): ?string
    {
        // Any DateTimeInterface, not just Illuminate's Carbon — a model
        // using the `immutable_datetime` cast stores a CarbonImmutable,
        // which does NOT extend Carbon. The old narrow check returned null
        // for it, so the whole cascade silently no-opped and descendants
        // stayed live with no error.
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
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
