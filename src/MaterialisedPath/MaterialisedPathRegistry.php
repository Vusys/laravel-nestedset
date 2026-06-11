<?php

declare(strict_types=1);

namespace Vusys\NestedSet\MaterialisedPath;

use Closure;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionMethod;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPath;
use Vusys\NestedSet\Attributes\NestedSetMaterialisedPathDefaults;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\MaterialisedPathConfigurationException;
use Vusys\NestedSet\Support\Runtime;

/**
 * Resolves the merged set of materialised-path declarations for a model
 * class, applying the five-layer defaults chain and caching per FQCN.
 *
 * Resolution sources, in this order:
 *   1. `#[NestedSetMaterialisedPath]` attribute instances (walking the
 *      parent chain for attribute inheritance).
 *   2. Optional `materialisedPaths(): array` static method on the
 *      model — strings auto-wrap to `attribute()`, closures to
 *      `from()`, value objects pass through. Method entries win on
 *      column collision with attribute entries.
 *
 * Defaults sources, most specific to least:
 *   1. Per-path explicit value already set on the value object.
 *   2. `#[NestedSetMaterialisedPathDefaults]` on the class (with
 *      ancestor walk).
 *   3. `config('nestedset.materialised_path.class_defaults.'.$class)`
 *      — exact FQCN, no `is_a` walk.
 *   4. `config('nestedset.materialised_path.defaults')` — global
 *      fallback.
 *   5. Package hard-coded fallback (the {@see MaterialisedPath}
 *      getter defaults when nothing matches above).
 */
final class MaterialisedPathRegistry
{
    /**
     * @var array<class-string, array<string, MaterialisedPath>>
     */
    private static array $cache = [];

    /**
     * @param  class-string<Model&HasNestedSet>  $class
     * @return array<string, MaterialisedPath> column => resolved path
     */
    public static function for(string $class): array
    {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $paths = self::fromAttributes($class);
        $paths = self::mergeMethodOverride($class, $paths);

        $defaults = self::resolveDefaults($class);

        $resolved = [];
        foreach ($paths as $column => $path) {
            $resolved[$column] = $path->withResolvedDefaults($defaults);
        }

        return self::$cache[$class] = $resolved;
    }

    public static function forgetCache(?string $class = null): void
    {
        if ($class === null) {
            self::$cache = [];

            return;
        }

        unset(self::$cache[$class]);
    }

    /**
     * Returns the list of columns declared on a class without going
     * through full resolution. Useful for callers that need just the
     * column-name set (e.g. clone $transform rejection).
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<string>
     */
    public static function columnsFor(string $class): array
    {
        return array_keys(self::for($class));
    }

    /**
     * @param  class-string  $class
     * @return array<string, MaterialisedPath>
     */
    private static function fromAttributes(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $paths = [];

        $chain = self::classChain($reflection);
        foreach ($chain as $rc) {
            foreach ($rc->getAttributes(NestedSetMaterialisedPath::class) as $attr) {
                $instance = $attr->newInstance();
                $value = $instance->toValueObject();

                if (isset($paths[$instance->column])) {
                    throw new MaterialisedPathConfigurationException(sprintf(
                        '%s: duplicate #[NestedSetMaterialisedPath(column: "%s")] declaration. '
                        .'Each column may be declared at most once via attribute (a method-form '
                        .'entry may still override it).',
                        $class,
                        $instance->column,
                    ));
                }

                $paths[$instance->column] = $value;
            }
        }

        return $paths;
    }

    /**
     * @param  class-string  $class
     * @param  array<string, MaterialisedPath>  $paths
     * @return array<string, MaterialisedPath>
     */
    private static function mergeMethodOverride(string $class, array $paths): array
    {
        if (! method_exists($class, 'materialisedPaths')) {
            return $paths;
        }

        $reflection = new ReflectionMethod($class, 'materialisedPaths');
        if (! $reflection->isStatic()) {
            throw new MaterialisedPathConfigurationException(sprintf(
                '%s::materialisedPaths() must be declared `protected static` (or `public static`).',
                $class,
            ));
        }
        $raw = $reflection->invoke(null);

        if (! is_array($raw)) {
            throw new MaterialisedPathConfigurationException(sprintf(
                '%s::materialisedPaths() must return array<string, MaterialisedPath|callable|string>; got %s.',
                $class,
                get_debug_type($raw),
            ));
        }

        foreach ($raw as $column => $entry) {
            if (! is_string($column) || $column === '') {
                throw new MaterialisedPathConfigurationException(sprintf(
                    '%s::materialisedPaths(): every entry must be keyed by a non-empty column name.',
                    $class,
                ));
            }

            $paths[$column] = self::normaliseEntry($column, $entry, $class);
        }

        return $paths;
    }

