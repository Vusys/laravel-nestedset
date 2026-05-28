<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Definitions;

use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Registry\AggregateRegistry;
use Vusys\NestedSet\Attributes\NestedSetAggregate;
use Vusys\NestedSet\Concerns\HasNestedSetAggregates;
use Vusys\NestedSet\Contracts\AggregateDefinitionContract;

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
        public ?string $weight = null,
        public bool $allowNonPositive = false,
        public float $percentilePoint = 0.5,
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

    /**
     * Columns whose dirty state should trigger aggregate maintenance for
     * this definition. Includes the source column, the filter's watch
     * columns, and (when the source transform consumes a weight) the
     * weight column too — without the weight trigger, a row whose value
     * is unchanged but whose weight changed would skip the delta capture
     * and leave `Σ(w · x)` stale.
     *
     * @return list<string>
     */
    public function triggerColumns(): array
    {
        return array_values(array_unique(array_merge(
            $this->source !== null ? [$this->source] : [],
            $this->sourceTransform->requiresWeight() && $this->weight !== null
                ? [$this->weight]
                : [],
            $this->filter?->watchColumns() ?? [],
        )));
    }
}
