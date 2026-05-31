<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Diff;

use Closure;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Diff\TreeChange\Added;
use Vusys\NestedSet\Diff\TreeChange\Modified;
use Vusys\NestedSet\Diff\TreeChange\Moved;
use Vusys\NestedSet\Diff\TreeChange\Removed;
use Vusys\NestedSet\Exceptions\DanglingParentException;
use Vusys\NestedSet\Exceptions\DuplicateNodeIdentityException;
use Vusys\NestedSet\Exceptions\InvalidJsonTreeException;

/**
 * Pure value-object diff over two tree snapshots. Given a `before`
 * and an `after` — both as Eloquent collections, plain arrays, or
 * decoded JSON — produce a typed list of {@see Added}, {@see Removed},
 * {@see Moved}, {@see Modified} changes that takes `before` to
 * `after`.
 *
 * Building the diff hits no database; {@see self::apply()} is the
 * opt-in mutation step.
 *
 * @phpstan-type RowAttrs array<string, mixed>
 * @phpstan-type NormalisedRow array{parent: int|string|null, attrs: RowAttrs, pos: int, parentId: int|string|null, id: int|string|null}
 */
final readonly class TreeDiff implements JsonSerializable
{
    /**
     * Structural columns that are derived from the tree shape and
     * never participate in `Modified` detection. The diff regenerates
     * them implicitly via `apply()`'s parent_id rewrites and
     * `fixTree()`-equivalent pass. `id` is included because the
     * primary key is database-generated on insert; comparing it
     * across environments would surface as spurious Modified rows
     * when the JSON came from a CMS that re-issued keys.
     */
    public const array STRUCTURAL_COLUMNS = ['lft', 'rgt', 'depth', 'parent_id', 'children', 'id'];

    /**
     * @param  list<Added>  $added
     * @param  list<Removed>  $removed
     * @param  list<Moved>  $moved
     * @param  list<Modified>  $modified
     * @param  list<string>  $ignoreColumns
     */
    public function __construct(
        public array $added,
        public array $removed,
        public array $moved,
        public array $modified,
        public string|Closure $on,
        public array $ignoreColumns,
    ) {}

    /**
     * Build a diff that takes `$before` to `$after`.
     *
     * Accepted input shapes (both sides accept all of them):
     *   - iterable<Model&HasNestedSet>
     *   - iterable<array<string, mixed>> in flat form (each row has parent_id)
     *   - list<array<string, mixed>> in nested form (each row carries `children`)
     *   - decoded JSON in either form
     *
     * @param  iterable<int|string, mixed>|array<int, mixed>  $before
     * @param  iterable<int|string, mixed>|array<int, mixed>  $after
     * @param  list<string>  $ignoreColumns
     */
    public static function between(
        iterable $before,
        iterable $after,
        string|Closure $on = 'id',
        array $ignoreColumns = [],
    ): self {
        $beforeRows = self::normalise($before, $on, 'before');
        $afterRows = self::normalise($after, $on, 'after');

        $beforeKeys = array_keys($beforeRows);
        $afterKeys = array_keys($afterRows);

        $beforeKeySet = array_fill_keys($beforeKeys, true);
        $afterKeySet = array_fill_keys($afterKeys, true);

        $structural = array_fill_keys(self::STRUCTURAL_COLUMNS, true);
        $ignoreSet = array_fill_keys($ignoreColumns, true);

        $removed = [];
        $moved = [];
        $modified = [];

        foreach ($beforeKeys as $key) {
            if (! isset($afterKeySet[$key])) {
                $removed[] = new Removed(key: $key);
            }
        }

        foreach ($afterKeys as $key) {
            if (! isset($beforeKeySet[$key])) {
                continue;
            }

            $beforeRow = $beforeRows[$key];
            $afterRow = $afterRows[$key];

            if (
                ! self::scalarEquals($beforeRow['parent'], $afterRow['parent'])
                || $beforeRow['pos'] !== $afterRow['pos']
            ) {
                $moved[] = new Moved(
                    key: $key,
                    fromParent: $beforeRow['parent'],
                    toParent: $afterRow['parent'],
                    toSiblingPosition: $afterRow['pos'],
                );
            }

            $diff = self::columnDiff($beforeRow['attrs'], $afterRow['attrs'], $structural, $ignoreSet);
            if ($diff !== null) {
                $modified[] = new Modified(
                    key: $key,
                    before: $diff['before'],
                    after: $diff['after'],
                );
            }
        }

        $addedSorted = self::topologicalSortAdded($afterRows, $beforeKeySet, $afterKeySet, $structural, $ignoreSet);

        return new self(
            added: $addedSorted,
            removed: $removed,
            moved: $moved,
            modified: $modified,
            on: $on,
            ignoreColumns: $ignoreColumns,
        );
    }

    public function isEmpty(): bool
    {
        return $this->added === []
            && $this->removed === []
            && $this->moved === []
            && $this->modified === [];
    }

    /**
     * @return array{added: int, removed: int, moved: int, modified: int}
     */
    public function summary(): array
    {
        return [
            'added' => count($this->added),
            'removed' => count($this->removed),
            'moved' => count($this->moved),
            'modified' => count($this->modified),
        ];
    }

    /**
     * Swap the diff to undo direction: `added` ↔ `removed`,
     * `Modified::before` ↔ `Modified::after`, `Moved::fromParent` ↔
     * `Moved::toParent`. The sibling position on a swapped Moved is a
     * lossy approximation — the original `before` position isn't
     * recorded on `Moved`, so callers using `invert()` for round-trip
     * must accept that pure reorder undo restores parent but not
     * order. See the docs gotchas section.
     */
    public function invert(): self
    {
        $newAdded = [];
        foreach ($this->removed as $r) {
            $newAdded[] = new Added(
                key: $r->key,
                parentKey: null,
                attributes: [],
                siblingPosition: 0,
            );
        }

        $newRemoved = [];
        foreach ($this->added as $a) {
            $newRemoved[] = new Removed(key: $a->key);
        }

        $newMoved = [];
        foreach ($this->moved as $m) {
            $newMoved[] = new Moved(
                key: $m->key,
                fromParent: $m->toParent,
                toParent: $m->fromParent,
                toSiblingPosition: $m->toSiblingPosition,
            );
        }

        $newModified = [];
        foreach ($this->modified as $mod) {
            $newModified[] = new Modified(
                key: $mod->key,
                before: $mod->after,
                after: $mod->before,
            );
        }

        return new self(
            added: $newAdded,
            removed: $newRemoved,
            moved: $newMoved,
            modified: $newModified,
            on: $this->on,
            ignoreColumns: $this->ignoreColumns,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'added' => array_map(static fn (Added $a): array => $a->jsonSerialize(), $this->added),
            'removed' => array_map(static fn (Removed $r): array => $r->jsonSerialize(), $this->removed),
            'moved' => array_map(static fn (Moved $m): array => $m->jsonSerialize(), $this->moved),
            'modified' => array_map(static fn (Modified $m): array => $m->jsonSerialize(), $this->modified),
            'summary' => $this->summary(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Apply the diff to the live database under `$modelClass`.
     *
     * The diff is structural data — `$modelClass` is the model whose
     * table receives the mutations, supplied at apply-time so the
     * same diff can be applied across environments. See
     * {@see TreeDiffApplier::apply()} for ordering and transaction
     * semantics.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @param  Closure(mixed): (int|string|null)|null  $resolver  Custom identity → primary-key resolver. Defaults to a single `whereIn` lookup.
     */
    public function apply(
        string $modelClass,
        ?Closure $resolver = null,
        bool $dryRun = false,
    ): TreeDiffResult {
        return TreeDiffApplier::apply($this, $modelClass, $resolver, $dryRun);
    }

    /**
     * Default ignore-column set for `$modelClass`: every aggregate
     * column declared via `#[NestedSetAggregate]` /
     * `#[NestedSetAggregateListener]`, plus the AVG companions auto-added
     * by the registry. Used by the applier to compose `$ignoreColumns`
     * without callers having to know which columns are derived.
     *
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @return list<string>
     */
    public static function aggregateColumnsFor(string $modelClass): array
    {
        $cols = [];
        foreach (AggregateRegistry::for($modelClass) as $def) {
            $cols[] = $def->getColumn();
        }

        return $cols;
    }

    /**
     * Normalise either input side to a map of identity → row data.
     *
     * @param  iterable<mixed>  $rows
     * @return array<int|string, NormalisedRow>
     *
     * @throws DuplicateNodeIdentityException
     * @throws DanglingParentException
     * @throws InvalidJsonTreeException
     */
    private static function normalise(
        iterable $rows,
        string|Closure $on,
        string $side,
    ): array {
        $materialised = self::materialise($rows);

        $shape = self::detectShape($materialised);

        if ($shape === 'nested') {
            return self::normaliseNested($materialised, $on, $side);
        }

        return self::normaliseFlat($materialised, $on, $side);
    }

    /**
     * @param  iterable<mixed>  $rows
     * @return list<mixed>
     */
    private static function materialise(iterable $rows): array
    {
        if (is_array($rows)) {
            return array_values($rows);
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Returns 'nested' if any top-level row carries a `children` key,
     * 'flat' otherwise. Mixed shapes throw — the caller picked one
     * format and the diff refuses to silently guess.
     *
     * @param  list<mixed>  $rows
     */
    private static function detectShape(array $rows): string
    {
        if ($rows === []) {
            return 'flat';
        }

        $hasNested = false;
        $hasFlat = false;
        foreach ($rows as $row) {
            $rowArr = self::rowToArray($row);
            if (array_key_exists('children', $rowArr)) {
                $hasNested = true;
            } else {
                $hasFlat = true;
            }
        }

        if ($hasNested && $hasFlat) {
            throw new InvalidJsonTreeException(
                'Tree diff input mixes nested (has "children") and flat (no "children") rows; pick one shape.',
            );
        }

        return $hasNested ? 'nested' : 'flat';
    }

    /**
     * @param  list<mixed>  $rows
     * @return array<int|string, NormalisedRow>
     */
    private static function normaliseNested(array $rows, string|Closure $on, string $side): array
    {
        $out = [];
        self::walkNested($rows, $on, $side, null, $out);
        self::assertParentsResolve($out, $side);

        return $out;
    }

    /**
     * @param  list<mixed>  $rows
     * @param  array<int|string, NormalisedRow>  $out
     */
    private static function walkNested(
        array $rows,
        string|Closure $on,
        string $side,
        int|string|null $parentIdentity,
        array &$out,
    ): void {
        $position = 0;
        foreach ($rows as $row) {
            $rowArr = self::rowToArray($row);
            $identity = self::resolveIdentity($row, $rowArr, $on, $side);
            $id = self::resolvePrimaryKey($rowArr);

            $children = [];
            if (array_key_exists('children', $rowArr)) {
                $rawChildren = $rowArr['children'];
                if (! is_array($rawChildren)) {
                    throw new InvalidJsonTreeException(sprintf(
                        'Tree diff input (%s): "children" must be an array; got %s on row %s.',
                        $side,
                        get_debug_type($rawChildren),
                        self::formatKey($identity),
                    ));
                }
                /** @var list<mixed> $children */
                $children = array_values($rawChildren);
            }

            $attrs = $rowArr;
            unset($attrs['children']);

            if (isset($out[$identity])) {
                throw new DuplicateNodeIdentityException(sprintf(
                    'Tree diff input (%s): identity %s appears more than once.',
                    $side,
                    self::formatKey($identity),
                ));
            }

            $out[$identity] = [
                'parent' => $parentIdentity,
                'attrs' => $attrs,
                'pos' => $position++,
                'parentId' => null,
                'id' => $id,
            ];

            if ($children !== []) {
                self::walkNested($children, $on, $side, $identity, $out);
            }
        }
    }

    /**
     * @param  list<mixed>  $rows
     * @return array<int|string, NormalisedRow>
     */
    private static function normaliseFlat(array $rows, string|Closure $on, string $side): array
    {
        $byIdentity = [];
        $idToIdentity = [];

        foreach ($rows as $row) {
            $rowArr = self::rowToArray($row);
            $identity = self::resolveIdentity($row, $rowArr, $on, $side);
            $id = self::resolvePrimaryKey($rowArr);
            $parentId = array_key_exists('parent_id', $rowArr) ? self::scalarOrNull($rowArr['parent_id']) : null;

            if (isset($byIdentity[$identity])) {
                throw new DuplicateNodeIdentityException(sprintf(
                    'Tree diff input (%s): identity %s appears more than once.',
                    $side,
                    self::formatKey($identity),
                ));
            }

            $byIdentity[$identity] = [
                'parent' => null,
                'attrs' => $rowArr,
                'pos' => 0,
                'parentId' => $parentId,
                'id' => $id,
            ];

            if ($id !== null) {
                $idToIdentity[$id] = $identity;
            }
        }

        $positionByParent = [];
        foreach ($byIdentity as $identity => $row) {
            $parentIdentity = null;
            if ($row['parentId'] !== null) {
                $parentIdentity = $idToIdentity[$row['parentId']] ?? null;
                if ($parentIdentity === null) {
                    throw new DanglingParentException(sprintf(
                        'Tree diff input (%s): row %s references parent_id=%s which is not present in the snapshot.',
                        $side,
                        self::formatKey($identity),
                        self::formatKey($row['parentId']),
                    ));
                }
            }

            $parentKey = $parentIdentity === null ? '__root__' : '__id__'.$parentIdentity;
            $position = $positionByParent[$parentKey] ?? 0;
            $positionByParent[$parentKey] = $position + 1;

            $row['parent'] = $parentIdentity;
            $row['pos'] = $position;
            $byIdentity[$identity] = $row;
        }

        return $byIdentity;
    }

    /**
     * @param  array<int|string, NormalisedRow>  $rows
     */
    private static function assertParentsResolve(array $rows, string $side): void
    {
        foreach ($rows as $identity => $row) {
            if ($row['parent'] !== null && ! isset($rows[$row['parent']])) {
                throw new DanglingParentException(sprintf(
                    'Tree diff input (%s): row %s references parent %s which is not present in the snapshot.',
                    $side,
                    self::formatKey($identity),
                    self::formatKey($row['parent']),
                ));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function rowToArray(mixed $row): array
    {
        if ($row instanceof Model) {
            return $row->attributesToArray();
        }

        if (is_array($row)) {
            /** @var array<string, mixed> $row */
            return $row;
        }

        throw new InvalidJsonTreeException(sprintf(
            'Tree diff input: every row must be a Model or array; got %s.',
            get_debug_type($row),
        ));
    }

    /**
     * @param  array<string, mixed>  $rowArr
     */
    private static function resolveIdentity(mixed $row, array $rowArr, string|Closure $on, string $side): int|string
    {
        if ($on instanceof Closure) {
            $val = $on($row instanceof Model ? $row : $rowArr);
        } else {
            if (! array_key_exists($on, $rowArr)) {
                throw new InvalidJsonTreeException(sprintf(
                    'Tree diff input (%s): identity column "%s" missing on row %s.',
                    $side,
                    $on,
                    json_encode($rowArr) ?: '<unprintable>',
                ));
            }
            $val = $rowArr[$on];
        }

        if (! is_int($val) && ! is_string($val)) {
            throw new InvalidJsonTreeException(sprintf(
                'Tree diff input (%s): identity must resolve to int|string; got %s.',
                $side,
                get_debug_type($val),
            ));
        }

        return $val;
    }

    /**
     * @param  array<string, mixed>  $rowArr
     */
    private static function resolvePrimaryKey(array $rowArr): int|string|null
    {
        if (! array_key_exists('id', $rowArr)) {
            return null;
        }

        return self::scalarOrNull($rowArr['id']);
    }

    private static function scalarOrNull(mixed $val): int|string|null
    {
        if ($val === null) {
            return null;
        }

        if (is_int($val) || is_string($val)) {
            return $val;
        }

        if (is_float($val)) {
            return (string) $val;
        }

        return null;
    }

    /**
     * Column-by-column equality with type-aware comparison for nested
     * arrays (JSON columns) and `DateTimeInterface` values, matching
     * Eloquent's dirty-tracking semantics.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, true>  $structural
     * @param  array<string, true>  $ignore
     * @return array{before: array<string, mixed>, after: array<string, mixed>}|null
     */
    private static function columnDiff(array $before, array $after, array $structural, array $ignore): ?array
    {
        $beforeOut = [];
        $afterOut = [];

        foreach ($after as $col => $afterVal) {
            if (isset($structural[$col])) {
                continue;
            }
            if (isset($ignore[$col])) {
                continue;
            }
            if (! array_key_exists($col, $before)) {
                $beforeOut[$col] = null;
                $afterOut[$col] = $afterVal;

                continue;
            }

            $beforeVal = $before[$col];
            if (! self::valueEquals($beforeVal, $afterVal)) {
                $beforeOut[$col] = $beforeVal;
                $afterOut[$col] = $afterVal;
            }
        }

        if ($beforeOut === []) {
            return null;
        }

        return ['before' => $beforeOut, 'after' => $afterOut];
    }

    private static function valueEquals(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            return $a->getTimestamp() === $b->getTimestamp()
                && (int) $a->format('u') === (int) $b->format('u');
        }

        if (is_array($a) && is_array($b)) {
            $jsonA = json_encode(self::canonicalise($a));
            $jsonB = json_encode(self::canonicalise($b));

            return $jsonA !== false && $jsonA === $jsonB;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return false;
    }

    /**
     * Recursively ksort an array so `json_encode` produces a canonical
     * key order. Required for comparing JSON-column values regardless
     * of how they were serialised on either side.
     *
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private static function canonicalise(array $value): array
    {
        $isList = array_is_list($value);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::canonicalise($v);
            }
        }

        if (! $isList) {
            ksort($value);
        }

        return $value;
    }

    private static function scalarEquals(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return false;
    }

    /**
     * Topologically sort the added set so parents appear before
     * children — `apply()` walks the list once and references each
     * parent by primary key as it goes.
     *
     * @param  array<int|string, NormalisedRow>  $afterRows
     * @param  array<int|string, true>  $beforeKeySet
     * @param  array<int|string, true>  $afterKeySet
     * @param  array<string, true>  $structural
     * @param  array<string, true>  $ignore
     * @return list<Added>
     */
    private static function topologicalSortAdded(
        array $afterRows,
        array $beforeKeySet,
        array $afterKeySet,
        array $structural,
        array $ignore,
    ): array {
        $addedKeySet = [];
        foreach ($afterRows as $key => $row) {
            if (! isset($beforeKeySet[$key])) {
                $addedKeySet[$key] = true;
            }
        }

        foreach ($afterRows as $key => $row) {
            if (! isset($addedKeySet[$key])) {
                continue;
            }

            $parent = $row['parent'];
            if ($parent === null) {
                continue;
            }

            $parentPresent = isset($beforeKeySet[$parent]) || isset($afterKeySet[$parent]);
            if (! $parentPresent) {
                throw new DanglingParentException(sprintf(
                    'Tree diff: added row %s references parent %s which is absent from both snapshots.',
                    self::formatKey($key),
                    self::formatKey($parent),
                ));
            }
        }

        $byKey = [];
        foreach ($afterRows as $key => $row) {
            if (isset($addedKeySet[$key])) {
                $byKey[$key] = $row;
            }
        }

        $ordered = [];
        $emitted = [];

        $emit = static function (int|string $key) use (&$emit, &$byKey, &$emitted, &$ordered, &$addedKeySet, $structural, $ignore): void {
            if (isset($emitted[$key])) {
                return;
            }
            $row = $byKey[$key];
            $parent = $row['parent'];
            if ($parent !== null && isset($addedKeySet[$parent]) && ! isset($emitted[$parent])) {
                $emit($parent);
            }
            $emitted[$key] = true;

            $attrs = $row['attrs'];
            foreach (array_keys($structural) as $col) {
                unset($attrs[$col]);
            }
            foreach (array_keys($ignore) as $col) {
                unset($attrs[$col]);
            }

            $ordered[] = new Added(
                key: $key,
                parentKey: $parent,
                attributes: $attrs,
                siblingPosition: $row['pos'],
            );
        };

        foreach (array_keys($byKey) as $key) {
            $emit($key);
        }

        return $ordered;
    }

    private static function formatKey(int|string|null $key): string
    {
        if ($key === null) {
            return 'null';
        }
        if (is_int($key)) {
            return (string) $key;
        }

        return '"'.$key.'"';
    }
}
