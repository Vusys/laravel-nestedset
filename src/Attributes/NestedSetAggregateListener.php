<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Attributes;

use Attribute;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\ListenerAggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Contracts\TreeAggregateListener;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * Declares a listener-based precalculated aggregate column on a nested-set
 * model. The attribute is repeatable so multiple aggregates can be declared
 * on a single class:
 *
 *     #[NestedSetAggregateListener(column: 'weighted_power', listener: WeightedPowerListener::class)]
 *     #[NestedSetAggregateListener(column: 'fire_count', listener: FireCountListener::class)]
 *     class Monster extends Model implements HasNestedSet { use NodeTrait; }
 *
 * Unlike {@see NestedSetAggregate}, this attribute accepts a PHP listener
 * class rather than a SQL source column, enabling aggregations that require
 * arbitrary PHP logic to compute each node's contribution.
 *
 * `exclusive: true` opts out of self-inclusion — a leaf's stored value for
 * an exclusive aggregate is always the function's zero/null element.
 *
 * Operations: Sum, Count, Min, Max, Avg, Variance, Stddev, GeometricMean,
 * HarmonicMean. The companion-derived operations (Avg, Variance, Stddev,
 * GeometricMean, HarmonicMean) are maintained as a derived value — the
 * registry auto-promotes hidden Sum / Sum_sq / Count (or Sum_log / Sum_recip)
 * companions over the same listener class; the display column is computed
 * directly from contributions during maintenance. Migrations for
 * companion-derived listener columns must declare the companion columns
 * alongside the display column — see docs/aggregates/listeners.md.
 *
 * Optional row-level filtering is available via the `filter` (equality) and
 * `filterNotNull` parameters, mirroring the SQL aggregate shape. There is
 * no `filterRaw` form: listener mode has no SQL evaluation path, so a raw
 * SQL predicate would have nowhere to run. Use `filter` / `filterNotNull`,
 * or return `null` from `contribution()` for arbitrary predicates.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class NestedSetAggregateListener
{
    /**
     * @param  string  $listener  class-string<TreeAggregateListener>
     * @param  array<string,mixed>|null  $filter  Equality-filter conditions; a node
     *                                            contributes only when every (column => value) pair
     *                                            matches its attributes. Mutually exclusive with
     *                                            $filterNotNull.
     * @param  string|null  $filterNotNull  Column name; a node contributes only when
     *                                      the named attribute is non-null. Mutually exclusive
     *                                      with $filter.
     */
    public function __construct(
        public string $column,
        public string $listener,
        public AggregateFunction $operation = AggregateFunction::Sum,
        public bool $exclusive = false,
        public bool $lazy = false,
        public ?int $ttl = null,
        public ?array $filter = null,
        public ?string $filterNotNull = null,
    ) {}

    /**
     * Materialises this declaration as a {@see ListenerAggregateDefinition}.
     *
     * @throws AggregateConfigurationException when $column is empty or both
     *                                         filter forms are supplied.
     */
    public function toDefinition(): ListenerAggregateDefinition
    {
        if ($this->column === '') {
            throw new AggregateConfigurationException(
                'NestedSetAggregateListener: `column` must not be empty.',
            );
        }

        return new ListenerAggregateDefinition(
            column: $this->column,
            listenerClass: $this->listener,
            operation: $this->operation,
            inclusive: ! $this->exclusive,
            lazy: $this->lazy,
            ttl: $this->ttl,
            filter: $this->resolveFilter(),
        );
    }

    private function resolveFilter(): ?FilterPredicate
    {
        if ($this->filter !== null && $this->filterNotNull !== null) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregateListener for column "%s": at most one filter form may be declared '
                .'(filter, filterNotNull).',
                $this->column,
            ));
        }

        if ($this->filter !== null) {
            return FilterPredicate::equality($this->filter);
        }

        if ($this->filterNotNull !== null) {
            return FilterPredicate::notNull($this->filterNotNull);
        }

        return null;
    }
}
