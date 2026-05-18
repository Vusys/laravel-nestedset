<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\Scope\NestedSetScopeResolver;

/**
 * Resolves the full set of aggregate definitions declared for a model
 * class, merging attribute declarations with any method override.
 *
 * Resolution order (mirrors {@see NestedSetScopeResolver}):
 *   1. `#[NestedSetAggregate(...)]` attribute instances on the class.
 *   2. `nestedSetAggregates(): array` method override on the model.
 *   3. `#[NestedSetAggregateListener(...)]` attribute instances on the class.
 *   4. `nestedSetListenerAggregates(): array` method override on the model.
 *   5. Auto-promotion: each AVG declaration without explicit companion
 *      SUM and COUNT for the same source gets two internal companion
 *      definitions auto-added with predictable column names.
 *
 * Validation runs after merging and throws
 * {@see AggregateConfigurationException} on any inconsistency (duplicate
 * column targets, AVG declared with no source, etc.).
 */
final class AggregateRegistry
{
    /**
     * Internal companion column suffix for the SUM half of an AVG.
     */
    public const string AVG_SUM_SUFFIX = '__sum';

    /**
     * Internal companion column suffix for the COUNT half of an AVG.
     */
    public const string AVG_COUNT_SUFFIX = '__count';

    /**
     * Cached resolved definitions, keyed by class name. Definitions for
     * a class never change after first resolution; caching avoids
     * repeated reflection on hot paths.
     *
     * @var array<class-string, list<AggregateDefinitionContract>>
     */
    private static array $cache = [];

    /**
     * Returns the merged, validated, auto-promoted set of definitions
     * for $class. Empty list means the model declares no aggregates.
     *
     * The list may contain both {@see AggregateDefinition} instances
     * (SQL-function aggregates) and — once registered — instances of
     * other {@see AggregateDefinitionContract} implementations. Callers
     * that need concrete properties must narrow with `instanceof`.
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<AggregateDefinitionContract>
     */
    public static function for(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        /** @var list<AggregateDefinitionContract> $definitions */
        $definitions = array_merge(
            self::fromAttributes($class),
            self::fromMethodOverride($class),
            self::fromListenerAttributes($class),
            self::fromListenerMethodOverride($class),
        );

        $definitions = self::autoPromoteAvgCompanions($definitions);

        self::assertNoDuplicateColumns($definitions, $class);
        self::assertNoAggregateColumnsInFillable($definitions, $class);

        return self::$cache[$class] = $definitions;
    }

    /**
     * Clears the resolution cache. Tests should call this in setUp /
     * tearDown when they declare aggregates dynamically on anonymous
     * fixture classes.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * For each inclusive AVG declaration on $class, returns the
     * column names of its companion SUM and COUNT definitions over
     * the same source. The result is consumed by Phase E maintenance
     * to write `avg = (sum + Δsum) / NULLIF(count + Δcount, 0)` in the
     * same UPDATE as the companion deltas.
     *
     * Companions are matched by source-column equality so user-declared
     * SUM/COUNT (e.g. `Aggregate::count('tickets')`) and auto-promoted
     * internal companions are treated uniformly. AVG declarations that
     * lack a matching pair are skipped — that state is unreachable when
     * declarations come through the registry's auto-promotion, but the
     * skip keeps the helper robust against direct
     * {@see AggregateDefinition} construction in tests.
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @return array<string, array{sum: string, count: string}>
     */
    public static function avgCompanionsFor(string $class): array
    {
        $definitions = self::for($class);
        $bySource = self::indexBySource($definitions);
        $listenerBySource = self::indexListenersByClassAndInclusive($definitions);

        $result = [];

        foreach ($definitions as $definition) {
            if ($definition instanceof AggregateDefinition) {
                if ($definition->function !== AggregateFunction::Avg) {
                    continue;
                }
                if ($definition->source === null) {
                    continue;
                }
                // Exclusive AVG is routed through the chain-recompute
                // path in the lifecycle hooks; it doesn't need the
                // delta-time SET clause.
                if (! $definition->inclusive) {
                    continue;
                }

                $companions = $bySource[$definition->source] ?? [];
                $sumColumn = null;
                $countColumn = null;

                foreach ($companions as $companion) {
                    if ($companion->function === AggregateFunction::Sum && $sumColumn === null) {
                        $sumColumn = $companion->column;
                    }
                    if ($companion->function === AggregateFunction::Count && $countColumn === null) {
                        $countColumn = $companion->column;
                    }
                }

                if ($sumColumn !== null && $countColumn !== null) {
                    $result[$definition->column] = ['sum' => $sumColumn, 'count' => $countColumn];
                }

                continue;
            }

            if ($definition instanceof ListenerAggregateDefinition
                && $definition->operation === AggregateFunction::Avg) {
                if (! $definition->inclusive) {
                    continue;   // exclusive listener AVG handled via chain recompute
                }

                $key = $definition->listenerClass.'|inc';
                $companions = $listenerBySource[$key] ?? [];

                $sumColumn = null;
                $countColumn = null;
                foreach ($companions as $companion) {
                    if ($companion->operation === AggregateFunction::Sum && $sumColumn === null) {
                        $sumColumn = $companion->column;
                    }
                    if ($companion->operation === AggregateFunction::Count && $countColumn === null) {
                        $countColumn = $companion->column;
                    }
                }

                if ($sumColumn !== null && $countColumn !== null) {
                    $result[$definition->column] = ['sum' => $sumColumn, 'count' => $countColumn];
                }
            }
        }

        return $result;
    }

