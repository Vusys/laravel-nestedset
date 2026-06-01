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
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

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
     * @param  bool  $lazy  When true, mutations invalidate this column
     *                      (set it and its `<column>_computed_at` stamp
     *                      companion to NULL on every affected ancestor)
     *                      instead of eagerly recomputing. The first read
     *                      past the invalidation recomputes via
     *                      {@see HasNestedSetAggregates::freshAggregate()}
     *                      and stamps the companion. The stamp column
     *                      must exist on the table as a nullable timestamp.
     * @param  int|null  $ttl  Seconds after which a stamped value is
     *                         treated as stale (read triggers recompute).
     *                         `null` means "no time-based expiry — only
     *                         invalidate on mutation".
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
        public ?int $k = null,
        public ?string $topKBy = null,
        public bool $lazy = false,
        public ?int $ttl = null,
    ) {
        if ($this->lazy && ! $this->function->supportsLazy()) {
            throw new AggregateConfigurationException(sprintf(
                'AggregateDefinition for column "%s": lazy is not supported on %s — '
                .'companion-derived display kinds (Avg / Variance / Stddev / WeightedAvg / '
                .'BoolOr / BoolAnd / GeometricMean / HarmonicMean) and fresh-read-only '
                .'kinds (Median / Percentile) cannot be lazy. Use lazy on the supporting '
                .'Sum / Count companions if you need their maintenance deferred.',
                $this->column,
                $this->function->value,
            ));
        }

        if ($this->lazy && $this->internal) {
            throw new AggregateConfigurationException(sprintf(
                'AggregateDefinition for column "%s": auto-promoted internal companion '
                .'cannot be lazy. Set `lazy: true` on the user-facing declaration instead.',
                $this->column,
            ));
        }

        if (! $this->lazy && $this->ttl !== null) {
            throw new AggregateConfigurationException(sprintf(
                'AggregateDefinition for column "%s": `ttl` only applies when `lazy: true`.',
                $this->column,
            ));
        }

        if ($this->ttl !== null && $this->ttl <= 0) {
            throw new AggregateConfigurationException(sprintf(
                'AggregateDefinition for column "%s": `ttl` must be a positive integer (seconds), got %d.',
                $this->column,
                $this->ttl,
            ));
        }
    }

    /**
     * Stamp companion column for lazy aggregates — `<column>_computed_at`.
     * NULL means stale (recompute on next read); non-NULL means fresh
     * as of that timestamp (subject to TTL).
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
            $this->topKBy !== null ? [$this->topKBy] : [],
            $this->filter?->watchColumns() ?? [],
        )));
    }
}
