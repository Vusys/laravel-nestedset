<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Definitions;

use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\ListenerAggregate;
use Vusys\NestedSet\Attributes\NestedSetAggregateListener;
use Vusys\NestedSet\Contracts\AggregateDefinitionContract;
use Vusys\NestedSet\Contracts\TreeAggregateListener;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * Immutable resolved declaration for a listener-based aggregate column.
 *
 * Produced by {@see ListenerAggregate::into()} (method-override form) or
 * by {@see NestedSetAggregateListener::toDefinition()}
 * (attribute form), and stored in the aggregate registry.
 *
 * Implements {@see AggregateDefinitionContract} so it can be handled
 * alongside {@see AggregateDefinition} by code that does not need to know
 * which kind it holds.
 */
final readonly class ListenerAggregateDefinition implements AggregateDefinitionContract
{
    /**
     * @param  string  $listenerClass  class-string<TreeAggregateListener>
     * @param  bool  $internal  true when this definition was auto-added
     *                          by the registry as a companion to a
     *                          companion-derived listener declaration
     *                          (AVG, Variance, Stddev, GeometricMean,
     *                          HarmonicMean). Internal companions are
     *                          maintained by the engine but excluded
     *                          from the user-facing inspection API
     *                          (getAggregateDefinitions(),
     *                          aggregateErrors() output).
     * @param  bool  $lazy  See {@see AggregateDefinition::$lazy}. Forbidden
     *                      on companion-derived listener kinds because the
     *                      display column is derived from internal
     *                      companions and a lazy display column would
     *                      require lazy companions too.
     * @param  int|null  $ttl  See {@see AggregateDefinition::$ttl}.
     * @param  FilterPredicate|null  $filter  Optional row-level predicate
     *                                        deciding whether a node contributes. When
     *                                        the predicate rejects a node, the listener's
     *                                        contribution() result is treated as null
     *                                        (excluded). Only {@see FilterPredicate::equality()}
     *                                        and {@see FilterPredicate::notNull()} forms
     *                                        are evaluable in listener mode — raw SQL
     *                                        predicates have no evaluation path here
     *                                        and are rejected at attribute-build time.
     * @param  CompanionSourceTransform  $sourceTransform  Applied PHP-side
     *                                                     to the raw contribution() value before it
     *                                                     feeds the operation. Identity passes through
     *                                                     untouched. Auto-promoted companions inherit
     *                                                     the transform from their {@see CompanionSpec}
     *                                                     (Square for variance __sum_sq, Ln for
     *                                                     geomean __sum_log / __count, Recip for
     *                                                     harmonic __sum_recip / __count).
     */
    public function __construct(
        public string $column,
        public string $listenerClass,
        public AggregateFunction $operation,
        public bool $inclusive = true,
        public bool $internal = false,
        public bool $lazy = false,
        public ?int $ttl = null,
        public ?FilterPredicate $filter = null,
        public CompanionSourceTransform $sourceTransform = CompanionSourceTransform::Identity,
    ) {
        if (in_array($this->operation, [AggregateFunction::BitOr, AggregateFunction::BitAnd, AggregateFunction::BitXor], true)) {
            throw new AggregateConfigurationException(sprintf(
                'Listener aggregate "%s" cannot use a bitwise operation (%s). '
                .'Bitwise rollups are SQL-only — declare via #[NestedSetAggregate(bitOr: ...)] or Aggregate::bitOr(...) on a source column.',
                $column,
                $operation->value,
            ));
        }

        if ($this->lazy && $this->internal) {
            throw new AggregateConfigurationException(sprintf(
                'Listener aggregate "%s": `lazy` cannot be true for internal listener companions. '
                .'Set `lazy: true` on the user-facing declaration instead.',
                $column,
            ));
        }

        if ($this->lazy && ! $this->operation->supportsLazy()) {
            throw new AggregateConfigurationException(sprintf(
                'Listener aggregate "%s" cannot be declared lazy: operation %s is companion-derived. '
                .'Lazy is allowed on Sum, Count, Min, Max only for listener aggregates.',
                $column,
                $operation->value,
            ));
        }

        if (! $this->lazy && $this->ttl !== null) {
            throw new AggregateConfigurationException(sprintf(
                'Listener aggregate "%s": `ttl` only applies when `lazy: true`.',
                $column,
            ));
        }

        if ($this->ttl !== null && $this->ttl <= 0) {
            throw new AggregateConfigurationException(sprintf(
                'Listener aggregate "%s": `ttl` must be a positive integer (seconds), got %d.',
                $column,
                $this->ttl,
            ));
        }
    }

    /**
     * Stamp companion column name for lazy aggregates — same convention
     * as {@see AggregateDefinition::lazyStampColumn()}.
     */
    public function lazyStampColumn(): string
    {
        return $this->column.'_computed_at';
    }

    public function isLazy(): bool
    {
        return $this->lazy;
    }

    public function lazyTtlSeconds(): ?int
    {
        return $this->ttl;
    }

    public function getColumn(): string
    {
        return $this->column;
    }

    public function isInclusive(): bool
    {
        return $this->inclusive;
    }

    /**
     * True when this definition was auto-added by the registry as a
     * companion to a listener AVG declaration.
     */
    public function isInternal(): bool
    {
        return $this->internal;
    }

    /**
     * Resolves and returns an instance of the listener class.
     *
     * @throws AggregateConfigurationException if the class does not exist
     *                                         or does not implement {@see TreeAggregateListener}.
     */
    public function makeListener(): TreeAggregateListener
    {
        if (! class_exists($this->listenerClass)) {
            throw new AggregateConfigurationException(sprintf(
                'Listener class "%s" does not exist.',
                $this->listenerClass,
            ));
        }

        if (! is_a($this->listenerClass, TreeAggregateListener::class, true)) {
            throw new AggregateConfigurationException(sprintf(
                'Listener class "%s" does not implement %s.',
                $this->listenerClass,
                TreeAggregateListener::class,
            ));
        }

        return new ($this->listenerClass)();
    }
}
