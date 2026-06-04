<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\ChangeFeed;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\Aggregates\NestedSetAggregateChanged;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\NodeBounds;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Captures a per-row, per-column snapshot of the user-facing aggregate
 * columns before a lifecycle hook, re-reads after the maintenance UPDATE
 * commits, and dispatches one {@see NestedSetAggregateChanged} per
 * (row, column) whose value actually moved.
 *
 * The snapshot itself is held on the mutating model — the trait owns
 * the lifecycle, this class owns the SQL and diff/normalisation logic.
 */
final class ChangeFeedRecorder
{
    /**
     * Captures the user-facing aggregate columns on every ancestor row
     * (and optionally self / subtree) before a maintenance pass.
     * Returns the snapshot for the caller to hold and feed back into
     * {@see self::dispatch()}; returns null when no listener is
     * registered, the model declares no user-facing aggregates, or the
     * model has no primary key.
     *
     * @param  Model&HasNestedSet  $node
     */
    public static function capture(
        Model $node,
        string $stage,
        NodeBounds $bounds,
        bool $includeSelf,
        bool $includeSubtree = false,
    ): ?ChangeFeedSnapshot {
        if (! EventDispatcher::hasListeners(NestedSetAggregateChanged::class)) {
            return null;
        }

        $columns = self::changeFeedColumns($node::class);
        if ($columns === []) {
            return null;
        }

        $scope = NestedSetScopeResolver::valuesFor($node);
        // Soft-delete restore needs to see trashed subtree rows in the
        // pre-snapshot because the cascade un-trashes them before the
        // restored hook reaches `applyAggregateOnRestore()`; without
        // disabling the filter the diff would miss the subtree entirely.
        $applySoftDeleteFilter = ! $includeSubtree;
        $snapshot = self::read(
            $node,
            $bounds,
            $scope,
            $columns,
            $includeSelf,
            $includeSubtree,
            $applySoftDeleteFilter,
        );

        return new ChangeFeedSnapshot(
            stage: $stage,
            bounds: $bounds,
            scope: $scope,
            columns: $columns,
            includeSelf: $includeSelf,
            includeSubtree: $includeSubtree,
            applySoftDeleteFilter: $applySoftDeleteFilter,
            values: $snapshot['values'],
            chain: $snapshot['chain'],
        );
    }

    /**
     * Re-reads the same rows the pre-snapshot captured, diffs them
     * column-by-column, and dispatches one
     * {@see NestedSetAggregateChanged} per (row, column) whose value
     * actually moved. No-op when {@see self::capture()} returned null.
     *
     * @param  Model&HasNestedSet  $node
     */
    public static function dispatch(Model $node, ?ChangeFeedSnapshot $pre): void
    {
        if (! $pre instanceof ChangeFeedSnapshot) {
            return;
        }

        $post = self::read(
            $node,
            $pre->bounds,
            $pre->scope,
            $pre->columns,
            $pre->includeSelf,
            $pre->includeSubtree,
            $pre->applySoftDeleteFilter,
        );

        // Union pre / post chain so rows that appeared in only one
        // (e.g. soft-delete-restore subtree row appearing in post)
        // still emit "value changed from null" events.
        $chain = $post['chain'];
        foreach ($pre->chain as $id) {
            if (! in_array($id, $chain, true)) {
                $chain[] = $id;
            }
        }

        if ($chain === []) {
            return;
        }

        foreach ($chain as $id) {
            $beforeValues = $pre->values[$id] ?? [];
            $afterValues = $post['values'][$id] ?? [];

            foreach ($pre->columns as $column) {
                $old = $beforeValues[$column] ?? null;
                $new = $afterValues[$column] ?? null;

                if (self::valuesEqual($old, $new)) {
                    continue;
                }

                EventDispatcher::dispatch(new NestedSetAggregateChanged(
                    modelClass: $node::class,
                    nodeId: $id,
                    column: $column,
                    oldValue: $old,
                    newValue: $new,
                    ancestorChain: $chain,
                    stage: $pre->stage,
                ));
            }
        }
    }

