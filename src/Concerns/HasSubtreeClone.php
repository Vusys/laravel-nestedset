<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use InvalidArgumentException;
use LogicException;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Events\Subtree\SubtreeCloned;
use Vusys\NestedSet\Exceptions\InvalidCloneTargetException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\Exceptions\UnplacedNodeException;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPathRegistry;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Subtree cloning: duplicate `$this` (and every descendant) under a
 * new parent or as a new root, with fresh primary keys, regenerated
 * structural columns, and one deferred aggregate recompute.
 *
 * The clone is one transaction: source-row read, gap-open + bulk
 * INSERT, parent-id reconciliation, deferred aggregate recompute. On
 * failure the whole thing rolls back — no half-cloned state is
 * reachable.
 *
 * The clone path sits on top of {@see HasBulkInsert::bulkInsertTree()};
 * it builds the same nested-array payload that primitive accepts and
 * inherits autoincrement/UUID parent-id reconciliation, scope copy,
 * and deferred aggregate maintenance for free. Per-row Eloquent
 * `creating`/`created`/`saving`/`saved` events are suppressed via
 * {@see Model::withoutEvents()} (the documented escape hatch); the
 * single signal listeners hook is {@see SubtreeCloned}.
 *
 * @mixin Model
 * @mixin HasNestedSet
 * @mixin HasNestedSetAggregates
 * @mixin HasNodeInspection
 * @mixin HasTreeMutation
 * @mixin HasBulkInsert
 */
trait HasSubtreeClone
{
    /**
     * Duplicate `$this` and every descendant under `$parent`.
     *
     * @param  Closure(array<string, mixed>, int): array<string, mixed>|null  $transform
     *                                                                                    Per-row rewrite. Receives the source row's raw DB
     *                                                                                    attributes (no casts) plus the destination depth
     *                                                                                    relative to the clone's root (0 for the clone's root).
     *                                                                                    Must return an array; returning a structural column
     *                                                                                    (lft/rgt/depth/parent_id/scope) throws.
     * @param  bool  $includeTrashed  When false (default), trashed root throws upfront and
     *                                trashed descendants are silently omitted. When true,
     *                                both are cloned as live rows (`deleted_at` always null
     *                                on clones).
     *
     * @throws UnplacedNodeException When `$this` or `$parent` has no bounds yet.
     * @throws InvalidArgumentException When `$this` or `$parent` is trashed in a disallowed way.
     * @throws InvalidCloneTargetException When `$parent` is in `$this`'s own subtree (including self).
     * @throws ScopeViolationException When `$this` and `$parent` live in different scopes.
     * @throws LogicException When `$transform` returns a structural column.
     */
    public function cloneSubtreeTo(
        HasNestedSet $parent,
        int|string $position = 'last',
        ?Closure $transform = null,
        bool $includeTrashed = false,
    ): static {
        return $this->performClone(
            destinationParent: $parent,
            asRoot: false,
            position: $position,
            transform: $transform,
            includeTrashed: $includeTrashed,
        );
    }

    /**
     * Duplicate `$this` and every descendant as a new root in the
     * source's scope.
     *
     * `$position` mirrors {@see HasTreeMutation::moveTo()} — `'first'`,
     * `'last'`, or a 0-indexed integer (matching `moveTo`'s integer
     * convention; out-of-range falls back to append). It governs the
     * clone's slot in the forest at depth 0.
     *
     * @param  Closure(array<string, mixed>, int): array<string, mixed>|null  $transform
     *                                                                                    See {@see self::cloneSubtreeTo()}.
     *
     * @throws UnplacedNodeException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function cloneSubtreeAsRoot(
        int|string $position = 'last',
        ?Closure $transform = null,
        bool $includeTrashed = false,
    ): static {
        return $this->performClone(
            destinationParent: null,
            asRoot: true,
            position: $position,
            transform: $transform,
            includeTrashed: $includeTrashed,
        );
    }

    /**
     * Static convenience: clone `$source` under `$parent` in one
     * expression. Equivalent to
     * `$source->cloneSubtreeTo($parent, $position, $transform, $includeTrashed)`.
     * Convention is to pass the optional args as named parameters for
     * readability — PHP doesn't enforce named-only, so the rule lives
     * in the docs.
     *
     * @param  Closure(array<string, mixed>, int): array<string, mixed>|null  $transform
     */
    public static function cloneSubtree(
        HasNestedSet $source,
        HasNestedSet $parent,
        int|string $position = 'last',
        ?Closure $transform = null,
        bool $includeTrashed = false,
    ): static {
        if (! $source instanceof static) {
            throw new InvalidArgumentException(sprintf(
                'cloneSubtree: $source must be an instance of %s, got %s.',
                static::class,
                $source::class,
            ));
        }

        return $source->cloneSubtreeTo($parent, $position, $transform, $includeTrashed);
    }

