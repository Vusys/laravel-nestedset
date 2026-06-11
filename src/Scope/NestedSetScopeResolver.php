<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Scope;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Vusys\NestedSet\Attributes\NestedSetScope;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Events\Diagnostics\ScopeViolationDetected;
use Vusys\NestedSet\Events\EventDispatcher;
use Vusys\NestedSet\Exceptions\ScopeViolationException;

/**
 * Resolves the scope column-set and per-instance scope values for a node.
 *
 * Resolution order (attribute wins over method, both lose to nothing):
 *   1. #[NestedSetScope('post_id')] / #[NestedSetScope(['tenant_id','post_id'])]
 *   2. getScopeAttributes(): array<string>  defined on the model
 *   3. Empty list — model is single-tree.
 */
final class NestedSetScopeResolver
{
    /**
     * Returns the scope column names declared on the model class.
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<string>
     */
    public static function columns(string $class): array
    {
        // ReflectionClass::getAttributes() does not traverse parents, so a
        // subclass of a scoped model would otherwise resolve to no scope —
        // and its mutations would leak across trees with no
        // ScopeViolationException. Walk the parent chain; first match wins.
        $reflection = new ReflectionClass($class);
        do {
            $attributes = $reflection->getAttributes(NestedSetScope::class);
            if ($attributes !== []) {
                $instance = $attributes[0]->newInstance();
                $columns = $instance->columns;

                return is_array($columns) ? $columns : [$columns];
            }
            $reflection = $reflection->getParentClass();
        } while ($reflection !== false);

        if (method_exists($class, 'getScopeAttributes')) {
            // Cheap instance — Eloquent models are safe to construct without args
            // and getScopeAttributes() is expected to be deterministic / class-level.
            /** @var array<int, string> $columns */
            $columns = (new $class)->getScopeAttributes();

            return array_values($columns);
        }

        return [];
    }

    /**
     * Returns the [column => value] map for $node, given its declared scope.
     *
     * @return array<string, mixed>
     */
    public static function valuesFor(Model&HasNestedSet $node): array
    {
        $values = [];

        foreach (self::columns($node::class) as $column) {
            $values[$column] = $node->getAttribute($column);
        }

        return $values;
    }

    /**
     * Throws when $a and $b carry different scope values. Read paths can
     * mix scopes freely; this guard exists for write paths (insertAt,
     * appendToNode, etc.) where crossing trees would corrupt the lft/rgt
     * sequence.
     */
    public static function assertSameScope(Model&HasNestedSet $a, Model&HasNestedSet $b): void
    {
        if ($a::class !== $b::class) {
            $message = sprintf('Cannot relate %s to %s — different models.', $a::class, $b::class);
            EventDispatcher::dispatch(new ScopeViolationDetected(
                modelClass: $a::class,
                stage: 'mutation',
                message: $message,
            ));
            throw new ScopeViolationException($message);
        }

        $columns = self::columns($a::class);

        foreach ($columns as $column) {
            $aValue = $a->getAttribute($column);
            $bValue = $b->getAttribute($column);

            if (! self::scopeValuesEqual($aValue, $bValue)) {
                $message = sprintf(
                    'Cross-scope operation on %s: column %s differs (%s vs %s).',
                    $a::class,
                    $column,
                    self::format($aValue),
                    self::format($bValue),
                );
                EventDispatcher::dispatch(new ScopeViolationDetected(
                    modelClass: $a::class,
                    stage: 'mutation',
                    message: $message,
                ));
                throw new ScopeViolationException($message);
            }
        }
    }

    /**
     * Non-throwing predicate variant of {@see self::assertSameScope()}.
     * Returns false if the two nodes are different classes or if any
     * declared scope column differs. Use this in read-only predicates
     * (e.g. `isSiblingOf`) where a class/scope mismatch must answer
     * "not the same partition" rather than abort.
     */
    public static function sameScope(Model&HasNestedSet $a, Model&HasNestedSet $b): bool
    {
        if ($a::class !== $b::class) {
            return false;
        }

        foreach (self::columns($a::class) as $column) {
            if (! self::scopeValuesEqual($a->getAttribute($column), $b->getAttribute($column))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compares two scope-column values for "same tree" membership.
     *
     * Permissive on type: a model with `int 5` and another with
     * `string "5"` (e.g. raw `setRawAttributes` without a cast)
     * should still count as the same scope. Numeric strings normalise
     * to their numeric form; DateTime-like objects compare on their
     * resolved timestamp. Anything else falls back to loose `==` so
     * Carbon vs Carbon comparing the same instant returns true.
     *
     * The strict `!==` form (the previous behaviour) threw spuriously
     * for these mismatched-type representations even when the
     * underlying scope was identical.
     */
    private static function scopeValuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if ($a === null || $b === null) {
            return false;
        }

        if (is_numeric($a) && is_numeric($b)) {
            // Integer-like values (snowflake IDs, BIGINT keys) must compare
            // as canonical strings. Casting to float collapses distinct
            // 64-bit values above 2^53 to the same double, which would let
            // two different trees pass the same-scope check and corrupt both
            // on a cross-tree append. Genuine floats/decimals fall through.
            $aInt = self::canonicalIntegerString($a);
            $bInt = self::canonicalIntegerString($b);
            if ($aInt !== null && $bInt !== null) {
                return $aInt === $bInt;
            }

            return (float) $a === (float) $b;
        }

        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            return $a->getTimestamp() === $b->getTimestamp()
                && (int) $a->format('u') === (int) $b->format('u');
        }

        // Fall back to loose equality. Catches Stringable vs string
        // and the long tail of value-objects that implement __toString.
        return $a == $b;
    }

    /**
     * Returns the canonical integer string for an int or integer-valued
     * string (leading zeros and a redundant sign stripped), or null when
     * the value is not integer-like (genuine float, decimal string,
     * exponential notation). Never casts through float/int, so 64-bit
     * values beyond 2^53 stay exact.
     */
    private static function canonicalIntegerString(mixed $v): ?string
    {
        if (is_int($v)) {
            return (string) $v;
        }

        if (is_string($v) && preg_match('/^-?\d+$/', $v) === 1) {
            $negative = $v[0] === '-';
            $digits = ltrim($negative ? substr($v, 1) : $v, '0');
            if ($digits === '') {
                return '0';
            }

            return $negative ? '-'.$digits : $digits;
        }

        return null;
    }

    private static function format(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_scalar($v)) {
            return (string) $v;
        }

        return get_debug_type($v);
    }
}
