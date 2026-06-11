<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Concerns;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\DuplicatePathSegment;
use Vusys\NestedSet\Exceptions\EmptyPathSegment;
use Vusys\NestedSet\Exceptions\InvalidPathSegment;
use Vusys\NestedSet\Exceptions\NonDeterministicPathSegment;
use Vusys\NestedSet\Exceptions\PathTooLong;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPath;
use Vusys\NestedSet\MaterialisedPath\MaterialisedPathRegistry;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Materialised-path lifecycle: keeps every declared path column coherent
 * with the tree on insert, update, move, and subtree rewrite.
 *
 * Hooks Eloquent's `saving` and `saved` events. `saving` computes each
 * path inline (non-key-dependent) and queues a subtree-rewrite UPDATE
 * for existing rows whose path actually changed. `saved` handles
 * key-dependent paths on freshly-inserted rows (the autoincrement key
 * isn't known pre-INSERT, so the value must be set with a second
 * targeted UPDATE).
 *
 * A per-class bypass counter mirrors the aggregate-deferral pattern —
 * {@see self::withoutMaterialisedPathMaintenance()} short-circuits both
 * listeners; the caller is expected to follow up with
 * {@see HasTreeRepair::fixMaterialisedPaths()} once the bulk work is
 * done.
 *
 * @mixin Model
 * @mixin HasNestedSet
 */
trait HasMaterialisedPath
{
    /**
     * Reentrancy counter for {@see self::withoutMaterialisedPathMaintenance()}.
     * When > 0 the saving/saved listeners early-return before any
     * segment computation or DB writes.
     *
     * Per-trait private statics give every using class its own counter,
     * so bypassing maintenance on `Category` doesn't affect `Page`.
     */
    private static int $materialisedPathBypassDepth = 0;

    /**
     * Per-save cache of parent-path lookups so two declared columns
     * sharing a parent only hit the DB once. Keyed by column name —
     * different columns have different stored values on the parent
     * row, so the cache key must include the column.
     *
     * @var array<string, string>
     */
    private array $materialisedPathParentCache = [];

    /**
     * Subtree-rewrite UPDATEs queued during `saving` and executed by
     * `saved` (after the row itself is written). Queueing the rewrite
     * lets us issue one UPDATE per changed path regardless of subtree
     * size, all inside the wrapping transaction.
     *
     * @var list<array{column: string, oldPrefix: string, newPrefix: string}>
     */
    private array $materialisedPathPendingSubtreeRewrites = [];

    /**
     * Resolves the declared {@see MaterialisedPath} for a single column.
     * Throws when the column isn't declared on the model.
     */
    /**
     * Default declaration list — attribute-only models inherit this
     * empty array. Override with `protected static function
     * materialisedPaths(): array` to add closure-form columns or to
     * override an attribute-declared column on the same model.
     *
     * @return array<string, MaterialisedPath|callable|string>
     */
    protected static function materialisedPaths(): array
    {
        return [];
    }

    public function materialisedPathFor(string $column): MaterialisedPath
    {
        $paths = MaterialisedPathRegistry::for(static::class);
        if (! isset($paths[$column])) {
            throw new \InvalidArgumentException(sprintf(
                '%s does not declare a materialised-path column named "%s". Declared columns: %s.',
                static::class,
                $column,
                $paths === [] ? '(none)' : implode(', ', array_keys($paths)),
            ));
        }

        return $paths[$column];
    }

    /**
     * Runs `$work` with materialised-path maintenance disabled for this
     * class. Within the closure, no listener fires, no UPDATEs are
     * emitted. Use this for bulk renames and large imports where you
     * intend to run a follow-up
     * {@see HasTreeRepair::fixMaterialisedPaths()} to restore
     * consistency. Reentrant (depth-counted) so wrapped wrappers
     * compose.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function withoutMaterialisedPathMaintenance(Closure $work): mixed
    {
        self::$materialisedPathBypassDepth++;
        try {
            return $work();
        } finally {
            self::$materialisedPathBypassDepth--;
        }
    }

    /**
     * True while the bypass counter is open. Lets the bulk-insert /
     * clone / repair helpers branch on whether to run path maintenance
     * inline.
     */
    public static function isMaterialisedPathMaintenanceBypassed(): bool
    {
        return self::$materialisedPathBypassDepth > 0;
    }

