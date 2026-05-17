<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Attributes;

use Attribute;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\FilterPredicate;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * Declares a precalculated aggregate column on a nested-set model.
 *
 * The attribute is repeatable so multiple aggregates can be declared on
 * a single class:
 *
 *     #[NestedSetAggregate(column: 'tickets_total', sum: 'tickets')]
 *     #[NestedSetAggregate(column: 'tickets_count', count: true)]
 *     #[NestedSetAggregate(column: 'tickets_avg',   avg: 'tickets')]
 *     #[NestedSetAggregate(column: 'tickets_max',   max: 'tickets')]
 *     class Area extends Model implements HasNestedSet { use NodeTrait; }
 *
 * Exactly one of `sum | count | avg | min | max` must be provided per
 * attribute instance; passing zero or more than one throws
 * {@see AggregateConfigurationException} when the registry resolves
 * declarations. `count: true` declares COUNT(*); for the
 * non-null-skipping COUNT(column) variant use the method-override form
 * {@see Aggregate::count()}.
 *
 * `exclusive: true` opts out of self-inclusion — a leaf's stored value
 * for an exclusive aggregate is always the function's zero/null element
 * rather than its own source value.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class NestedSetAggregate
{
    /**
     * @param  array<string,mixed>|null  $filter
     * @param  list<string>              $filterRawWatches
     */
    public function __construct(
        public string $column,
        public ?string $sum = null,
        public bool $count = false,
        public ?string $avg = null,
        public ?string $min = null,
        public ?string $max = null,
        public bool $exclusive = false,
        public ?array $filter = null,
        public ?string $filterNotNull = null,
        public ?string $filterRaw = null,
        public array $filterRawWatches = [],
    ) {}

    /**
     * Materialises this declaration as an {@see AggregateDefinition}.
     *
     * @throws AggregateConfigurationException when the attribute is
     *                                         missing a function or specifies more than one.
     */
    public function toDefinition(): AggregateDefinition
    {
        if ($this->column === '') {
            throw new AggregateConfigurationException(
                'NestedSetAggregate: `column` must not be empty.',
            );
        }

        $declared = $this->declaredFunctions();

        if ($declared === []) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": no aggregate function declared. '
                .'Provide exactly one of sum, count, avg, min, max.',
                $this->column,
            ));
        }

        if (count($declared) > 1) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": multiple aggregate functions declared (%s). '
                .'Each declaration must use exactly one of sum, count, avg, min, max.',
                $this->column,
                implode(', ', array_keys($declared)),
            ));
        }

        [$function, $source] = $this->resolveFunction($declared);

        return new AggregateDefinition(
            column: $this->column,
            function: $function,
            source: $source,
            inclusive: ! $this->exclusive,
            filter: $this->resolveFilter(),
        );
    }

    /**
     * Returns the subset of {sum,count,avg,min,max} args that were
     * actually provided. Used both for validation and to drive the
     * function/source resolution below.
     *
     * @return array<string, mixed>
     */
    private function declaredFunctions(): array
    {
        $declared = [];

        if ($this->sum !== null) {
            $declared['sum'] = $this->sum;
        }
        if ($this->count) {
            $declared['count'] = true;
        }
        if ($this->avg !== null) {
            $declared['avg'] = $this->avg;
        }
        if ($this->min !== null) {
            $declared['min'] = $this->min;
        }
        if ($this->max !== null) {
            $declared['max'] = $this->max;
        }

        return $declared;
    }

    /**
     * @param  array<string, mixed>  $declared  exactly one entry.
     * @return array{0: AggregateFunction, 1: ?string}
     */
    private function resolveFunction(array $declared): array
    {
        return match (array_key_first($declared)) {
            'sum' => [AggregateFunction::Sum, $this->sum],
            'count' => [AggregateFunction::Count, null],
            'avg' => [AggregateFunction::Avg, $this->avg],
            'min' => [AggregateFunction::Min, $this->min],
            'max' => [AggregateFunction::Max, $this->max],
            default => throw new AggregateConfigurationException(
                'Unreachable: declaredFunctions() returned an unknown key.',
            ),
        };
    }

    private function resolveFilter(): ?FilterPredicate
    {
        $count = ($this->filter !== null ? 1 : 0)
            + ($this->filterNotNull !== null ? 1 : 0)
            + ($this->filterRaw !== null ? 1 : 0);

        if ($count > 1) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": at most one filter form may be declared '
                .'(filter, filterNotNull, filterRaw).',
                $this->column,
            ));
        }

        if ($this->filter !== null) {
            return FilterPredicate::equality($this->filter);
        }

        if ($this->filterNotNull !== null) {
            return FilterPredicate::notNull($this->filterNotNull);
        }

        if ($this->filterRaw !== null) {
            return FilterPredicate::raw($this->filterRaw, $this->filterRawWatches);
        }

        return null;
    }
}