    /**
     * Shared body for both `cloneSubtreeTo()` and `cloneSubtreeAsRoot()`.
     *
     * @param  Closure(array<string, mixed>, int): array<string, mixed>|null  $transform
     */
    private function performClone(
        ?HasNestedSet $destinationParent,
        bool $asRoot,
        int|string $position,
        ?Closure $transform,
        bool $includeTrashed,
    ): static {
        $this->assertSourcePlaced();
        $this->assertSourceLive($includeTrashed);

        // Source's in-memory bounds may be stale if a sibling or
        // descendant has been added/removed since hydration. Re-read
        // before the descendant pre-check (which compares bounds) and
        // before payload construction. One SELECT is cheap and the
        // alternative (callers chasing false-negative descendant
        // detection bugs) is much worse.
        $this->refresh();

        // Re-confirm placement + live state after the refresh in case
        // the row was deleted between original load and now.
        $this->assertSourcePlaced();
        $this->assertSourceLive($includeTrashed);

        /** @var static|null $narrowedParent */
        $narrowedParent = null;
        if (! $asRoot) {
            \assert($destinationParent instanceof HasNestedSet);
            $narrowedParent = $this->narrowDestinationParent($destinationParent);
            $narrowedParent->refresh();
            $this->assertDestinationPlacedAndLive($narrowedParent);
            $this->assertSourceAndDestinationScopesMatch($narrowedParent);
            $this->assertDestinationNotInSourceSubtree($narrowedParent);
        }

        $sourceRootDepth = $this->getDepth();

        $reservedColumns = $this->reservedColumnNames();
        $aggregateColumns = $this->aggregateColumnNames();

        $rows = $this->loadSourceRows($includeTrashed);

        if ($rows === []) {
            throw new LogicException(sprintf(
                'cloneSubtreeTo: source subtree under %s contained no rows after applying includeTrashed filter — this should be unreachable since the pre-checks accept the source.',
                static::class,
            ));
        }

        $payload = $this->buildClonePayload(
            rows: $rows,
            sourceRootDepth: $sourceRootDepth,
            reservedColumns: $reservedColumns,
            aggregateColumns: $aggregateColumns,
            transform: $transform,
        );

        $rowCount = count($rows);
        $connection = $this->getConnection();

        $hasScope = NestedSetScopeResolver::columns(static::class) !== [];

        $cloneRoot = $connection->transaction(function () use ($payload, $narrowedParent, $asRoot, $position, $hasScope): self {
            // Suppress per-row Eloquent events — the clone primitive's
            // single signal is SubtreeCloned, fired once after the
            // bulk insert commits. Wraps the inner bulkInsertTree
            // call (which would otherwise fire creating/saving/
            // created/saved per row). This is the documented escape
            // hatch from HasBulkInsert.
            //
            // For asRoot on a scoped model, bulkInsertTree refuses to
            // seed roots without an anchor (it needs scope-column
            // values). Insert under the source as a temporary parent
            // and promote to root after the bulk INSERT — keeps the
            // clone's payload going through one code path.
            $useSourceAsAnchor = $asRoot && $hasScope;
            /** @var static|null $anchor */
            $anchor = $useSourceAsAnchor ? $this : $narrowedParent;

            /** @var list<static> $inserted */
            $inserted = static::withoutEvents(static function () use ($payload, $anchor, $asRoot, $useSourceAsAnchor): array {
                if ($asRoot && ! $useSourceAsAnchor) {
                    return static::bulkInsertTree($payload);
                }
                \assert($anchor !== null);

                return static::bulkInsertTree($payload, $anchor);
            });

            if ($inserted === []) {
                throw new LogicException('cloneSubtreeTo: bulkInsertTree returned no rows.');
            }

            $root = $inserted[0];

            if ($useSourceAsAnchor) {
                // Promote out of the temporary parent. makeRoot fires
                // its own Eloquent + aggregate maintenance hooks; the
                // outer transaction keeps both INSERT and move atomic.
                $root->makeRoot()->save();
                $root->refresh();
                $this->placeAsRootAtPosition($root, $position);
            } elseif ($asRoot) {
                $this->placeAsRootAtPosition($root, $position);
            } else {
                $this->placeAtPosition($root, $narrowedParent, $position);
            }

            // Recompute materialised-path columns INSIDE the transaction
            // (bulk insert ran under withoutEvents(), so the path saving
            // listener never fired) and validate uniqueness. Doing this
            // inside the transaction means a clone that would collide
            // with an existing sibling — e.g. cloning a slugged node
            // under its own parent — rolls back with DuplicatePathSegmentException
            // exactly as a normal save() would, instead of committing two
            // siblings with the same path; and a crash mid-rebuild can't
            // leave the cloned rows with NULL paths.
            if (MaterialisedPathRegistry::columnsFor(static::class) !== []) {
                $root->refresh();
                static::fixMaterialisedPaths(null, $root);
                $root->refresh();
                self::assertClonedPathsUnique($root);
            }

            return $root;
        });

        $cloneRoot->refresh();

        // Defer the SubtreeCloned dispatch until the outermost
        // transaction commits. With no caller transaction this fires
        // immediately (afterCommit runs the callback when no current
        // transaction is open); with a caller transaction it waits
        // for the outer commit so listeners never see clone state
        // that may still roll back.
        $source = $this;
        $connection->afterCommit(static function () use ($source, $cloneRoot, $rowCount, $includeTrashed): void {
            EventDispatcher::dispatch(new SubtreeCloned(
                modelClass: $source::class,
                source: $source,
                clone: $cloneRoot,
                rowCount: $rowCount,
                includeTrashed: $includeTrashed,
            ));
        });

        return $cloneRoot;
    }

