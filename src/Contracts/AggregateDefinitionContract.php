<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Contracts;

use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;

/**
 * Common contract for all aggregate definition types. The minimal surface
 * needed by code that handles both SQL-function aggregates
 * ({@see AggregateDefinition}) and PHP listener aggregates
 * ({@see ListenerAggregateDefinition}) without knowing which kind it holds.
 *
 * Callers that need function-specific properties (source column, aggregate
 * function, filter predicate) must narrow with `instanceof AggregateDefinition`
 * first. Callers that need listener-specific properties must narrow with
 * `instanceof ListenerAggregateDefinition`.
 */
interface AggregateDefinitionContract
{
    public function getColumn(): string;

    public function isInclusive(): bool;

    /**
     * True when this definition was auto-added by the registry as a
     * companion to a user-declared AVG. Internal companions are maintained
     * by the engine but are not part of the public API surface.
     */
    public function isInternal(): bool;

    /**
     * True when this aggregate's column is maintained lazily — mutations
     * invalidate (set the column and its `<column>_computed_at` stamp to
     * NULL) instead of eagerly recomputing; the next read populates both.
     *
     * Mirrors the `$lazy` constructor flag on the concrete definitions.
     */
    public function isLazy(): bool;

    /**
     * TTL (seconds) after which a stamped lazy column is considered stale
     * and the next read triggers a recompute. `null` means "no time-based
     * expiry — only invalidate on mutation". Always `null` when
     * {@see self::isLazy()} returns false.
     */
    public function lazyTtlSeconds(): ?int;

    /**
     * Companion stamp column name (`<column>_computed_at`) the lazy
     * machinery reads and writes to track freshness. Returns the same
     * string regardless of `isLazy()` for use as a derivation helper —
     * callers should gate on `isLazy()` before reading or writing it.
     */
    public function lazyStampColumn(): string;
}
