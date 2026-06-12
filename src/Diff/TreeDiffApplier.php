<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Diff;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Diff\TreeChange\Added;
use Vusys\NestedSet\Diff\TreeChange\Modified;
use Vusys\NestedSet\Diff\TreeChange\Moved;
use Vusys\NestedSet\Diff\TreeChange\Removed;
use Vusys\NestedSet\Exceptions\CyclicMoveException;
use Vusys\NestedSet\Exceptions\MissingParentException;
use Vusys\NestedSet\Exceptions\NestedSetLogicException;

/**
 * Applies a {@see TreeDiff} to a live model class.
 *
 * Ordering: add → move → remove → modify, all under one transaction
 * with aggregate maintenance deferred to a single trailing pass. The
 * applier is stateless; every operation hangs off the diff + model
 * class passed in.
 *
 * Identity resolution uses the diff's `$on`. When `$on === 'id'` the
 * resolver is the identity function; otherwise the default does a
 * single `whereIn` against the named column. Callers with non-trivial
 * mapping (composite keys, scoped lookups) pass an explicit
 * `$resolver` closure.
 */
final class TreeDiffApplier
{
    /**
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @param  Closure(mixed): (int|string|null)|null  $resolver
     */
    public static function apply(
        TreeDiff $diff,
        string $modelClass,
        ?Closure $resolver,
        bool $dryRun,
    ): TreeDiffResult {
        if ($diff->isEmpty()) {
            return new TreeDiffResult(
                added: [],
                removed: [],
                moved: [],
                modified: [],
                dryRun: $dryRun,
                plannedStatements: [],
            );
        }

        self::assertCycleFree($diff);

        $instance = self::makeInstance($modelClass);

        self::assertSchemaMatches($diff, $instance);

        $on = $diff->on;
        $identities = self::collectIdentities($diff);
        $resolved = $resolver instanceof Closure
            ? self::resolveAll($identities, $resolver)
            : self::resolveDefault($modelClass, $on, $identities);
        $resolver ??= static fn (mixed $identity): int|string|null => self::resolveLookup($identity, $resolved);

        if ($dryRun) {
            return self::dryRunResult($diff);
        }

        $accumulator = new TreeDiffApplierAccumulator;

        // Order: add → move → remove → reorder → modify. Adds run first so
        // a move can target a newly-added parent. Moves run before removes
        // so a child the diff retains is re-parented OUT of a doomed subtree
        // before the remove's hard-delete cascade would take it with the
        // parent. Sibling placement is *deferred* to a single reorder pass
        // after removes: an added node ranked after a moved-in sibling can't
        // be positioned until that sibling exists (doAdds runs before
        // doMoves), and running the placement inline threw "position out of
        // range". The reorder pass sees the final sibling set.
        $work = static function () use ($diff, $modelClass, $resolver, &$resolved, $accumulator): void {
            $reorderTargets = [];
            self::doAdds($diff, $modelClass, $resolver, $resolved, $accumulator, $reorderTargets);
            self::doMoves($diff, $modelClass, $resolved, $accumulator, $reorderTargets);
            self::doRemoves($diff, $modelClass, $resolved, $accumulator);
            self::doReorders($modelClass, $reorderTargets);
            self::doModifies($diff, $modelClass, $resolved, $accumulator);
        };

        $connection = $instance->getConnection();
        // Transaction OUTSIDE the deferral so the trailing fixAggregates pass
        // commits atomically with the changes — a crash between commit and
        // the deferred repair would otherwise leave persistent aggregate
        // drift.
        $connection->transaction(static function () use ($modelClass, $work): void {
            self::callStatic($modelClass, 'withDeferredAggregateMaintenance', [
                $work,
                null,
            ]);
        });

        return new TreeDiffResult(
            added: $accumulator->added,
            removed: $accumulator->removed,
            moved: $accumulator->moved,
            modified: $accumulator->modified,
            dryRun: false,
            plannedStatements: [],
        );
    }

    /**
     * @param  class-string<Model&HasNestedSet>  $modelClass
     */
    private static function makeInstance(string $modelClass): Model
    {
        return new $modelClass;
    }