    // ----------------------------------------------------------------
    // Pre-checks
    // ----------------------------------------------------------------

    private function assertSourcePlaced(): void
    {
        if (! $this->exists || ! $this->isPlacedInTree()) {
            throw new UnplacedNodeException(sprintf(
                'cloneSubtreeTo: source %s must be a saved, placed node.',
                static::class,
            ));
        }
    }

    private function assertSourceLive(bool $includeTrashed): void
    {
        if ($includeTrashed) {
            return;
        }

        if ($this->isSourceTrashed()) {
            throw new InvalidArgumentException(sprintf(
                'cloneSubtreeTo: source %s id=%s is soft-deleted; restore it or pass includeTrashed: true.',
                static::class,
                $this->describeKey(),
            ));
        }
    }

    /**
     * Narrows `HasNestedSet $parent` to a same-class instance so
     * downstream pre-checks can call cross-trait methods (isPlacedInTree,
     * isDescendantOf, etc.) on a typed value. Cross-class clone is out
     * of scope (§1).
     */
    private function narrowDestinationParent(HasNestedSet $parent): static
    {
        if (! $parent instanceof static) {
            throw new InvalidArgumentException(sprintf(
                'cloneSubtreeTo: destination parent must be an instance of %s, got %s.',
                static::class,
                $parent::class,
            ));
        }

        return $parent;
    }

