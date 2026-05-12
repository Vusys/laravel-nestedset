<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Vusys\NestedSet\Contracts\HasNestedSet;
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

        // Use registerModelEvent so PHPStan doesn't need to know that
        // SoftDeletes adds the restoring/restored static accessors —
        // those are dynamic-trait methods Larastan can't see through
        // our class_uses_recursive guard above.
        static::registerModelEvent('deleted', static function (Model $node): void {
            self::cascadeSoftDelete($node);
        });

        static::registerModelEvent('restoring', static function (Model $node): void {
            self::captureRestoreMarker($node);
        });

        static::registerModelEvent('restored', static function (Model $node): void {
            self::cascadeRestore($node);
        });
    }

    private static function cascadeSoftDelete(Model $node): void
    {
        if (! $node instanceof HasNestedSet) {
            return;
        }

        $deletedAt = self::stringifyTimestamp($node->getAttribute('deleted_at'));

        if ($deletedAt === null) {
            return;
        }

        $bounds = $node->getBounds();

        self::descendantQuery($node, $bounds->lft, $bounds->rgt)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $deletedAt]);
    }

    private static function captureRestoreMarker(Model $node): void
    {
        if (! $node instanceof HasNestedSet) {
            return;
        }

        $deletedAt = self::stringifyTimestamp($node->getAttribute('deleted_at'));

        // Static event closures can only access the receiver via Model API;
        // the buffer methods are on the trait so we know they exist when
        // the event was registered by bootHasSoftDeleteTree.
        if (method_exists($node, 'setRestoreMarker')) {
            $node->setRestoreMarker($deletedAt);
        }
    }

    private static function cascadeRestore(Model $node): void
    {
        if (! $node instanceof HasNestedSet) {
            return;
        }

        if (! method_exists($node, 'takeRestoreMarker')) {
            return;
        }

        $marker = $node->takeRestoreMarker();

        if ($marker === null) {
            return;
        }

        $bounds = $node->getBounds();

        self::descendantQuery($node, $bounds->lft, $bounds->rgt)
            ->where('deleted_at', $marker)
            ->update(['deleted_at' => null]);
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