    /**
     * `saving` hook: compute and validate every declared non-key
     * column inline, queue subtree-rewrite UPDATEs for existing rows
     * whose path changed.
     */
    public function applyMaterialisedPathsOnSaving(): void
    {
        $this->materialisedPathPendingSubtreeRewrites = [];
        $this->materialisedPathParentCache = [];

        if (self::$materialisedPathBypassDepth > 0) {
            return;
        }

        $paths = MaterialisedPathRegistry::for(static::class);
        if ($paths === []) {
            return;
        }

        foreach ($paths as $column => $path) {
            // Key-dependent paths on new rows must wait for `saved` —
            // $node->getKey() is null until autoincrement runs.
            if (! $this->exists && $path->isDependentOnKey() && $this->getKey() === null) {
                continue;
            }

            $segment = $path->segmentFor($this);

            if ($this->isPathDeterminismGuarded()) {
                $second = $path->segmentFor($this);
                if ($second !== $segment) {
                    throw new NonDeterministicPathSegment(sprintf(
                        '%s: materialised-path builder for column "%s" returned different values on '
                        .'repeated calls within one save. The builder must be a pure function of the '
                        .'node\'s persisted attributes — avoid request(), auth(), now(), or any other '
                        .'non-attribute state.',
                        static::class,
                        $column,
                    ), column: $column, modelClass: static::class);
                }
                unset($second);
            }

            $segment = $this->validateSegment($column, $path, $segment);

            $parentPath = $this->resolveParentPath($column);
            $newFullPath = $this->assemblePath($parentPath, $segment, $path);

            if (strlen((string) $newFullPath) > $path->getMaxLength()) {
                throw new PathTooLong(sprintf(
                    '%s: materialised-path column "%s" computed length %d exceeds the configured maxLength %d.',
                    static::class,
                    $column,
                    strlen((string) $newFullPath),
                    $path->getMaxLength(),
                ), column: $column, modelClass: static::class, length: strlen((string) $newFullPath), maxLength: $path->getMaxLength());
            }

            $originalValue = $this->exists ? $this->getOriginal($column) : null;
            $originalString = is_string($originalValue) ? $originalValue : null;

            if ($originalString === $newFullPath) {
                continue;
            }

            if ($path->getUniquePerParent()) {
                $this->assertNoSiblingPathCollision($column, $newFullPath);
            }

            $this->setAttribute($column, $newFullPath);

            // Always queue the rewrite when the path actually changed on
            // an existing row. The in-memory rgt can be stale (a previous
            // descendant insert shifted it in the DB but didn't refresh
            // the model), so a hasDescendants() short-circuit at this
            // layer would skip needed work. A no-descendant rewrite is
            // a single zero-row UPDATE — cheap and correct.
            if ($this->exists && $originalString !== null && $originalString !== '') {
                $this->materialisedPathPendingSubtreeRewrites[] = [
                    'column' => $column,
                    'oldPrefix' => $originalString,
                    'newPrefix' => $newFullPath,
                ];
            }
        }
    }