    private function assertDestinationPlacedAndLive(self $parent): void
    {
        if (! $parent->exists) {
            throw new UnplacedNodeException(sprintf(
                'cloneSubtreeTo: destination parent %s must be a saved model.',
                $parent::class,
            ));
        }

        if (! $parent->isPlacedInTree()) {
            throw new UnplacedNodeException(sprintf(
                'cloneSubtreeTo: destination parent %s id=%s has no bounds — place it in a tree first.',
                $parent::class,
                $this->describeKeyOf($parent),
            ));
        }

        if ($this->modelIsTrashed($parent)) {
            // includeTrashed is about the SOURCE, not the destination —
            // a trashed parent can never receive a clone. Cloning into
            // a deleted tree would orphan the new rows on restore /
            // forceDelete.
            throw new InvalidArgumentException(sprintf(
                'cloneSubtreeTo: destination parent %s id=%s is soft-deleted; restore it before cloning into it.',
                $parent::class,
                $this->describeKeyOf($parent),
            ));
        }
    }

    private function assertSourceAndDestinationScopesMatch(self $parent): void
    {
        NestedSetScopeResolver::assertSameScope($this, $parent);
    }

    private function assertDestinationNotInSourceSubtree(self $parent): void
    {
        $sourceKey = $this->getKey();
        $parentKey = $parent->getKey();
        $sourceIsParent = $parent === $this
            || ($sourceKey !== null && $parentKey !== null && $this->stringifyKey($sourceKey) === $this->stringifyKey($parentKey));
        if ($sourceIsParent) {
            throw new InvalidCloneTargetException(sprintf(
                'cloneSubtreeTo: cannot clone %s id=%s into itself.',
                static::class,
                $this->describeKey(),
            ));
        }

        $parentBounds = $parent->getBounds();
        $sourceBounds = $this->getBounds();
        $sameBounds = $parentBounds->lft === $sourceBounds->lft && $parentBounds->rgt === $sourceBounds->rgt;
        if ($parent->isDescendantOf($this) || $sameBounds) {
            throw new InvalidCloneTargetException(sprintf(
                'cloneSubtreeTo: cannot clone %s id=%s into one of its own descendants (target id=%s).',
                static::class,
                $this->describeKey(),
                $this->describeKeyOf($parent),
            ));
        }
    }

    private function stringifyKey(mixed $key): string
    {
        if (is_int($key) || is_string($key)) {
            return (string) $key;
        }

        return '';
    }

    /**
     * Asserts the return value of `$transform` only carries columns the
     * caller is allowed to override. Structural columns (lft/rgt/depth/
     * parent_id) and scope columns are owned by the package and the
     * primary key is regenerated — silently dropping a caller-set value
     * would hide a bug, so fail-fast.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $reservedColumns
     */
    private function assertTransformReturnIsStructurallyClean(array $attributes, array $reservedColumns): void
    {
        foreach ($reservedColumns as $column) {
            if (array_key_exists($column, $attributes)) {
                throw new LogicException(sprintf(
                    'cloneSubtreeTo: $transform returned reserved column "%s" — lft/rgt/depth/parent_id/scope/primary-key are owned by the package and cannot be overridden.',
                    $column,
                ));
            }
        }
    }

    // ----------------------------------------------------------------
    // Source read + payload build
    // ----------------------------------------------------------------

