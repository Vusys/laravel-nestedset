<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
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
 *   3. Auto-promotion: each AVG declaration without explicit companion
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
     * @var array<class-string, list<AggregateDefinition>>
     */
    private static array $cache = [];

    /**
     * Returns the merged, validated, auto-promoted set of definitions
     * for $class. Empty list means the model declares no aggregates.
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<AggregateDefinition>
     */
    public static function for(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $definitions = array_merge(
            self::fromAttributes($class),
            self::fromMethodOverride($class),
        );

        $definitions = self::autoPromoteAvgCompanions($definitions);

        self::assertNoDuplicateColumns($definitions, $class);

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

        $result = [];

        foreach ($definitions as $definition) {
            if ($definition->function !== AggregateFunction::Avg) {
                continue;
            }
            if (! $definition->inclusive) {
                // Phase E covers inclusive AVG only; exclusive arrives in Phase G.
                continue;
            }
            if ($definition->source === null) {
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
     * For each AVG definition that lacks a sibling SUM and COUNT on the
     * same source, adds internal companions. If the user has already
     * declared compatible companions (a SUM(source) and a COUNT(source)
     * or COUNT(*) on the same model), we leave the AVG to reference
     * those at maintenance time and skip auto-promotion. The decision
     * is made by source-column match; the actual reference resolution
     * lives in later phases.
     *
     * @param  list<AggregateDefinition>  $definitions
     * @return list<AggregateDefinition>
     */
    private static function autoPromoteAvgCompanions(array $definitions): array
    {
        $bySource = self::indexBySource($definitions);

        $extras = [];

        foreach ($definitions as $definition) {
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
                );
            }

            if (! $hasCount) {
                $extras[] = new AggregateDefinition(
                    column: $definition->column.self::AVG_COUNT_SUFFIX,
                    function: AggregateFunction::Count,
                    source: $source,
                    inclusive: $definition->inclusive,
                    internal: true,
                );
            }
        }

        return array_merge($definitions, $extras);
    }

    /**
     * @param  list<AggregateDefinition>  $definitions
     * @return array<string, list<AggregateDefinition>>
     */
    private static function indexBySource(array $definitions): array
    {
        $index = [];

        foreach ($definitions as $definition) {
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
     * @param  list<AggregateDefinition>  $definitions
     * @param  class-string  $class
     */
    private static function assertNoDuplicateColumns(array $definitions, string $class): void
    {
        $seen = [];

        foreach ($definitions as $definition) {
            if (isset($seen[$definition->column])) {
                throw new AggregateConfigurationException(sprintf(
                    '%s: aggregate column "%s" is declared more than once. '
                    .'Each stored aggregate column must be targeted by exactly one declaration.',
                    $class,
                    $definition->column,
                ));
            }
            $seen[$definition->column] = true;
        }
    }
}