    /**
     * `saved` hook: emit queued subtree-rewrite UPDATEs and handle
     * key-dependent paths on freshly-inserted rows.
     */
    public function applyMaterialisedPathsOnSaved(): void
    {
        if (self::$materialisedPathBypassDepth > 0) {
            $this->materialisedPathPendingSubtreeRewrites = [];

            return;
        }

        foreach ($this->materialisedPathPendingSubtreeRewrites as $rewrite) {
            $this->emitSubtreeRewrite($rewrite['column'], $rewrite['oldPrefix'], $rewrite['newPrefix']);
        }
        $this->materialisedPathPendingSubtreeRewrites = [];

        $paths = MaterialisedPathRegistry::for(static::class);
        if ($paths === []) {
            return;
        }

        $keyDependentUpdates = [];
        foreach ($paths as $column => $path) {
            if (! $path->isDependentOnKey()) {
                continue;
            }

            $currentValue = $this->getAttribute($column);
            $currentString = is_string($currentValue) ? $currentValue : null;
            if ($currentString !== null && $currentString !== '') {
                continue;
            }

            if ($this->getKey() === null) {
                continue;
            }

            $segment = $path->segmentFor($this);
            $segment = $this->validateSegment($column, $path, $segment);
            $parentPath = $this->resolveParentPath($column);
            $newFullPath = $this->assemblePath($parentPath, $segment, $path);

            if (strlen((string) $newFullPath) > $path->getMaxLength()) {
                throw new PathTooLong(sprintf(
                    '%s: materialised-path column "%s" computed length %d exceeds the configured maxLength %d.',
                    static::class,
                    $column,
                    strlen((string) $newFullPath),
                    $path->getMaxLength(),
                ), column: $column, modelClass: static::class, length: strlen((string) $newFullPath), maxLength: $path->getMaxLength());
            }

            if ($path->getUniquePerParent()) {
                $this->assertNoSiblingPathCollision($column, $newFullPath);
            }

            $this->setAttribute($column, $newFullPath);
            $this->syncOriginalAttribute($column);
            $keyDependentUpdates[$column] = $newFullPath;
        }

        if ($keyDependentUpdates !== []) {
            $this->getConnection()
                ->table($this->getTable())
                ->where($this->getKeyName(), $this->getKey())
                ->update($keyDependentUpdates);
        }
    }

    /**
     * Validates a segment per the column's options. Returns the segment
     * possibly with the separator stripped, when
     * `rejectSeparatorInSegment` is false.
     */
    private function validateSegment(string $column, MaterialisedPath $path, string $segment): string
    {
        if ($segment === '') {
            throw new EmptyPathSegment(sprintf(
                '%s: materialised-path column "%s" segment builder returned an empty string. '
                .'Provide a non-empty source attribute or guard the builder against null/empty values.',
                static::class,
                $column,
            ), column: $column, modelClass: static::class);
        }

        $sep = $path->getSeparator();
        if ($sep !== '' && str_contains($segment, $sep)) {
            if ($path->getRejectSeparatorInSegment()) {
                throw new InvalidPathSegment(sprintf(
                    '%s: materialised-path column "%s" segment "%s" contains the configured '
                    .'separator "%s". Configure rejectSeparatorInSegment: false to silently strip '
                    .'or fix the source attribute.',
                    static::class,
                    $column,
                    $segment,
                    $sep,
                ), column: $column, modelClass: static::class);
            }

            $segment = str_replace($sep, '', $segment);
            if ($segment === '') {
                throw new EmptyPathSegment(sprintf(
                    '%s: materialised-path column "%s" segment is empty after stripping the separator. '
                    .'Source attribute consists entirely of separator characters.',
                    static::class,
                    $column,
                ), column: $column, modelClass: static::class);
            }
        }

        return $segment;
    }