    /**
     * @return list<Model&HasNestedSet>
     */
    private function loadSourceRows(bool $includeTrashed): array
    {
        $bounds = $this->getBounds();
        $lftCol = $this->getLftName();
        $rgtCol = $this->getRgtName();

        $query = static::query()
            ->where($lftCol, '>=', $bounds->lft)
            ->where($rgtCol, '<=', $bounds->rgt)
            ->orderBy($lftCol);

        foreach (NestedSetScopeResolver::valuesFor($this) as $col => $value) {
            $query->where($col, $value);
        }

        if ($includeTrashed && in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        /** @var list<Model&HasNestedSet> $rows */
        $rows = [];
        foreach ($query->cursor() as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Walks `$rows` (already in lft order, i.e. DFS pre-order) and
     * builds the nested payload `bulkInsertTree` expects. The first
     * row is the clone's root.
     *
     * @param  list<Model&HasNestedSet>  $rows
     * @param  list<string>  $reservedColumns
     * @param  list<string>  $aggregateColumns
     * @param  Closure(array<string, mixed>, int): array<string, mixed>|null  $transform
     * @return list<array<string, mixed>> shape `bulkInsertTree` accepts (attributes + optional `children`).
     */
    private function buildClonePayload(
        array $rows,
        int $sourceRootDepth,
        array $reservedColumns,
        array $aggregateColumns,
        ?Closure $transform,
    ): array {
        // Per-row payload entries indexed by source row id. We attach
        // children to the parent's `children` slot as we walk; the
        // top-level payload returned is the first row's wrapper.
        /** @var array<string, array{attributes: array<string, mixed>, children: array<int, mixed>}> $entries */
        $entries = [];
        /** @var list<string> $orderedKeys */
        $orderedKeys = [];

        $deletedAtColumn = $this->softDeleteColumnName();

        foreach ($rows as $row) {
            $key = $row->getKey();
            if (! is_int($key) && ! is_string($key)) {
                throw new LogicException('cloneSubtreeTo: source row has no primary key.');
            }
            $mapKey = (string) $key;

            $raw = $row->getAttributes();
            $destinationDepth = $row->getDepth() - $sourceRootDepth;

            $attributes = $this->prepareRowAttributes(
                rawAttributes: $raw,
                destinationDepth: $destinationDepth,
                reservedColumns: $reservedColumns,
                aggregateColumns: $aggregateColumns,
                deletedAtColumn: $deletedAtColumn,
                transform: $transform,
            );

            $entries[$mapKey] = [
                'attributes' => $attributes,
                'children' => [],
            ];
            $orderedKeys[] = $mapKey;
        }

        // First row is the cloned root; every subsequent row attaches
        // to its source parent's `children` slot. Iterating in lft
        // order (DFS pre-order) guarantees the parent entry exists by
        // the time the child is processed.
        $rootKey = $orderedKeys[0];

        foreach ($rows as $i => $row) {
            if ($i === 0) {
                continue;
            }
            $parentId = $row->getParentId();
            if ($parentId === null) {
                throw new LogicException(sprintf(
                    'cloneSubtreeTo: source row id=%s has no parent_id but is not the clone root.',
                    $this->describeKeyOf($row),
                ));
            }
            $parentMapKey = $this->stringifyKey($parentId);
            $childMapKey = $this->stringifyKey($row->getKey());
            if (! isset($entries[$parentMapKey])) {
                // Soft-deleted parent in include-trashed=false mode:
                // descendant's ancestor was excluded from the row
                // set, so we silently drop the descendant too —
                // matches the "trashed descendants are skipped"
                // contract in SUBTREE_CLONE_DESIGN.md §5.1.
                unset($entries[$childMapKey]);

                continue;
            }
            $entries[$parentMapKey]['children'][] = &$entries[$childMapKey];
        }

        return [$this->normaliseEntry($entries[$rootKey])];
    }

    /**
     * Materialises the nested entry, stripping empty `children` keys —
     * bulkInsertTree accepts payload without the key, and leaving an
     * empty array there clutters debug output.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function normaliseEntry(array $entry): array
    {
        /** @var array<string, mixed> $attributes */
        $attributes = $entry['attributes'];

        /** @var list<array<string, mixed>> $children */
        $children = is_array($entry['children']) ? array_values($entry['children']) : [];

        if ($children === []) {
            return $attributes;
        }

        $normalised = [];
        foreach ($children as $child) {
            $normalised[] = $this->normaliseEntry($child);
        }

        $attributes['children'] = $normalised;

        return $attributes;
    }

    /**
     * Builds the attribute set for one row of the destination payload:
     * raw source attributes minus structural columns, minus aggregate
     * columns (recomputed by the deferred pass), minus the soft-delete
     * column (clones are always live), with timestamps refreshed and
     * `$transform` applied.
     *
     * @param  array<string, mixed>  $rawAttributes
     * @param  list<string>  $reservedColumns
     * @param  list<string>  $aggregateColumns
     * @param  Closure(array<string, mixed>, int): array<string, mixed>|null  $transform
     * @return array<string, mixed>
     */
    private function prepareRowAttributes(
        array $rawAttributes,
        int $destinationDepth,
        array $reservedColumns,
        array $aggregateColumns,
        ?string $deletedAtColumn,
        ?Closure $transform,
    ): array {
        foreach ($reservedColumns as $column) {
            unset($rawAttributes[$column]);
        }
        foreach ($aggregateColumns as $column) {
            unset($rawAttributes[$column]);
        }
        if ($deletedAtColumn !== null) {
            unset($rawAttributes[$deletedAtColumn]);
        }

        $createdAtColumn = $this->clonePayloadCreatedAtColumn();
        $updatedAtColumn = $this->clonePayloadUpdatedAtColumn();
        if ($createdAtColumn !== null) {
            unset($rawAttributes[$createdAtColumn]);
        }
        if ($updatedAtColumn !== null) {
            unset($rawAttributes[$updatedAtColumn]);
        }

        if (! $transform instanceof Closure) {
            return $rawAttributes;
        }

        // PHP's @phpdoc contract narrows $transform's return type to
        // `array`, but PHP itself doesn't enforce phpdoc at runtime —
        // a caller could declare `mixed` (or no return type at all)
        // and return anything. Re-widen to mixed before the runtime
        // guard so the `is_array` check is a legitimate type narrow
        // for both PHPStan and runtime.
        /** @var mixed $result */
        $result = $transform($rawAttributes, $destinationDepth);

        if (! is_array($result)) {
            throw new LogicException(sprintf(
                'cloneSubtreeTo: $transform must return an array, got %s.',
                get_debug_type($result),
            ));
        }

        /** @var array<string, mixed> $stringKeyed */
        $stringKeyed = [];
        foreach ($result as $key => $value) {
            if (! is_string($key)) {
                throw new LogicException(sprintf(
                    'cloneSubtreeTo: $transform must return an array keyed by column name (string), got %s key.',
                    get_debug_type($key),
                ));
            }
            $stringKeyed[$key] = $value;
        }
        $result = $stringKeyed;

        $this->assertTransformReturnIsStructurallyClean($result, $reservedColumns);

        // Aggregate columns coming back from $transform get stripped
        // too — they're owned by the package and the recompute pass
        // would overwrite them anyway, but matching the structural-
        // rejection rule would over-fire on callers that just pass
        // through `$attributes` without touching aggregates.
        foreach ($aggregateColumns as $column) {
            unset($result[$column]);
        }

        return $result;
    }

    /**
     * Reorders `$root` under `$parent` at `$position`. The bulk insert
     * lands the clone at the last child slot; for `position = 'last'`
     * (the default) nothing to do.
     */
    private function placeAtPosition(
        self $root,
        self $parent,
        int|string $position,
    ): void {
        if ($position === 'last') {
            return;
        }

        $root->moveTo($parent, $position)->save();
    }

    private function placeAsRootAtPosition(
        self $root,
        int|string $position,
    ): void {
        if ($position === 'last') {
            return;
        }

        // Sequence the new root among existing roots in the same scope.
        // Convert 'first'/integer to a left-sibling insert against the
        // resolved sibling at that position. Out of range (or matching
        // the root's own current slot) falls back to no-op.
        $scopeValues = NestedSetScopeResolver::valuesFor($root);
        $rootQuery = static::query()
            ->whereNull($root->getParentIdName())
            ->whereKeyNot($root->getKey())
            ->orderBy($root->getLftName());
        foreach ($scopeValues as $col => $value) {
            $rootQuery->where($col, $value);
        }
        $roots = $rootQuery->get()->values();

        if ($roots->isEmpty()) {
            return;
        }

        if ($position === 'first') {
            $target = $roots->first();
        } elseif ($position === 0) {
            $target = $roots->first();
        } else {
            \assert(is_int($position));
            if ($position < 0) {
                throw new LogicException(sprintf(
                    'cloneSubtreeAsRoot: int position must be non-negative. Got %d.',
                    $position,
                ));
            }
            $target = $roots->get($position);
            if ($target === null) {
                return;
            }
        }

        $root->insertBeforeNode($target)->save();
    }

    // ----------------------------------------------------------------
    // Column inspection helpers
    // ----------------------------------------------------------------

    /**
     * Columns the package owns end-to-end on the destination row:
     * structural (lft/rgt/depth/parent_id), the primary key, and every
     * declared scope column. The payload-build path strips these from
     * the source's raw attributes; the `$transform` guard rejects them
     * if the closure tries to inject them.
     *
     * @return list<string>
     */
    private function reservedColumnNames(): array
    {
        $columns = [
            $this->getLftName(),
            $this->getRgtName(),
            $this->getDepthName(),
            $this->getParentIdName(),
            $this->getKeyName(),
        ];

        foreach (NestedSetScopeResolver::columns(static::class) as $column) {
            $columns[] = $column;
        }

        // Materialised-path columns are regenerated per-row from the
        // destination parent's stored path; allowing $transform to set
        // one would silently mask the package's recomputation.
        foreach (MaterialisedPathRegistry::columnsFor(static::class) as $column) {
            $columns[] = $column;
        }

        return array_values(array_unique($columns));
    }

    /**
     * Returns the names of every declared aggregate column (SQL +
     * listener + internal companions). Stripped from the destination
     * payload so the row inserts at zero/null — the deferred recompute
     * fills in the right value at the end of the clone's transaction.
     *
     * @return list<string>
     */
    private function aggregateColumnNames(): array
    {
        $columns = [];
        foreach (AggregateRegistry::for(static::class) as $definition) {
            $columns[] = $definition->getColumn();
        }

        return array_values(array_unique($columns));
    }

    private function softDeleteColumnName(): ?string
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            return null;
        }

        $column = (new \ReflectionMethod($this, 'getDeletedAtColumn'))->invoke($this);

        return is_string($column) ? $column : null;
    }