    /**
     * Invokes a `NodeTrait`-provided static method on `$modelClass`.
     *
     * The {@see HasNestedSet} contract intentionally omits the trait's
     * mutation surface (`bulkInsertTree`, `withDeferredAggregateMaintenance`,
     * etc.) so user code can implement the contract by hand for tests.
     * Dispatching through a callable keeps the contract minimal while
     * letting the applier call the methods every real fixture exposes.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @param  list<mixed>  $args
     */
    private static function callStatic(string $modelClass, string $method, array $args): mixed
    {
        $callable = [$modelClass, $method];
        if (! is_callable($callable)) {
            throw new NestedSetLogicException(sprintf(
                'TreeDiff::apply(): %s does not expose static %s() — typically NodeTrait is missing.',
                $modelClass,
                $method,
            ));
        }

        return $callable(...$args);
    }

    /**
     * Invokes a `NodeTrait`-provided instance method on a freshly-fetched
     * model row. See {@see self::callStatic()} for the rationale.
     *
     * @param  list<mixed>  $args
     */
    private static function callInstance(Model $model, string $method, array $args): mixed
    {
        $callable = [$model, $method];
        if (! is_callable($callable)) {
            throw new NestedSetLogicException(sprintf(
                'TreeDiff::apply(): %s::%s() not callable — typically NodeTrait is missing on the model.',
                $model::class,
                $method,
            ));
        }

        return $callable(...$args);
    }

    /**
     * Validates that every column the diff intends to write exists on
     * the model. The check is up-front so a half-applied diff never
     * lands.
     */
    private static function assertSchemaMatches(TreeDiff $diff, Model $instance): void
    {
        $table = $instance->getTable();
        $columns = $instance->getConnection()->getSchemaBuilder()->getColumnListing($table);
        $known = array_fill_keys($columns, true);

        $offenders = [];

        foreach ($diff->added as $added) {
            foreach (array_keys($added->attributes) as $col) {
                if (! isset($known[$col])) {
                    $offenders[$col] = true;
                }
            }
        }

        foreach ($diff->modified as $mod) {
            foreach (array_keys($mod->after) as $col) {
                if (! isset($known[$col])) {
                    $offenders[$col] = true;
                }
            }
        }

        if ($offenders !== []) {
            throw new NestedSetLogicException(sprintf(
                'TreeDiff::apply(): %s has no columns %s — refusing to apply.',
                $instance::class,
                implode(', ', array_keys($offenders)),
            ));
        }
    }

    private static function assertCycleFree(TreeDiff $diff): void
    {
        /** @var array<string, string|null> $parentByKey */
        $parentByKey = [];
        foreach ($diff->moved as $m) {
            $parentByKey[self::keyHash($m->key)] = $m->toParent === null ? null : self::keyHash($m->toParent);
        }

        foreach ($diff->moved as $m) {
            $seen = [];
            $cursor = self::keyHash($m->key);
            $seen[$cursor] = true;
            while (array_key_exists($cursor, $parentByKey) && $parentByKey[$cursor] !== null) {
                $cursor = $parentByKey[$cursor];
                if (isset($seen[$cursor])) {
                    throw new CyclicMoveException(sprintf(
                        'TreeDiff::apply(): move set forms a cycle reachable from key %s.',
                        self::formatKey($m->key),
                    ));
                }
                $seen[$cursor] = true;
            }
        }
    }

    /**
     * @return list<mixed>
     */
    private static function collectIdentities(TreeDiff $diff): array
    {
        $identities = [];
        foreach ($diff->removed as $r) {
            $identities[self::keyHash($r->key)] = $r->key;
        }
        foreach ($diff->moved as $m) {
            $identities[self::keyHash($m->key)] = $m->key;
            if ($m->toParent !== null) {
                $identities[self::keyHash($m->toParent)] = $m->toParent;
            }
        }
        foreach ($diff->modified as $mod) {
            $identities[self::keyHash($mod->key)] = $mod->key;
        }
        foreach ($diff->added as $a) {
            if ($a->parentKey !== null) {
                $identities[self::keyHash($a->parentKey)] = $a->parentKey;
            }
        }

        return array_values($identities);
    }

