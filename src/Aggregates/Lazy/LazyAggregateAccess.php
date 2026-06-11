<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Lazy;

use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Aggregates\Sql\AggregateSqlEmitter;
use Vusys\NestedSet\Aggregates\Strategy\LazyInvalidation;
use Vusys\NestedSet\Concerns\HasNestedSetAggregates;
use Vusys\NestedSet\Contracts\AggregateDefinitionContract;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Contracts\MaintainsTreeAggregates;
use Vusys\NestedSet\Exceptions\NestedSetRuntimeException;
use Vusys\NestedSet\NodeBounds;

/**
 * Read-side support for lazy aggregate columns plus the ancestor-chain
 * invalidation issued from every lifecycle hook.
 *
 * Extracted from {@see HasNestedSetAggregates} so the read path
 * (`getAttribute` interception, stamp/TTL gating, on-demand refresh)
 * and the write-side invalidation (null out the value + stamp on every
 * affected ancestor) live alongside the existing
 * {@see LazyInvalidation} write helper.
 */
final class LazyAggregateAccess
{
    /**
     * Returns the lazy aggregate definition (SQL or listener) declared
     * on the given model for the named column, or null if `$column`
     * does not name a lazy aggregate. Used as the fast-path
     * discriminator on every attribute read.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     */
    public static function definitionForColumn(string $modelClass, string $column): ?AggregateDefinitionContract
    {
        foreach (AggregateRegistry::for($modelClass) as $definition) {
            if ($definition->isLazy() && $definition->getColumn() === $column) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * True when a lazy aggregate column needs a fresh recompute on
     * read — either the stamp companion is NULL or it is older than
     * the definition's TTL.
     *
     * @param  array<string, mixed>  $rawAttributes  Output of `getAttributes()` on the model.
     */
    public static function isStale(AggregateDefinitionContract $definition, array $rawAttributes): bool
    {
        $stampColumn = $definition->lazyStampColumn();
        $raw = $rawAttributes[$stampColumn] ?? null;

        if ($raw === null) {
            return true;
        }

        $ttl = $definition->lazyTtlSeconds();
        if ($ttl === null) {
            return false;
        }

        if (is_int($raw) || is_float($raw)) {
            $stampUnix = (int) $raw;
        } elseif (is_string($raw)) {
            $parsed = strtotime($raw);
            if ($parsed === false) {
                return true;
            }
            $stampUnix = $parsed;
        } else {
            return true;
        }

        return ($stampUnix + $ttl) < time();
    }

    /**
     * Recomputes a single lazy aggregate column for the node via
     * `freshAggregate()` and writes the new value plus the stamp
     * companion to both the database row and the in-memory model.
     *
     * @param  Model&MaintainsTreeAggregates  $node
     */
    public static function refresh(Model $node, AggregateDefinitionContract $definition): void
    {
        $column = $definition->getColumn();
        $stampColumn = $definition->lazyStampColumn();

        $value = $node->freshAggregate($column);
        $now = $node->freshTimestampString();

        $node->getConnection()->table($node->getTable())
            ->where($node->getKeyName(), $node->getKey())
            ->update([
                $column => $value,
                $stampColumn => $now,
            ]);

        $node->setAttribute($column, $value);
        $node->setAttribute($stampColumn, $now);
        $node->syncOriginalAttribute($column);
        $node->syncOriginalAttribute($stampColumn);
    }

    /**
     * Returns the columns whose dirty state should trigger lazy
     * invalidation for a SQL aggregate definition. Unions
     * {@see AggregateDefinition::triggerColumns()} (source + filter +
     * weight) with {@see AggregateSqlEmitter::watchColumns()} so
     * collection aggregates (JsonAgg/JsonObjectAgg/StringAgg) catch
     * their per-row field columns and multi-source JsonAgg catches
     * every named source.
     *
     * @return list<string>
     */
    public static function watchColumnsForSql(AggregateDefinition $definition): array
    {
        return array_values(array_unique(array_merge(
            $definition->triggerColumns(),
            AggregateSqlEmitter::watchColumns($definition),
        )));
    }

    /**
     * Returns the names of every lazy aggregate column declared on a
     * model class. Used by lifecycle hooks (create / delete / restore /
     * move) where the mutation invalidates uniformly — the per-column
     * dirty check that gates the save path doesn't apply because the
     * row itself appeared, disappeared, moved, or restored.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @return list<string>
     */
    public static function allLazyColumns(string $modelClass): array
    {
        $names = [];
        foreach (AggregateRegistry::for($modelClass) as $definition) {
            if ($definition->isLazy()) {
                $names[] = $definition->getColumn();
            }
        }

        return $names;
    }

    /**
     * Issues one {@see LazyInvalidation::apply()} call for the named
     * lazy aggregate columns over the supplied bounds and scope.
     * Resolves stamp column and inclusivity from the registry; entries
     * that aren't lazy or aren't declared on the model are silently
     * skipped.
     *
     * `$excludeSelf` forces strict bounds for every spec — used by the
     * restore hook, where self's lazy columns have already been
     * populated by a prior `fixAggregates()` pass and only the proper
     * ancestors are stale.
     *
     * @param  Model&HasNestedSet  $node
     * @param  list<string>  $columnNames
     * @param  array<string, mixed>  $scope
     */
    public static function invalidate(
        Model $node,
        array $columnNames,
        NodeBounds $bounds,
        array $scope,
        ?string $softDeletedColumn,
        bool $excludeSelf = false,
    ): void {
        if ($columnNames === []) {
            return;
        }

        $specs = AggregateRegistry::lazySpecsForColumns($node::class, $columnNames);

        if ($specs === []) {
            return;
        }

        LazyInvalidation::apply(
            connection: $node->getConnection(),
            table: $node->getTable(),
            lftCol: $node->getLftName(),
            rgtCol: $node->getRgtName(),
            bounds: $bounds,
            columns: $specs,
            scope: $scope,
            softDeletedColumn: $softDeletedColumn,
            excludeSelf: $excludeSelf,
        );
    }

    /**
     * Stamps every lazy aggregate's `<column>_computed_at` companion to
     * NOW across the rows fixAggregates() (or fixAggregatesChunk) just
     * repaired. `fixAggregates` writes fresh values to the value
     * columns; without an accompanying stamp, the next read would treat
     * those rows as stale and immediately recompute, wasting the
     * repair.
     *
     * Bounded to the same anchor / scope / chunk subset the differ
     * touched, so per-chunk calls only stamp their own rows.
     *
     * @param  Model&HasNestedSet  $instance
     * @param  array<string, mixed>  $scope
     * @param  list<int|string>|null  $outerIds  When non-null, restricts
     *                                           the stamp pass to this
     *                                           chunk's outer rows.
     */
    public static function stampForFix(
        Model $instance,
        array $scope,
        int|string|null $rootId,
        ?array $outerIds,
        ?string $softDeletedColumn,
    ): void {
        $stampColumns = [];
        foreach (AggregateRegistry::for($instance::class) as $definition) {
            if ($definition->isLazy()) {
                $stampColumns[$definition->lazyStampColumn()] = true;
            }
        }
        if ($stampColumns === []) {
            return;
        }

        if ($outerIds !== null && $outerIds === []) {
            return;
        }

        $stamp = $instance->freshTimestampString();
        $updates = array_map(static fn (): string => $stamp, $stampColumns);

        $query = $instance->getConnection()->table($instance->getTable());

        if ($rootId !== null) {
            $bounds = self::anchorBoundsRow($instance, $rootId);
            // Missing-row guard: the lazy stamp pass runs after the
            // differ. If the anchor row disappeared mid-repair (rare,
            // but possible under concurrent hard-delete) the stamp UPDATE
            // would silently widen to every row in scope. Fail loudly
            // instead — the caller can decide whether to retry or skip.
            if ($bounds === null) {
                throw new NestedSetRuntimeException(sprintf(
                    '%s::stampForFix: anchor id %s not found — was the row deleted? '
                    .'Refusing to widen the lazy-stamp UPDATE to the whole scope.',
                    $instance::class,
                    (string) $rootId,
                ));
            }
            $query->where($instance->getLftName(), '>=', $bounds['lft'])
                ->where($instance->getRgtName(), '<=', $bounds['rgt']);
        }

        foreach ($scope as $col => $value) {
            $query->where($col, '=', $value);
        }

        if ($softDeletedColumn !== null) {
            $query->whereNull($softDeletedColumn);
        }

        if ($outerIds !== null) {
            $query->whereIn($instance->getKeyName(), $outerIds);
        }

        $query->update($updates);
    }

    /**
     * Fetches just the `lft` / `rgt` of the row whose primary key
     * equals `$rootId`. Returns null when the row no longer exists —
     * the differ already ran, so the missing-row case would have been
     * a no-op anyway. Reads raw integers off the connection to keep
     * the lazy-stamp UPDATE path free of model hydration, casts, and
     * events.
     *
     * @param  Model&HasNestedSet  $instance
     * @return array{lft: int, rgt: int}|null
     */
    private static function anchorBoundsRow(Model $instance, int|string $rootId): ?array
    {
        $row = $instance->getConnection()
            ->table($instance->getTable())
            ->where($instance->getKeyName(), $rootId)
            ->first([$instance->getLftName(), $instance->getRgtName()]);

        if ($row === null) {
            return null;
        }

        $lft = $row->{$instance->getLftName()} ?? null;
        $rgt = $row->{$instance->getRgtName()} ?? null;

        if (! is_numeric($lft) || ! is_numeric($rgt)) {
            return null;
        }

        return ['lft' => (int) $lft, 'rgt' => (int) $rgt];
    }
}
