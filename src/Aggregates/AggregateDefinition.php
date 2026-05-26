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
 *
 * Implements {@see AggregateDefinitionContract} so it can be handled
 * alongside {@see ListenerAggregateDefinition} by code that does not
 * need to know which kind it holds.
 *
 * The properties from `$separator` onward are only meaningful for the
 * collection-aggregate kinds (StringAgg / JsonAgg / JsonObjectAgg /
 * DistinctCount). The numeric SUM/COUNT/AVG/MIN/MAX kinds ignore them
 * — their defaults are no-ops.
 */
final readonly class AggregateDefinition implements AggregateDefinitionContract
{
    /**
     * @param  array<string,string>  $sources  multi-column source map for JsonAgg
     *                                         (JSON key => source column). Empty for scalar / non-JSON kinds.
     */
    public function __construct(
        public string $column,
        public AggregateFunction $function,
        public ?string $source,
        public bool $inclusive,
        public bool $internal = false,
        public ?FilterPredicate $filter = null,
        public bool $sample = false,
        public CompanionSourceTransform $sourceTransform = CompanionSourceTransform::Identity,
        public string $separator = ', ',
        public ?int $limit = null,
        public ?string $orderBy = null,
        public bool $distinct = false,
        public bool $allowNullKeys = false,
        public ?string $keyColumn = null,
        public ?string $valueColumn = null,
        public array $sources = [],
    ) {}

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
