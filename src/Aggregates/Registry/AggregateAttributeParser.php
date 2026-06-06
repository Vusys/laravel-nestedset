<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Registry;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\AggregateDefinitionContract;
use Vusys\NestedSet\Contracts\HasNestedSet;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * Reads aggregate declarations off a model class — `#[NestedSetAggregate]`
 * / `#[NestedSetAggregateListener]` attributes and the
 * `nestedSetAggregates()` / `nestedSetListenerAggregates()` method
 * overrides — and materialises each into its definition value object.
 *
 * Pure reflection / no validation: companion auto-promotion and
 * structural assertions live in {@see AggregateDefinitionValidator}.
 * {@see AggregateRegistry::for()} orchestrates parse → validate → cache.
 */
final class AggregateAttributeParser
{
    /**
     * Returns the user-declared definitions for `$class` in resolution
     * order — class attributes first, then the matching method override.
     * SQL aggregates come before listener aggregates so AVG-companion
     * auto-promotion (downstream) sees siblings in a stable order.
     *
     * @param  class-string<Model&HasNestedSet>  $class
     * @return list<AggregateDefinitionContract>
     */
    public static function parse(string $class): array
    {
        /** @var list<AggregateDefinitionContract> $definitions */
        $definitions = array_merge(
            self::fromAttributes($class),
            self::fromMethodOverride($class),
            self::fromListenerAttributes($class),
            self::fromListenerMethodOverride($class),
        );

        return $definitions;
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
}