    /**
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<AggregateDefinition>
     */
    private static function fromAttributes(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(NestedSetAggregate::class);

        $definitions = [];

        foreach ($attributes as $attribute) {
            $definitions[] = $attribute->newInstance()->toDefinition();
        }

        return $definitions;
    }

    /**
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<AggregateDefinition>
     */
    private static function fromMethodOverride(string $class): array
    {
        if (! method_exists($class, 'nestedSetAggregates')) {
            return [];
        }

        // Reflection invocation because the method is declared protected
        // on user models (mirrors the pattern in NestedSetScopeResolver
        // for getScopeAttributes()). Eloquent models are safe to
        // construct without args for class-level metadata reads.
        $instance = new $class;
        $method = (new ReflectionClass($class))->getMethod('nestedSetAggregates');
        $raw = $method->invoke($instance);

        if (! is_array($raw)) {
            throw new AggregateConfigurationException(sprintf(
                '%s::nestedSetAggregates() must return an array of AggregateDefinition.',
                $class,
            ));
        }

        $definitions = [];

        foreach ($raw as $index => $definition) {
            if (! $definition instanceof AggregateDefinition) {
                throw new AggregateConfigurationException(sprintf(
                    '%s::nestedSetAggregates(): entry at index %s is not an AggregateDefinition. '
                    .'Use Aggregate::sum(...)->into(...) to produce one.',
                    $class,
                    (string) $index,
                ));
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<ListenerAggregateDefinition>
     */
    private static function fromListenerAttributes(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(NestedSetAggregateListener::class);

        $definitions = [];

        foreach ($attributes as $attribute) {
            $definitions[] = $attribute->newInstance()->toDefinition();
        }

        return $definitions;
    }

    /**
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<ListenerAggregateDefinition>
     */
    private static function fromListenerMethodOverride(string $class): array
    {
        if (! method_exists($class, 'nestedSetListenerAggregates')) {
            return [];
        }

        $instance = new $class;
        $method = (new ReflectionClass($class))->getMethod('nestedSetListenerAggregates');
        $raw = $method->invoke($instance);

        if (! is_array($raw)) {
            throw new AggregateConfigurationException(sprintf(
                '%s::nestedSetListenerAggregates() must return an array of ListenerAggregateDefinition.',
                $class,
            ));
        }

        $definitions = [];

        foreach ($raw as $index => $definition) {
            if (! $definition instanceof ListenerAggregateDefinition) {
                throw new AggregateConfigurationException(sprintf(
                    '%s::nestedSetListenerAggregates(): entry at index %s is not a ListenerAggregateDefinition. '
                    .'Use ListenerAggregate::sum(...)->into(...) to produce one.',
                    $class,
                    (string) $index,
                ));
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * For each AVG definition that lacks a sibling SUM and COUNT on the
     * same source, adds internal companions. If the user has already
     * declared compatible companions (a SUM(source) and a COUNT(source)
     * or COUNT(*) on the same model), we leave the AVG to reference
     * those at maintenance time and skip auto-promotion. The decision
     * is made by source-column match; the actual reference resolution
     * lives in later phases.
     *
     * Non-{@see AggregateDefinition} entries (e.g. {@see ListenerAggregateDefinition})
     * are passed through unchanged; only SQL-function definitions participate
     * in AVG companion promotion.
     *
     * @param  list<AggregateDefinitionContract>  $definitions
     * @return list<AggregateDefinitionContract>
     */
    private static function autoPromoteAvgCompanions(array $definitions): array
    {
        $bySource = self::indexBySource($definitions);
        $listenerBySource = self::indexListenersByClassAndInclusive($definitions);

        /** @var list<AggregateDefinitionContract> $extras */
        $extras = [];

        foreach ($definitions as $definition) {
            if ($definition instanceof AggregateDefinition) {
                if ($definition->function !== AggregateFunction::Avg) {
                    continue;
                }

                if ($definition->source === null) {
                    throw new AggregateConfigurationException(sprintf(
                        'AggregateDefinition for column "%s": AVG requires a source column.',
                        $definition->column,
                    ));
                }

                $source = $definition->source;
                $companionsForSource = $bySource[$source] ?? [];

                $hasSum = self::hasFunction($companionsForSource, AggregateFunction::Sum);
                $hasCount = self::hasFunction($companionsForSource, AggregateFunction::Count);

                if (! $hasSum) {
                    $extras[] = new AggregateDefinition(
                        column: $definition->column.self::AVG_SUM_SUFFIX,
                        function: AggregateFunction::Sum,
                        source: $source,
                        inclusive: $definition->inclusive,
                        internal: true,
                        filter: $definition->filter,
                    );
                }

                if (! $hasCount) {
                    $extras[] = new AggregateDefinition(
                        column: $definition->column.self::AVG_COUNT_SUFFIX,
                        function: AggregateFunction::Count,
                        source: $source,
                        inclusive: $definition->inclusive,
                        internal: true,
                        filter: $definition->filter,
                    );
                }

                continue;
            }

            if ($definition instanceof ListenerAggregateDefinition
                && $definition->operation === AggregateFunction::Avg) {
                // Listener AVG: auto-promote Sum + Count companions
                // over the same listener class, same inclusivity. The
                // AVG display column is maintained as
                // `sum_col / NULLIF(count_col, 0)` after every delta
                // — same recipe as SQL AVG.
                $key = $definition->listenerClass.'|'.($definition->inclusive ? 'inc' : 'exc');
                $companions = $listenerBySource[$key] ?? [];

                $hasSum = false;
                $hasCount = false;
                foreach ($companions as $companion) {
                    if ($companion->operation === AggregateFunction::Sum) {
                        $hasSum = true;
                    }
                    if ($companion->operation === AggregateFunction::Count) {
                        $hasCount = true;
                    }
                }

                if (! $hasSum) {
                    $extras[] = new ListenerAggregateDefinition(
                        column: $definition->column.self::AVG_SUM_SUFFIX,
                        listenerClass: $definition->listenerClass,
                        operation: AggregateFunction::Sum,
                        inclusive: $definition->inclusive,
                        internal: true,
                    );
                }

                if (! $hasCount) {
                    $extras[] = new ListenerAggregateDefinition(
                        column: $definition->column.self::AVG_COUNT_SUFFIX,
                        listenerClass: $definition->listenerClass,
                        operation: AggregateFunction::Count,
                        inclusive: $definition->inclusive,
                        internal: true,
                    );
                }
            }
        }

        return array_merge($definitions, $extras);
    }

    /**
     * Group listener definitions by `listenerClass|inclusive` so AVG
     * auto-promotion can detect already-declared Sum/Count companions
     * (a user might declare Sum + Count + Avg manually for the same
     * listener; we skip promotion in that case).
     *
     * @param  list<AggregateDefinitionContract>  $definitions
     * @return array<string, list<ListenerAggregateDefinition>>
     */
    private static function indexListenersByClassAndInclusive(array $definitions): array
    {
        $index = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof ListenerAggregateDefinition) {
                continue;
            }
            $key = $definition->listenerClass.'|'.($definition->inclusive ? 'inc' : 'exc');
            $index[$key][] = $definition;
        }

        return $index;
    }

    /**
     * @param  list<AggregateDefinitionContract>  $definitions
     * @return array<string, list<AggregateDefinition>>
     */
    private static function indexBySource(array $definitions): array
    {
        $index = [];

        foreach ($definitions as $definition) {
            if (! $definition instanceof AggregateDefinition) {
                continue;
            }
            if ($definition->source === null) {
                continue;
            }
            $index[$definition->source][] = $definition;
        }

        return $index;
    }

    /**
     * @param  list<AggregateDefinition>  $definitions
     */
    private static function hasFunction(array $definitions, AggregateFunction $function): bool
    {
        foreach ($definitions as $definition) {
            if ($definition->function === $function) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<AggregateDefinitionContract>  $definitions
     * @param  class-string  $class
     */
    private static function assertNoDuplicateColumns(array $definitions, string $class): void
    {
        $seen = [];

        foreach ($definitions as $definition) {
            $column = $definition->getColumn();

            if (isset($seen[$column])) {
                throw new AggregateConfigurationException(sprintf(
                    '%s: aggregate column "%s" is declared more than once. '
                    .'Each stored aggregate column must be targeted by exactly one declaration.',
                    $class,
                    $column,
                ));
            }
            $seen[$column] = true;
        }
    }

    /**
     * Aggregate columns are derived state — every mutation overwrites
     * them via the maintenance machinery. Listing them in `$fillable`
     * means mass-assignment briefly writes user-supplied values that
     * the next save silently clobbers, producing apparent data loss
     * with no error trail.
     *
     * Catch this at registry-build time so models fail loudly during
     * boot rather than silently the first time someone passes a
     * request body through `Model::create($request->all())`.
     *
     * Excluded from the check:
     *  - Internal AVG companions: never user-declared, so users
     *    can't put them in `$fillable` by mistake. Skip to keep the
     *    error message focused on user-facing columns.
     *
     * @param  list<AggregateDefinitionContract>  $definitions
     * @param  class-string  $class
     */
    private static function assertNoAggregateColumnsInFillable(array $definitions, string $class): void
    {
        if ($definitions === []) {
            return;
        }

        // Eloquent stores `$fillable` as a protected property. Read
        // via reflection on the prototype — instantiating the model
        // for a property read is wasteful but matches the registry's
        // existing method-override pattern.
        try {
            $reflection = new ReflectionClass($class);
            if (! $reflection->hasProperty('fillable')) {
                return;
            }
            $prop = $reflection->getProperty('fillable');
            $instance = new $class;
            $value = $prop->getValue($instance);
            if (! is_array($value)) {
                return;
            }
            /** @var list<string> $fillable */
            $fillable = array_values(array_filter($value, is_string(...)));
        } catch (\ReflectionException) {
            return;
        }

        if ($fillable === []) {
            return;
        }

        $conflicts = [];
        foreach ($definitions as $definition) {
            if ($definition instanceof AggregateDefinition && $definition->isInternal()) {
                continue;
            }
            $column = $definition->getColumn();
            if (in_array($column, $fillable, true)) {
                $conflicts[] = $column;
            }
        }

        if ($conflicts === []) {
            return;
        }

        throw new AggregateConfigurationException(sprintf(
            '%s: aggregate column(s) [%s] appear in $fillable. Aggregate columns are derived state — '
            .'the package overwrites them on every mutation, so mass-assigning to them is silently undone. '
            .'Remove from $fillable; the column stays usable on the model (cast, hidden, etc. all still apply).',
            $class,
            implode(', ', $conflicts),
        ));
    }
}