    private static function normaliseEntry(string $column, mixed $entry, string $class): MaterialisedPath
    {
        if ($entry instanceof MaterialisedPath) {
            return $entry;
        }

        if ($entry instanceof Closure || (is_object($entry) && is_callable($entry))) {
            return MaterialisedPath::from($entry);
        }

        // String check must precede is_callable() — a column name that
        // matches a global function name (e.g. 'count', 'strlen') would
        // otherwise be wrapped as a closure instead of an attribute source.
        if (is_string($entry)) {
            return MaterialisedPath::attribute($entry);
        }

        if (is_callable($entry)) {
            return MaterialisedPath::from(Closure::fromCallable($entry));
        }

        throw new MaterialisedPathConfigurationException(sprintf(
            '%s::materialisedPaths()[%s]: entry must be a MaterialisedPath, a callable, '
            .'or a string attribute name; got %s.',
            $class,
            $column,
            get_debug_type($entry),
        ));
    }

    /**
     * @param  class-string  $class
     * @return array{separator?: string, wrap?: bool, maxLength?: int, rejectSeparatorInSegment?: bool, uniquePerParent?: bool}
     */
    private static function resolveDefaults(string $class): array
    {
        // Lowest precedence first; later layers override.
        $merged = self::globalDefaults();
        $merged = self::merge($merged, self::classConfigDefaults($class));

        return self::merge($merged, self::classAttributeDefaults($class));
    }

    /**
     * @return array{separator?: string, wrap?: bool, maxLength?: int, rejectSeparatorInSegment?: bool, uniquePerParent?: bool}
     */
    private static function globalDefaults(): array
    {
        $raw = Runtime::config('nestedset.materialised_path.defaults', []);

        return is_array($raw) ? self::sanitiseDefaults($raw) : [];
    }

    /**
     * @return array{separator?: string, wrap?: bool, maxLength?: int, rejectSeparatorInSegment?: bool, uniquePerParent?: bool}
     */
    private static function classConfigDefaults(string $class): array
    {
        $raw = Runtime::config('nestedset.materialised_path.class_defaults.'.$class);

        if (! is_array($raw)) {
            return [];
        }

        return self::sanitiseDefaults($raw);
    }

    /**
     * @param  class-string  $class
     * @return array{separator?: string, wrap?: bool, maxLength?: int, rejectSeparatorInSegment?: bool, uniquePerParent?: bool}
     */
    private static function classAttributeDefaults(string $class): array
    {
        $reflection = new ReflectionClass($class);
        $found = null;

        // Walk root-down so the most-derived class wins.
        $chain = self::classChain($reflection);
        foreach ($chain as $rc) {
            $attrs = $rc->getAttributes(NestedSetMaterialisedPathDefaults::class);
            if ($attrs === []) {
                continue;
            }
            if (count($attrs) > 1) {
                throw new MaterialisedPathConfigurationException(sprintf(
                    '%s: at most one #[NestedSetMaterialisedPathDefaults] may be declared per class.',
                    $rc->getName(),
                ));
            }
            $found = $attrs[0]->newInstance();
        }

        return $found?->toArray() ?? [];
    }

    /**
     * @param  array{separator?: string, wrap?: bool, maxLength?: int, rejectSeparatorInSegment?: bool, uniquePerParent?: bool}  $a
     * @param  array{separator?: string, wrap?: bool, maxLength?: int, rejectSeparatorInSegment?: bool, uniquePerParent?: bool}  $b
     * @return array{separator?: string, wrap?: bool, maxLength?: int, rejectSeparatorInSegment?: bool, uniquePerParent?: bool}
     */
    private static function merge(array $a, array $b): array
    {
        return $b + $a;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array{separator?: string, wrap?: bool, maxLength?: int, rejectSeparatorInSegment?: bool, uniquePerParent?: bool}
     */
    private static function sanitiseDefaults(array $raw): array
    {
        $out = [];
        if (isset($raw['separator']) && is_string($raw['separator'])) {
            $out['separator'] = $raw['separator'];
        }
        if (isset($raw['wrap']) && is_bool($raw['wrap'])) {
            $out['wrap'] = $raw['wrap'];
        }
        if (isset($raw['maxLength']) && is_int($raw['maxLength'])) {
            $out['maxLength'] = $raw['maxLength'];
        }
        if (isset($raw['rejectSeparatorInSegment']) && is_bool($raw['rejectSeparatorInSegment'])) {
            $out['rejectSeparatorInSegment'] = $raw['rejectSeparatorInSegment'];
        }
        if (isset($raw['uniquePerParent']) && is_bool($raw['uniquePerParent'])) {
            $out['uniquePerParent'] = $raw['uniquePerParent'];
        }

        return $out;
    }

    /**
     * Root-down class chain (oldest ancestor first, the class itself
     * last). Used for both attribute inheritance and defaults walk so
     * the most-derived class wins.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return list<ReflectionClass<object>>
     */
    private static function classChain(ReflectionClass $reflection): array
    {
        $chain = [];
        $current = $reflection;
        while ($current !== false) {
            $chain[] = $current;
            $current = $current->getParentClass();
        }

        return array_reverse($chain);
    }
}
