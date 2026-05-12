<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Concerns\HasNestedSetAggregates;

/**
 * A fully-resolved aggregate declaration: target column, function,
 * source column (null for COUNT(*)), inclusivity flag, and an internal
 * flag for companions auto-promoted by AVG declarations.
 *
 * Produced by {@see Aggregate::into()} (method-override form) or by
 * {@see NestedSetAggregate::toDefinition()}
 * (attribute form), and stored in {@see AggregateRegistry}.
 */
final readonly class AggregateDefinition
{
    public function __construct(
        public string $column,
        public AggregateFunction $function,
        public ?string $source,
        public bool $inclusive,
        public bool $internal = false,
    ) {}

    /**
     * True when this definition was auto-added by the registry as a
     * companion to a user-declared AVG. Internal companions are
     * maintained alongside their parent AVG but are not part of the
     * user's public API surface (they don't appear in
     * {@see HasNestedSetAggregates::getAggregateDefinitions()}
     * for end-user inspection).
     */
    public function isInternal(): bool
    {
        return $this->internal;
    }
}