    /**
     * @param  class-string<Model&HasNestedSet>  $modelClass
     * @return list<string>
     */
    private static function changeFeedColumns(string $modelClass): array
    {
        $columns = [];
        foreach (AggregateRegistry::for($modelClass) as $definition) {
            if ($definition->isInternal()) {
                continue;
            }
            $columns[] = $definition->getColumn();
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param  Model&HasNestedSet  $node
     * @param  array<string, mixed>  $scope
     * @param  list<string>  $columns
     * @return array{
     *     chain: list<int|string>,
     *     values: array<int|string, array<string, int|float|bool|string|null>>,
     * }
     */
    private static function read(
        Model $node,
        NodeBounds $bounds,
        array $scope,
        array $columns,
        bool $includeSelf,
        bool $includeSubtree,
        bool $applySoftDeleteFilter,
    ): array {
        $idCol = $node->getKeyName();
        $lftCol = $node->getLftName();
        $rgtCol = $node->getRgtName();

        $query = $node->getConnection()->table($node->getTable());

        // WHERE matches the maintenance UPDATE's ancestor-targeting
        // shape: ancestors + optionally self, optionally plus strict
        // descendants for restore-style passes. Same row set ⇒ the
        // diff captures every value the UPDATE could touch.
        if ($includeSubtree) {
            $query->where(function ($q) use ($lftCol, $rgtCol, $bounds, $includeSelf): void {
                $q->where(function ($q2) use ($lftCol, $rgtCol, $bounds, $includeSelf): void {
                    $q2->where($lftCol, '<=', $bounds->lft)
                        ->where($rgtCol, '>=', $bounds->rgt);
                    if (! $includeSelf) {
                        $q2->where(function ($q3) use ($lftCol, $rgtCol, $bounds): void {
                            $q3->where($lftCol, '!=', $bounds->lft)
                                ->orWhere($rgtCol, '!=', $bounds->rgt);
                        });
                    }
                })->orWhere(function ($q2) use ($lftCol, $rgtCol, $bounds): void {
                    $q2->where($lftCol, '>', $bounds->lft)
                        ->where($rgtCol, '<', $bounds->rgt);
                });
            });
        } else {
            $query->where($lftCol, '<=', $bounds->lft)
                ->where($rgtCol, '>=', $bounds->rgt);

            if (! $includeSelf) {
                $query->where(function ($q) use ($lftCol, $rgtCol, $bounds): void {
                    $q->where($lftCol, '!=', $bounds->lft)
                        ->orWhere($rgtCol, '!=', $bounds->rgt);
                });
            }
        }

        foreach ($scope as $column => $value) {
            $query->where($column, '=', $value);
        }

        if ($applySoftDeleteFilter) {
            $softDeletedColumn = self::softDeleteColumn($node);
            if ($softDeletedColumn !== null) {
                $query->whereNull($softDeletedColumn);
            }
        }

        $selectCols = array_values(array_unique(array_merge([$idCol, $lftCol], $columns)));
        $rows = $query->orderBy($lftCol, 'desc')->get($selectCols);

        $chain = [];
        $values = [];
        foreach ($rows as $row) {
            $rowArray = (array) $row;
            $id = $rowArray[$idCol] ?? null;
            if (! is_int($id) && ! is_string($id)) {
                continue;
            }

            $chain[] = $id;
            $colValues = [];
            foreach ($columns as $col) {
                $raw = $rowArray[$col] ?? null;
                $colValues[$col] = self::normalise($raw);
            }
            $values[$id] = $colValues;
        }

        return ['chain' => $chain, 'values' => $values];
    }

    /**
     * Loose equality for change-feed diffing. NULL is distinct from
     * any concrete value (we want to emit "0 → null" and "null → 0"
     * as real changes). For numeric values we normalise driver-side
     * type variation (PG DECIMAL strings, MySQL ints) — integer-like
     * pairs are compared as canonical integer strings to keep 64-bit
     * BIGINT precision (a float cast loses bits past 2^53, which
     * would silently swallow change events on large SUM/COUNT
     * aggregates), decimal-string pairs are compared as canonical
     * decimal strings for the same reason at the fractional end
     * (DECIMAL(38, 10) sums differing only in the trailing digits
     * collapse to the same float), and anything else falls back to
     * a float comparison.
     */
    private static function valuesEqual(
        int|float|bool|string|null $a,
        int|float|bool|string|null $b,
    ): bool {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        if (is_bool($a) || is_bool($b)) {
            return $a === $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            if (self::isIntegerLike($a) && self::isIntegerLike($b)) {
                return self::normaliseIntegerString($a) === self::normaliseIntegerString($b);
            }

            if (self::isDecimalString($a) && self::isDecimalString($b)) {
                return self::normaliseDecimalString($a) === self::normaliseDecimalString($b);
            }

            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    /**
     * True when the raw value represents an integer without a
     * fractional part — a PHP `int`, or a string of the form
     * `-?\d+` (the wire-format DB drivers return BIGINTs as).
     */
    private static function isIntegerLike(int|float|bool|string|null $value): bool
    {
        if (is_int($value)) {
            return true;
        }
        if (is_string($value) && $value !== '') {
            return (bool) preg_match('/^[+-]?\d+$/', $value);
        }

        return false;
    }

    /**
     * Canonicalise an integer-like numeric to a comparable string —
     * strip an optional `+`/`-` sign prefix, drop leading zeros, and
     * collapse `-0` to `0`. Operates on the raw string rather than
     * casting through PHP `int` so values that exceed `PHP_INT_MAX`
     * (e.g. UNSIGNED BIGINT on MySQL) still compare correctly.
     */
    private static function normaliseIntegerString(int|float|bool|string|null $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        $raw = (string) $value;
        $negative = false;
        if ($raw !== '' && ($raw[0] === '+' || $raw[0] === '-')) {
            $negative = $raw[0] === '-';
            $raw = substr($raw, 1);
        }
        $raw = ltrim($raw, '0');
        if ($raw === '') {
            return '0';
        }

        return $negative ? '-'.$raw : $raw;
    }

    /**
     * True when the value is a plain decimal-string of the form
     * `-?(\d+\.\d*|\.\d+|\d+)` — what DB drivers return for DECIMAL
     * columns. Excludes scientific notation (`1.0e10`), which can't
     * be compared digit-for-digit and falls through to the float
     * branch in {@see valuesEqual()}.
     */
    private static function isDecimalString(int|float|bool|string|null $value): bool
    {
        return is_string($value)
            && (bool) preg_match('/^[+-]?(\d+\.\d*|\.\d+|\d+)$/', $value);
    }

    /**
     * Canonicalise a decimal-string numeric to a comparable string —
     * strip sign, drop leading zeros in the integer part, drop
     * trailing zeros in the fractional part, drop the decimal point
     * if the fractional part is empty, and collapse signed zero to
     * `0`. Operates on the raw string so precision past `PHP_FLOAT`
     * (~15 significant digits) is preserved for the diff — a
     * DECIMAL(38, 10) SUM differing by `0.0000000001` still compares
     * as unequal.
     */
    private static function normaliseDecimalString(int|float|bool|string|null $value): string
    {
        $raw = (string) $value;
        $negative = false;
        if ($raw !== '' && ($raw[0] === '+' || $raw[0] === '-')) {
            $negative = $raw[0] === '-';
            $raw = substr($raw, 1);
        }

        if (str_contains($raw, '.')) {
            [$int, $frac] = explode('.', $raw, 2);
            $frac = rtrim($frac, '0');
        } else {
            $int = $raw;
            $frac = '';
        }

        $int = ltrim($int, '0');
        if ($int === '') {
            $int = '0';
        }

        $canonical = $frac === '' ? $int : $int.'.'.$frac;
        if ($canonical === '0') {
            return '0';
        }

        return $negative ? '-'.$canonical : $canonical;
    }

    /**
     * Coerces a raw column value into the change-feed event payload
     * type union. Stdclass / objects are flattened to string;
     * everything else passes through. Keeps the event constructor's
     * type contract honest without forcing a numeric cast that would
     * lose driver-native types (e.g. PG returns DECIMAL as string).
     */
    private static function normalise(mixed $value): int|float|bool|string|null
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value) || is_bool($value) || is_string($value)) {
            return $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param  Model&HasNestedSet  $node
     */
    private static function softDeleteColumn(Model $node): ?string
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($node::class), true)) {
            return null;
        }

        $column = (new \ReflectionMethod($node, 'getDeletedAtColumn'))->invoke($node);

        return is_string($column) ? $column : null;
    }
}
