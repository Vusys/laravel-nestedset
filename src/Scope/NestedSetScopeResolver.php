<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Scope;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Vusys\NestedSet\Attributes\NestedSetScope;
use Vusys\NestedSet\Contracts\HasNestedSet;
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
        $attributes = (new ReflectionClass($class))->getAttributes(NestedSetScope::class);

        if ($attributes !== []) {
            $instance = $attributes[0]->newInstance();
            $columns = $instance->columns;

            return is_array($columns) ? array_values($columns) : [$columns];
        }

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
            throw new ScopeViolationException(
                sprintf('Cannot relate %s to %s — different models.', $a::class, $b::class),
            );
        }

        $columns = self::columns($a::class);

        foreach ($columns as $column) {
            if ($a->getAttribute($column) !== $b->getAttribute($column)) {
                throw new ScopeViolationException(sprintf(
                    'Cross-scope operation on %s: column %s differs (%s vs %s).',
                    $a::class,
                    $column,
                    self::format($a->getAttribute($column)),
                    self::format($b->getAttribute($column)),
                ));
            }
        }
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