    /**
     * @param  list<mixed>  $identities
     * @param  Closure(mixed): (int|string|null)  $resolver
     * @return array<string, int|string|null>
     */
    private static function resolveAll(array $identities, Closure $resolver): array
    {
        $out = [];
        foreach ($identities as $identity) {
            $out[self::keyHash($identity)] = $resolver($identity);
        }

        return $out;
    }

    /**
     * Default identity resolver — a single `whereIn` against the `$on`
     * column (or pass-through when `$on` is the model's primary key).
     * The closure-`$on` case falls through to pass-through; supplying
     * a custom resolver is the right path when identity isn't a column.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @param  list<mixed>  $identities
     * @return array<string, int|string|null>
     */
    private static function resolveDefault(string $modelClass, string|Closure $on, array $identities): array
    {
        $instance = self::makeInstance($modelClass);
        $keyName = $instance->getKeyName();

        $passthrough = $on instanceof Closure || $on === $keyName;

        $out = [];
        if ($passthrough) {
            foreach ($identities as $identity) {
                $out[self::keyHash($identity)] = is_int($identity) || is_string($identity) ? $identity : null;
            }

            return $out;
        }

        /** @var list<int|string> $scalarIdentities */
        $scalarIdentities = [];
        foreach ($identities as $identity) {
            if (is_int($identity) || is_string($identity)) {
                $scalarIdentities[] = $identity;
            } else {
                $out[self::keyHash($identity)] = null;
            }
        }

        if ($scalarIdentities !== []) {
            $rows = $modelClass::query()
                ->whereIn($on, $scalarIdentities)
                ->get([$keyName, $on]);

            /** @var array<int|string, int|string> $map */
            $map = [];
            foreach ($rows as $row) {
                $identityVal = $row->getAttribute($on);
                $pk = $row->getKey();
                if ((is_int($identityVal) || is_string($identityVal)) && (is_int($pk) || is_string($pk))) {
                    $map[$identityVal] = $pk;
                }
            }

            foreach ($scalarIdentities as $identity) {
                $out[self::keyHash($identity)] = $map[$identity] ?? null;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, int|string|null>  $resolved
     */
    private static function resolveLookup(mixed $identity, array $resolved): int|string|null
    {
        return $resolved[self::keyHash($identity)] ?? null;
    }

    /**
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @param  array<string, int|string|null>  $resolved
     */
    private static function doRemoves(
        TreeDiff $diff,
        string $modelClass,
        array $resolved,
        TreeDiffApplierAccumulator $accumulator,
    ): void {
        if ($diff->removed === []) {
            return;
        }

        $pks = [];
        $byPk = [];
        foreach ($diff->removed as $r) {
            $pk = $resolved[self::keyHash($r->key)] ?? null;
            if ($pk === null) {
                continue;
            }
            $pks[] = $pk;
            $byPk[self::keyHash($pk)] = $r->key;
        }

        if ($pks === []) {
            return;
        }

        $rows = $modelClass::query()->whereIn((new $modelClass)->getKeyName(), $pks)->get();

        $removedPkSet = [];
        foreach ($pks as $pk) {
            $removedPkSet[self::keyHash($pk)] = true;
        }
        $parentIdName = (new $modelClass)->getParentIdName();

        foreach ($rows as $row) {
            $key = $row->getKey();
            $applied = $byPk[self::keyHash($key)] ?? $key;
            if (is_int($applied) || is_string($applied)) {
                $accumulator->removed[] = $applied;
            }

            // Delete only the top-most removed nodes. A removed node whose
            // parent is also in the removed set is hard-deleted by its
            // ancestor's cascade (and on soft-delete models, stamped by the
            // cascade so stamp-matched restore still works). Calling
            // delete() on it again would run the structural cleanup against
            // stale bounds — the cascade already closed the gap — deleting
            // innocent rows and corrupting the tree. Moves run before
            // removes, so any surviving intermediate has already been moved
            // out: parent_id here reflects the post-move shape.
            $parentId = $row->getAttribute($parentIdName);
            if ($parentId !== null && isset($removedPkSet[self::keyHash($parentId)])) {
                continue;
            }

            $row->delete();
        }
    }

    /**
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @param  Closure(mixed): (int|string|null)  $resolver
     * @param  array<string, int|string|null>  $resolved
     * @param  list<array{pk: int|string, parentPk: int|string|null, position: int}>  $reorderTargets
     *
     * @param-out array<string, int|string|null>  $resolved
     * @param-out list<array{pk: int|string, parentPk: int|string|null, position: int}>  $reorderTargets
     */
    private static function doAdds(
        TreeDiff $diff,
        string $modelClass,
        Closure $resolver,
        array &$resolved,
        TreeDiffApplierAccumulator $accumulator,
        array &$reorderTargets,
    ): void {
        $keyName = (new $modelClass)->getKeyName();

        foreach ($diff->added as $added) {
            $attrs = $added->attributes;
            unset($attrs[$keyName]);

            $model = new $modelClass($attrs);

            if ($added->parentKey === null) {
                self::callInstance($model, 'makeRoot', []);
                $model->save();
            } else {
                $parentPk = $resolved[self::keyHash($added->parentKey)]
                    ?? $resolver($added->parentKey);
                if ($parentPk === null) {
                    throw new MissingParentException(sprintf(
                        'TreeDiff::apply(): cannot place added row %s; parent %s did not resolve.',
                        self::formatKey($added->key),
                        self::formatKey($added->parentKey),
                    ));
                }

                $parent = $modelClass::query()->whereKey($parentPk)->first();
                if (! $parent instanceof Model) {
                    throw new MissingParentException(sprintf(
                        'TreeDiff::apply(): parent row id=%s no longer exists for added %s.',
                        self::formatKey($parentPk),
                        self::formatKey($added->key),
                    ));
                }
                self::callInstance($model, 'appendToNode', [$parent]);
                $model->save();

                $childPk = $model->getKey();
                if (is_int($childPk) || is_string($childPk)) {
                    $reorderTargets[] = [
                        'pk' => $childPk,
                        'parentPk' => $parentPk,
                        'position' => $added->siblingPosition,
                    ];
                }
            }

            $key = $model->getKey();
            if (! is_int($key) && ! is_string($key)) {
                continue;
            }
            if (! is_int($added->key) && ! is_string($added->key)) {
                continue;
            }
            $accumulator->added[] = $added->key;
            $resolved[self::keyHash($added->key)] = $key;
        }
    }

    /**
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @param  array<string, int|string|null>  $resolved
     * @param  list<array{pk: int|string, parentPk: int|string|null, position: int}>  $reorderTargets
     *
     * @param-out list<array{pk: int|string, parentPk: int|string|null, position: int}>  $reorderTargets
     */
    private static function doMoves(
        TreeDiff $diff,
        string $modelClass,
        array $resolved,
        TreeDiffApplierAccumulator $accumulator,
        array &$reorderTargets,
    ): void {
        // Apply moves new-ancestor-first. A parent/child swap (B becomes the
        // parent of its former parent A) supplied in row order would move A
        // under B while B is still a descendant of A — a CyclicMoveException.
        // Moving B into its new home first breaks the cycle.
        foreach (self::orderMovesTopologically($diff->moved) as $move) {
            $rowPk = $resolved[self::keyHash($move->key)] ?? null;
            if ($rowPk === null) {
                throw new MissingParentException(sprintf(
                    'TreeDiff::apply(): cannot move row %s; identity did not resolve.',
                    self::formatKey($move->key),
                ));
            }

            $row = $modelClass::query()->whereKey($rowPk)->first();
            if (! $row instanceof Model) {
                throw new MissingParentException(sprintf(
                    'TreeDiff::apply(): row id=%s no longer exists for move %s.',
                    self::formatKey($rowPk),
                    self::formatKey($move->key),
                ));
            }

            if ($move->toParent === null) {
                self::callInstance($row, 'makeRoot', []);
                $row->save();
            } else {
                $parentPk = $resolved[self::keyHash($move->toParent)] ?? null;
                if ($parentPk === null) {
                    throw new MissingParentException(sprintf(
                        'TreeDiff::apply(): cannot move row %s; destination parent %s did not resolve.',
                        self::formatKey($move->key),
                        self::formatKey($move->toParent),
                    ));
                }
                $parent = $modelClass::query()->whereKey($parentPk)->first();
                if (! $parent instanceof Model) {
                    throw new MissingParentException(sprintf(
                        'TreeDiff::apply(): destination parent id=%s no longer exists for move %s.',
                        self::formatKey($parentPk),
                        self::formatKey($move->key),
                    ));
                }

                self::callInstance($row, 'appendToNode', [$parent]);
                $row->save();

                $reorderTargets[] = [
                    'pk' => $rowPk,
                    'parentPk' => $parentPk,
                    'position' => $move->toSiblingPosition,
                ];
            }

            if (is_int($move->key) || is_string($move->key)) {
                $accumulator->moved[] = $move->key;
            }
        }
    }

    /**
     * Orders moves so a node is moved only after the move that places its
     * new parent has run — parents-before-children in the *after* tree.
     * Breaks the parent/child-swap cycle that row order otherwise hits.
     * `assertCycleFree()` has already rejected genuine cycles in the after
     * tree, so the visiting guard here is purely defensive.
     *
     * @param  list<Moved>  $moves
     * @return list<Moved>
     */
    private static function orderMovesTopologically(array $moves): array
    {
        /** @var array<string, Moved> $byKey */
        $byKey = [];
        foreach ($moves as $move) {
            $byKey[self::keyHash($move->key)] = $move;
        }

        /** @var list<Moved> $ordered */
        $ordered = [];
        /** @var array<string, bool> $visited */
        $visited = [];
        /** @var array<string, bool> $visiting */
        $visiting = [];

        $visit = static function (Moved $move) use (&$visit, &$byKey, &$ordered, &$visited, &$visiting): void {
            $hash = self::keyHash($move->key);
            if (isset($visited[$hash]) || isset($visiting[$hash])) {
                return;
            }
            $visiting[$hash] = true;

            if ($move->toParent !== null) {
                $parentHash = self::keyHash($move->toParent);
                if (isset($byKey[$parentHash])) {
                    $visit($byKey[$parentHash]);
                }
            }

            unset($visiting[$hash]);
            $visited[$hash] = true;
            $ordered[] = $move;
        };

        foreach ($moves as $move) {
            $visit($move);
        }

        return $ordered;
    }

    /**
     * Single sibling-ordering pass run after all adds/moves/removes, when
     * every sibling is finally present. Each parent's changed children are
     * placed in ascending target rank; unchanged siblings keep their
     * relative order, so ascending insertion lands each changed node at its
     * recorded slot.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @param  list<array{pk: int|string, parentPk: int|string|null, position: int}>  $reorderTargets
     */
    private static function doReorders(string $modelClass, array $reorderTargets): void
    {
        /** @var array<string, list<array{pk: int|string, parentPk: int|string|null, position: int}>> $byParent */
        $byParent = [];
        foreach ($reorderTargets as $target) {
            $byParent[self::keyHash($target['parentPk'])][] = $target;
        }

        foreach ($byParent as $targets) {
            usort($targets, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

            foreach ($targets as $target) {
                $node = $modelClass::query()->whereKey($target['pk'])->first();
                if ($node instanceof Model) {
                    self::reorderToSiblingPosition($node, $target['position']);
                }
            }
        }
    }

    /**
     * Places a freshly placed (appended) child at its recorded
     * sibling slot. The diff records `$zeroBasedPosition` as the row's
     * 0-indexed rank within its parent's children in the `after` tree;
     * `moveToSiblingPosition()` is 1-indexed. Skipped for roots, which
     * have no sibling group to reorder within.
     *
     * Processing adds/moves in the diff's DFS order means every sibling
     * ranked below this one is already present, so the 1-indexed target
     * never exceeds the current child count.
     */
    private static function reorderToSiblingPosition(Model $node, int $zeroBasedPosition): void
    {
        self::callInstance($node, 'moveToSiblingPosition', [$zeroBasedPosition + 1]);
    }

    /**
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @param  array<string, int|string|null>  $resolved
     */
    private static function doModifies(
        TreeDiff $diff,
        string $modelClass,
        array $resolved,
        TreeDiffApplierAccumulator $accumulator,
    ): void {
        $keyName = (new $modelClass)->getKeyName();

        foreach ($diff->modified as $mod) {
            $pk = $resolved[self::keyHash($mod->key)] ?? null;
            if ($pk === null) {
                throw new MissingParentException(sprintf(
                    'TreeDiff::apply(): cannot modify row %s; identity did not resolve.',
                    self::formatKey($mod->key),
                ));
            }

            $row = $modelClass::query()->whereKey($pk)->first();
            if (! $row instanceof Model) {
                throw new MissingParentException(sprintf(
                    'TreeDiff::apply(): row id=%s no longer exists for modify %s.',
                    self::formatKey($pk),
                    self::formatKey($mod->key),
                ));
            }

            foreach ($mod->after as $col => $val) {
                if ($col === $keyName) {
                    continue;
                }
                $row->setAttribute($col, $val);
            }
            $row->save();

            if (is_int($mod->key) || is_string($mod->key)) {
                $accumulator->modified[] = $mod->key;
            }
        }
    }

    private static function dryRunResult(TreeDiff $diff): TreeDiffResult
    {
        $added = array_map(static fn (Added $a): mixed => $a->key, $diff->added);
        $removed = array_map(static fn (Removed $r): mixed => $r->key, $diff->removed);
        $moved = array_map(static fn (Moved $m): mixed => $m->key, $diff->moved);
        $modified = array_map(static fn (Modified $m): mixed => $m->key, $diff->modified);

        // Mirror the real execution order: add → move → remove → modify.
        $planned = [];
        foreach ($diff->added as $_) {
            $planned[] = ['statement' => 'insert+gap', 'rows' => 1];
        }
        foreach ($diff->moved as $_) {
            $planned[] = ['statement' => 'move', 'rows' => 1];
        }
        if ($diff->removed !== []) {
            $planned[] = ['statement' => 'delete', 'rows' => count($diff->removed)];
        }
        if ($diff->modified !== []) {
            $planned[] = ['statement' => 'update', 'rows' => count($diff->modified)];
        }

        /** @var list<int|string> $added */
        /** @var list<int|string> $removed */
        /** @var list<int|string> $moved */
        /** @var list<int|string> $modified */
        return new TreeDiffResult(
            added: $added,
            removed: $removed,
            moved: $moved,
            modified: $modified,
            dryRun: true,
            plannedStatements: $planned,
        );
    }

    private static function keyHash(mixed $key): string
    {
        if (is_int($key)) {
            return 'i:'.$key;
        }
        if (is_string($key)) {
            return 's:'.$key;
        }
        if ($key === null) {
            return 'n:';
        }
        if (is_array($key)) {
            return 'a:'.self::canonicalJson($key);
        }
        if (is_object($key)) {
            return 'o:'.spl_object_hash($key);
        }

        return 't:'.get_debug_type($key);
    }

    /**
     * Recursively ksort associative arrays so a `json_encode` output
     * is independent of key order — required for stable hashing of
     * resolver-produced identity values that happen to be array-shaped.
     *
     * @param  array<mixed, mixed>  $value
     */
    private static function canonicalJson(array $value): string
    {
        $normalised = self::canonicalArray($value);

        return json_encode($normalised, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private static function canonicalArray(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::canonicalArray($v);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private static function formatKey(mixed $key): string
    {
        if ($key === null) {
            return 'null';
        }
        if (is_int($key)) {
            return (string) $key;
        }
        if (is_string($key)) {
            return '"'.$key.'"';
        }

        return get_debug_type($key);
    }
}
