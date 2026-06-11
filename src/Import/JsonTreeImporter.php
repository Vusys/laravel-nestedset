<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\InvalidJsonTreeException;
use Vusys\NestedSet\Exceptions\JsonImportKeyCollisionException;
use Vusys\NestedSet\Exceptions\ScopeViolationException;
use Vusys\NestedSet\NodeCollection;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Translates a JSON tree payload into model rows under an optional
 * anchor or as new roots, reusing `bulkInsertTree` semantics for the
 * actual mutation (one gap, N inserts, one deferred aggregate pass).
 *
 * The importer is stateless; the entry point is the static
 * `fromJsonTree()` shim on `HasTreeExport` — this class is the engine.
 *
 * @phpstan-type NormalisedNode array{attributes: array<string, mixed>, children: list<mixed>}
 */
final class JsonTreeImporter
{
    /**
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @return NodeCollection<int, Model&HasNestedSet>
     */
    public static function import(
        string $modelClass,
        mixed $json,
        ?HasNestedSet $parent,
        JsonImportOptions $options,
    ): NodeCollection {
        $normalised = JsonTreeNormaliser::normalise($json, $options);

        if ($normalised === []) {
            /** @var NodeCollection<int, Model&HasNestedSet> $empty */
            $empty = new NodeCollection;

            return $empty;
        }

        $instance = new $modelClass;

        $aggregateColumns = [];
        foreach (AggregateRegistry::for($modelClass) as $def) {
            $aggregateColumns[] = $def->getColumn();
        }
        // `label` is the exporter's display-only field; including it in
        // the default ignore set lets round-trips work without callers
        // having to strip it manually.
        $ignore = array_merge($options->ignoreColumns, $aggregateColumns, ['label']);
        $ignoreSet = array_fill_keys($ignore, true);

        $keyName = $instance->getKeyName();
        $tableColumns = $instance->getConnection()->getSchemaBuilder()->getColumnListing($instance->getTable());
        $knownColumns = array_fill_keys($tableColumns, true);

        $scopeColumns = NestedSetScopeResolver::columns($modelClass);
        if (! $parent instanceof HasNestedSet && $scopeColumns !== []) {
            self::assertScopeColumnsPresent($normalised, $scopeColumns);
        }

        $tree = self::buildBulkInsertInput(
            normalised: $normalised,
            options: $options,
            keyName: $keyName,
            knownColumns: $knownColumns,
            ignoreSet: $ignoreSet,
            depth: 0,
            path: '',
        );

        try {
            $callable = [$modelClass, 'bulkInsertTree'];
            if (! is_callable($callable)) {
                throw new \LogicException(sprintf(
                    'fromJsonTree: %s must use NodeTrait (HasBulkInsert) to expose static bulkInsertTree().',
                    $modelClass,
                ));
            }
            /** @var list<Model&HasNestedSet> $saved */
            $saved = $callable($tree, $parent);
        } catch (QueryException $e) {
            if ($options->includeKeys && self::isUniqueViolation($e)) {
                throw new JsonImportKeyCollisionException(
                    offendingKey: self::extractCollisionKey($e),
                    message: 'fromJsonTree: primary-key collision while includeKeys=true. '.$e->getMessage(),
                    previous: $e,
                );
            }
            throw $e;
        }

        // bulkInsertTree() already returns every inserted row in DFS
        // pre-order — which is exactly the documented contract. The old
        // array_slice(0, topLevelCount) assumed top-level rows came first,
        // but pre-order interleaves a parent's descendants before its next
        // sibling ([A, A1, A2, B]), so it returned the wrong nodes.
        /** @var NodeCollection<int, Model&HasNestedSet> $collection */
        $collection = new NodeCollection($saved);

        return $collection;
    }

    /**
     * @param  list<NormalisedNode>  $normalised
     * @param  array<string, true>  $knownColumns
     * @param  array<string, true>  $ignoreSet
     * @return list<array<string, mixed>>
     */
    private static function buildBulkInsertInput(
        array $normalised,
        JsonImportOptions $options,
        string $keyName,
        array $knownColumns,
        array $ignoreSet,
        int $depth,
        string $path,
    ): array {
        $out = [];
        foreach ($normalised as $i => $node) {
            $here = $path === '' ? '['.$i.']' : $path.'.children['.$i.']';
            $attrs = $node['attributes'];

            if ($options->transform instanceof \Closure) {
                $transformed = ($options->transform)($attrs, $depth);
                $attrs = $transformed;
            }

            if ($options->strict) {
                foreach (array_keys($attrs) as $col) {
                    if (isset($ignoreSet[$col])) {
                        continue;
                    }
                    if ($col === $keyName) {
                        continue;
                    }
                    if (! isset($knownColumns[$col])) {
                        throw new InvalidJsonTreeException(sprintf(
                            'fromJsonTree: strict mode: unknown column "%s" at %s.',
                            $col,
                            $here,
                        ));
                    }
                }
            }

            foreach (array_keys($ignoreSet) as $col) {
                unset($attrs[$col]);
            }

            if (! $options->includeKeys) {
                unset($attrs[$keyName]);
            }

            $cleaned = [];
            foreach ($attrs as $col => $val) {
                if (isset($knownColumns[$col]) || $col === $keyName) {
                    $cleaned[$col] = $val;
                }
            }

            $children = [];
            /** @var list<mixed> $rawChildren */
            $rawChildren = $node['children'];
            if ($rawChildren !== []) {
                /** @var list<NormalisedNode> $rawChildren */
                $children = self::buildBulkInsertInput(
                    normalised: $rawChildren,
                    options: $options,
                    keyName: $keyName,
                    knownColumns: $knownColumns,
                    ignoreSet: $ignoreSet,
                    depth: $depth + 1,
                    path: $here,
                );
            }

            $cleaned['children'] = $children;
            $out[] = $cleaned;
        }

        return $out;
    }

    /**
     * @param  list<NormalisedNode>  $normalised
     * @param  list<string>  $scopeColumns
     */
    private static function assertScopeColumnsPresent(array $normalised, array $scopeColumns): void
    {
        foreach ($normalised as $i => $node) {
            foreach ($scopeColumns as $col) {
                if (! array_key_exists($col, $node['attributes'])) {
                    throw new ScopeViolationException(sprintf(
                        'fromJsonTree: top-level row [%d] is missing scope column "%s" — importing as roots requires the scope on every root.',
                        $i,
                        $col,
                    ));
                }
            }
        }
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->getCode();
        if ($sqlState === '23000' || $sqlState === '23505') {
            return true;
        }
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }

    /**
     * Best-effort extraction of the colliding primary-key value from the
     * underlying driver's unique-constraint error message. Handles string
     * / UUID keys, not just integers — the value is quoted on MySQL /
     * MariaDB / SQLite and parenthesised on PostgreSQL.
     */
    private static function extractCollisionKey(QueryException $e): int|string
    {
        $message = $e->getMessage();

        // PostgreSQL: "... Key (id)=(0190f-…-abc) already exists."
        if (preg_match('/=\(([^)]+)\)/', $message, $m) === 1) {
            return is_numeric($m[1]) ? (int) $m[1] : $m[1];
        }

        // MySQL / MariaDB / SQLite quote the offending value:
        // "Duplicate entry 'abc-123' for key …".
        if (preg_match("/'([^']+)'/", $message, $m) === 1) {
            return is_numeric($m[1]) ? (int) $m[1] : $m[1];
        }

        // Last resort: a bare integer somewhere in the message.
        if (preg_match('/\b(\d+)\b/', $message, $m) === 1) {
            return (int) $m[1];
        }

        return -1;
    }
}
