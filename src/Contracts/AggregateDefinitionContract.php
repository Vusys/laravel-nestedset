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
}