    /**
     * Resolves the parent's stored path for the given column. Uses the
     * in-memory parent relation when loaded; otherwise issues one
     * targeted SELECT keyed by parent_id (cached per save).
     */
    private function resolveParentPath(string $column): ?string
    {
        $parentIdName = $this->getParentIdName();
        /** @var int|string|null $parentId */
        $parentId = $this->getAttribute($parentIdName);
        if (in_array($parentId, [null, '', 0], true)) {
            return null;
        }

        $cacheKey = $column.'@'.$parentId;
        if (array_key_exists($cacheKey, $this->materialisedPathParentCache)) {
            // Empty-string sentinel means "looked up, no path"; any other
            // value (including the literal '0') is a legitimate stored
            // path and must pass through. `?:` would collapse '0' to null.
            $cached = $this->materialisedPathParentCache[$cacheKey];

            return $cached === '' ? null : $cached;
        }

        if ($this->relationLoaded('parent')) {
            $parent = $this->getRelation('parent');
            if ($parent instanceof Model) {
                $value = $parent->getAttribute($column);
                if (is_string($value)) {
                    $this->materialisedPathParentCache[$cacheKey] = $value;

                    return $value;
                }
            }
        }

        $row = $this->getConnection()
            ->table($this->getTable())
            ->where($this->getKeyName(), $parentId)
            ->first([$column]);

        $value = ($row !== null && isset($row->{$column}) && is_string($row->{$column})) ? $row->{$column} : '';

        $stored = $value;
        $this->materialisedPathParentCache[$cacheKey] = $stored;

        return $stored === '' ? null : $stored;
    }

    /**
     * Assembles a full path from the parent path and the new segment,
     * honouring the column's wrap and separator settings.
     */
    private function assemblePath(?string $parentPath, string $segment, MaterialisedPath $path): string
    {
        $sep = $path->getSeparator();
        $wrap = $path->getWrap();

        if ($parentPath === null) {
            return $wrap ? $sep.$segment.$sep : $segment;
        }

        if ($wrap) {
            // Parent ends in separator; append `segment` + separator.
            return $parentPath.$segment.$sep;
        }

        return $parentPath.$sep.$segment;
    }