    private function isSourceTrashed(): bool
    {
        return $this->modelIsTrashed($this);
    }

    private function modelIsTrashed(HasNestedSet $node): bool
    {
        if (! $node instanceof Model) {
            return false;
        }

        if (! in_array(SoftDeletes::class, class_uses_recursive($node), true)) {
            return false;
        }

        // SoftDeletes::trashed() exists at runtime when the trait is
        // applied — PHPStan can't prove that per concrete class. Use
        // ReflectionMethod for type safety without an @ignore.
        $trashed = (new \ReflectionMethod($node, 'trashed'))->invoke($node);

        return $trashed === true;
    }

    private function clonePayloadCreatedAtColumn(): ?string
    {
        if (! $this->usesTimestamps()) {
            return null;
        }

        return static::CREATED_AT;
    }

    private function clonePayloadUpdatedAtColumn(): ?string
    {
        if (! $this->usesTimestamps()) {
            return null;
        }

        return static::UPDATED_AT;
    }

    private function describeKey(): string
    {
        if (! $this instanceof Model) {
            return '?';
        }

        $key = $this->getKey();

        return is_int($key) || is_string($key) ? (string) $key : '?';
    }

    private function describeKeyOf(HasNestedSet $node): string
    {
        if (! $node instanceof Model) {
            return '?';
        }

        $key = $node->getKey();

        return is_int($key) || is_string($key) ? (string) $key : '?';
    }

    /**
     * Validates that the freshly-cloned root's materialised-path columns
     * don't collide with an existing sibling, for every column declared
     * uniquePerParent. Only the clone root can collide — its descendants
     * carry new parent ids and paths copied from the (already-valid)
     * source subtree. Throws DuplicatePathSegmentException (rolling back the clone
     * transaction) when a collision is found, matching a normal save().
     */
    private static function assertClonedPathsUnique(self $root): void
    {
        foreach (MaterialisedPathRegistry::for(static::class) as $column => $path) {
            if (! $path->getUniquePerParent()) {
                continue;
            }

            $value = $root->getAttribute($column);
            if (is_string($value) && $value !== '') {
                $root->assertNoSiblingPathCollision($column, $value);
            }
        }
    }
}