    /**
     * Emits one UPDATE rewriting the path column on every strict
     * descendant. The per-backend SQL differs only in concat operator
     * (`CONCAT()` on MySQL/MariaDB, `||` on PG/SQLite) and substring
     * function (`SUBSTRING(col, n)` on MySQL, `SUBSTRING(col FROM n)`
     * on PG, `SUBSTR(col, n)` on SQLite). All three are 1-indexed.
     */
    private function emitSubtreeRewrite(string $column, string $oldPrefix, string $newPrefix): void
    {
        $connection = $this->getConnection();
        $table = $this->getTable();
        $lft = $this->getLftName();
        $rgt = $this->getRgtName();
        $key = $this->getKey();
        $keyName = $this->getKeyName();
        // Character length, not byte length: the SUBSTRING/SUBSTR functions
        // in the rewrite SQL are character-indexed on utf8mb4 MySQL, PG and
        // SQLite. A byte offset would chop characters off every descendant
        // path the moment the old prefix contains any non-ASCII character —
        // including the ' › ' separator used throughout the docs.
        $oldLen = mb_strlen($oldPrefix, 'UTF-8');

        // Read fresh bounds from the DB — in-memory rgt grows as
        // descendants are added without refreshing this model, so the
        // BETWEEN range would miss rows if we trusted the stale value.
        $row = $connection->table($table)
            ->where($keyName, $key)
            ->first([$lft, $rgt]);
        if ($row === null) {
            return;
        }
        $freshLft = (int) ($row->{$lft} ?? 0);
        $freshRgt = (int) ($row->{$rgt} ?? 0);
        if ($freshRgt - $freshLft < 1) {
            return;
        }
        $bounds = (object) ['lft' => $freshLft, 'rgt' => $freshRgt];

        $sql = self::buildSubtreeRewriteSql($connection, $table, $column, $lft, $keyName, NestedSetScopeResolver::valuesFor($this));

        $bindings = [$newPrefix, $oldLen + 1];
        foreach (NestedSetScopeResolver::valuesFor($this) as $value) {
            $bindings[] = $value;
        }
        $bindings[] = $bounds->lft;
        $bindings[] = $bounds->rgt;
        $bindings[] = $key;

        $connection->update($sql, $bindings);
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private static function buildSubtreeRewriteSql(
        Connection $connection,
        string $table,
        string $column,
        string $lftCol,
        string $keyName,
        array $scope,
    ): string {
        $grammar = $connection->getQueryGrammar();
        $tableQ = $grammar->wrap($table);
        $colQ = $grammar->wrap($column);
        $lftQ = $grammar->wrap($lftCol);
        $keyQ = $grammar->wrap($keyName);

        // PG's parameter-type inference can't resolve a positional `?`
        // inside `SUBSTRING(col FROM ?)` — it defaults to text and the
        // expression evaluates to NULL. SUBSTR(text, int) has a single
        // overload PG can match, so the second placeholder is inferred
        // as integer there. SQLite accepts SUBSTR too; MySQL/MariaDB
        // stays on CONCAT() + SUBSTRING(col, n) (where `||` is logical
        // OR by default).
        $concatExpr = match (true) {
            $connection instanceof MySqlConnection => 'CONCAT(?, SUBSTRING('.$colQ.', ?))',
            $connection instanceof PostgresConnection => '? || SUBSTR('.$colQ.', ?)',
            $connection instanceof SQLiteConnection => '? || SUBSTR('.$colQ.', ?)',
            default => '? || SUBSTR('.$colQ.', ?)',
        };

        // Scope predicates must appear in iteration order so their
        // placeholders line up with the scope values, which emitSubtreeRewrite
        // appends forward. Prepending each predicate (reversing column order)
        // bound tenant_id to menu_id and vice versa on multi-column scopes,
        // so rewrites missed every descendant or hit another tenant's rows.
        // Scope predicates must appear in iteration order so their
        // placeholders line up with the scope values, which emitSubtreeRewrite
        // appends forward. Prepending each predicate (reversing column order)
        // bound tenant_id to menu_id and vice versa on multi-column scopes,
        // so rewrites missed every descendant or hit another tenant's rows.
        $scopeClause = '';
        foreach (array_keys($scope) as $scopeColumn) {
            $scopeClause .= $grammar->wrap($scopeColumn).' = ? AND ';
        }

        $where = $scopeClause.$lftQ.' BETWEEN ? AND ? AND '.$keyQ.' <> ?';

        return 'UPDATE '.$tableQ.' SET '.$colQ.' = '.$concatExpr.' WHERE '.$where;
    }

    /**
     * Verifies no sibling under this row's parent (and scope) already
     * holds the new path value. Uses an indexed equality check on the
     * declared column — O(1) with the index, O(siblings) without.
     */
    private function assertNoSiblingPathCollision(string $column, string $newFullPath): void
    {
        $parentIdName = $this->getParentIdName();
        $parentId = $this->getAttribute($parentIdName);

        $query = $this->getConnection()
            ->table($this->getTable())
            ->where($column, $newFullPath);

        if ($parentId === null) {
            $query->whereNull($parentIdName);
        } else {
            $query->where($parentIdName, $parentId);
        }

        foreach (NestedSetScopeResolver::valuesFor($this) as $scopeCol => $scopeVal) {
            $query->where($scopeCol, $scopeVal);
        }

        if ($this->exists && $this->getKey() !== null) {
            $query->where($this->getKeyName(), '<>', $this->getKey());
        }

        if ($query->exists()) {
            $parentIdForMessage = match (true) {
                $parentId === null => '(root)',
                is_int($parentId), is_string($parentId) => (string) $parentId,
                default => '(unknown)',
            };
            $parentIdForException = (is_int($parentId) || is_string($parentId)) ? $parentId : null;

            throw new DuplicatePathSegment(sprintf(
                '%s: materialised-path column "%s" — sibling already holds path "%s" under parent %s. '
                .'Configure uniquePerParent: false or change the source attribute.',
                static::class,
                $column,
                $newFullPath,
                $parentIdForMessage,
            ), column: $column, modelClass: static::class, segment: $newFullPath, parentId: $parentIdForException);
        }
    }

    /**
     * Determinism guard runs only when `config('app.debug')` is true. In
     * prod the second `segmentFor` call never happens.
     */
    private function isPathDeterminismGuarded(): bool
    {
        return (bool) config('app.debug', false);
    }
}
