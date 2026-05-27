<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Attributes;

use Attribute;
use Vusys\NestedSet\Aggregates\Aggregate;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
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
 * Exactly one of `sum | count | avg | min | max | variance | stddev |
 * weightedAvg | boolOr | boolAnd | geometricMean | harmonicMean |
 * bitOr | bitAnd | bitXor | distinctCount | stringAgg | jsonAgg |
 * jsonObjectAgg` must be provided per attribute instance; passing zero
 * or more than one throws
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
     * `filterRawWatches` must list every column the `filterRaw` SQL
     * references; otherwise delta maintenance can't notice a row
     * flipping in/out of the filter and the stored aggregate silently
     * drifts. The check fires at registry build time, so a missing
     * declaration surfaces on app boot rather than as runtime drift.
     *
     * For a genuinely column-free filter (e.g. `'1 = 1'`,
     * `'NOW() > "2000-01-01"'`) set `filterRawNoColumnDependencies: true`.
     * That signal must be explicit — silent empty-watch defaults are
     * the footgun this guard removes.
     *
     * `jsonAgg` accepts a `string` (scalar form), a list of column
     * names (object form keyed by column name), or an assoc array
     * (object form keyed by the array's keys). `jsonObjectAgg` takes
     * a `key` and `value` column name. `distinct: true` switches
     * `stringAgg` to its DISTINCT variant. `limit`, `orderBy`,
     * `separator`, `allowNullKeys` map onto the corresponding
     * {@see Aggregate} factory args. `sample: true` switches
     * `variance` / `stddev` to the sample (`n − 1`) denominator.
     *
     * @param  array<string,mixed>|null  $filter
     * @param  list<string>  $filterRawWatches
     * @param  string|list<string>|array<string,string>|null  $jsonAgg
     * @param  array<string,string>|null  $jsonObjectAgg  expected shape: `['key' => ..., 'value' => ...]` —
     *                                                    validated at definition-build time.
     */
    public function __construct(
        public string $column,
        public ?string $sum = null,
        public bool $count = false,
        public ?string $avg = null,
        public ?string $min = null,
        public ?string $max = null,
        public ?string $variance = null,
        public ?string $stddev = null,
        public bool $sample = false,
        public ?string $bitOr = null,
        public ?string $bitAnd = null,
        public ?string $bitXor = null,
        public ?string $distinctCount = null,
        public ?string $stringAgg = null,
        public string|array|null $jsonAgg = null,
        public ?array $jsonObjectAgg = null,
        public ?string $weightedAvg = null,
        public ?string $weight = null,
        public ?string $boolOr = null,
        public ?string $boolAnd = null,
        public ?string $geometricMean = null,
        public ?string $harmonicMean = null,
        public ?string $median = null,
        public ?string $percentile = null,
        public bool $allowNonPositive = false,
        public bool $exclusive = false,
        public ?array $filter = null,
        public ?string $filterNotNull = null,
        public ?string $filterRaw = null,
        public array $filterRawWatches = [],
        public bool $filterRawNoColumnDependencies = false,
        public string $separator = ', ',
        public ?int $limit = null,
        public ?string $orderBy = null,
        public bool $distinct = false,
        public bool $allowNullKeys = false,
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

        if ($this->median !== null || $this->percentile !== null) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": median() and percentile() are recompute-only '
                .'and cannot be stored as precalculated aggregate columns. '
                .'Use withFreshAggregates() for on-demand quantile reads: '
                .'->withFreshAggregates([\'%s\' => Aggregate::median(\'source\')]).',
                $this->column,
                $this->column,
            ));
        }

        $declared = $this->declaredFunctions();

        if ($declared === []) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": no aggregate function declared. '
                .'Provide exactly one of sum, count, avg, min, max, variance, stddev, '
                .'weightedAvg, boolOr, boolAnd, geometricMean, harmonicMean, '
                .'bitOr, bitAnd, bitXor, '
                .'distinctCount, stringAgg, jsonAgg, jsonObjectAgg.',
                $this->column,
            ));
        }

        if (count($declared) > 1) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": multiple aggregate functions declared (%s). '
                .'Each declaration must use exactly one function.',
                $this->column,
                implode(', ', array_keys($declared)),
            ));
        }

        $function = array_key_first($declared);

        // `sample` only carries meaning for variance/stddev. Reject it
        // on other functions at registry-build time so a stray named
        // argument doesn't silently mean nothing.
        if ($this->sample && $function !== 'variance' && $function !== 'stddev') {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": `sample: true` is only valid on variance/stddev declarations.',
                $this->column,
            ));
        }

        return $this->buildDefinition($function);
    }

    /**
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
        if ($this->variance !== null) {
            $declared['variance'] = $this->variance;
        }
        if ($this->stddev !== null) {
            $declared['stddev'] = $this->stddev;
        }
        if ($this->bitOr !== null) {
            $declared['bitOr'] = $this->bitOr;
        }
        if ($this->bitAnd !== null) {
            $declared['bitAnd'] = $this->bitAnd;
        }
        if ($this->bitXor !== null) {
            $declared['bitXor'] = $this->bitXor;
        }
        if ($this->distinctCount !== null) {
            $declared['distinctCount'] = $this->distinctCount;
        }
        if ($this->stringAgg !== null) {
            $declared['stringAgg'] = $this->stringAgg;
        }
        if ($this->jsonAgg !== null) {
            $declared['jsonAgg'] = $this->jsonAgg;
        }
        if ($this->jsonObjectAgg !== null) {
            $declared['jsonObjectAgg'] = $this->jsonObjectAgg;
        }
        if ($this->weightedAvg !== null) {
            $declared['weightedAvg'] = $this->weightedAvg;
        }
        if ($this->boolOr !== null) {
            $declared['boolOr'] = $this->boolOr;
        }
        if ($this->boolAnd !== null) {
            $declared['boolAnd'] = $this->boolAnd;
        }
        if ($this->geometricMean !== null) {
            $declared['geometricMean'] = $this->geometricMean;
        }
        if ($this->harmonicMean !== null) {
            $declared['harmonicMean'] = $this->harmonicMean;
        }

        return $declared;
    }

    private function buildDefinition(string $functionKey): AggregateDefinition
    {
        return match ($functionKey) {
            'sum' => $this->simpleDefinition(AggregateFunction::Sum, $this->sum),
            'count' => $this->simpleDefinition(AggregateFunction::Count, null),
            'avg' => $this->simpleDefinition(AggregateFunction::Avg, $this->avg),
            'min' => $this->simpleDefinition(AggregateFunction::Min, $this->min),
            'max' => $this->simpleDefinition(AggregateFunction::Max, $this->max),
            'variance' => $this->varianceDefinition(AggregateFunction::Variance, $this->variance),
            'stddev' => $this->varianceDefinition(AggregateFunction::Stddev, $this->stddev),
            'bitOr' => $this->simpleDefinition(AggregateFunction::BitOr, $this->bitOr),
            'bitAnd' => $this->simpleDefinition(AggregateFunction::BitAnd, $this->bitAnd),
            'bitXor' => $this->simpleDefinition(AggregateFunction::BitXor, $this->bitXor),
            'distinctCount' => $this->distinctCountDefinition(),
            'stringAgg' => $this->stringAggDefinition(),
            'jsonAgg' => $this->jsonAggDefinition(),
            'jsonObjectAgg' => $this->jsonObjectAggDefinition(),
            'weightedAvg' => $this->weightedAvgDefinition(),
            'boolOr' => $this->boolRollupDefinition(AggregateFunction::BoolOr, $this->boolOr),
            'boolAnd' => $this->boolRollupDefinition(AggregateFunction::BoolAnd, $this->boolAnd),
            'geometricMean' => $this->meanDefinition(AggregateFunction::GeometricMean, $this->geometricMean),
            'harmonicMean' => $this->meanDefinition(AggregateFunction::HarmonicMean, $this->harmonicMean),
            default => throw new AggregateConfigurationException(
                'Unreachable: declaredFunctions() returned an unknown key.',
            ),
        };
    }

    private function weightedAvgDefinition(): AggregateDefinition
    {
        $value = $this->weightedAvg;
        if ($value === null || $value === '') {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": weightedAvg requires a non-empty value column.',
                $this->column,
            ));
        }

        if ($this->weight === null || $this->weight === '') {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": weightedAvg requires a non-empty `weight` column.',
                $this->column,
            ));
        }

        if ($value === $this->weight) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": weightedAvg value and weight must differ.',
                $this->column,
            ));
        }

        return new AggregateDefinition(
            column: $this->column,
            function: AggregateFunction::WeightedAvg,
            source: $value,
            inclusive: ! $this->exclusive,
            filter: $this->resolveFilter(),
            weight: $this->weight,
        );
    }

    private function boolRollupDefinition(AggregateFunction $function, ?string $source): AggregateDefinition
    {
        if ($source === null || $source === '') {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": %s requires a non-empty column name.',
                $this->column,
                $function->value,
            ));
        }

        return new AggregateDefinition(
            column: $this->column,
            function: $function,
            source: $source,
            inclusive: ! $this->exclusive,
            filter: $this->resolveFilter(),
        );
    }

    private function meanDefinition(AggregateFunction $function, ?string $source): AggregateDefinition
    {
        if ($source === null || $source === '') {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": %s requires a non-empty column name.',
                $this->column,
                $function->value,
            ));
        }

        return new AggregateDefinition(
            column: $this->column,
            function: $function,
            source: $source,
            inclusive: ! $this->exclusive,
            filter: $this->resolveFilter(),
            allowNonPositive: $this->allowNonPositive,
        );
    }

    private function simpleDefinition(AggregateFunction $function, ?string $source): AggregateDefinition
    {
        return new AggregateDefinition(
            column: $this->column,
            function: $function,
            source: $source,
            inclusive: ! $this->exclusive,
            filter: $this->resolveFilter(),
        );
    }

    private function varianceDefinition(AggregateFunction $function, ?string $source): AggregateDefinition
    {
        return new AggregateDefinition(
            column: $this->column,
            function: $function,
            source: $source,
            inclusive: ! $this->exclusive,
            filter: $this->resolveFilter(),
            sample: $this->sample,
        );
    }

    private function distinctCountDefinition(): AggregateDefinition
    {
        $source = $this->distinctCount;
        if ($source === null || $source === '') {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": distinctCount requires a non-empty column name.',
                $this->column,
            ));
        }

        return new AggregateDefinition(
            column: $this->column,
            function: AggregateFunction::DistinctCount,
            source: $source,
            inclusive: ! $this->exclusive,
            filter: $this->resolveFilter(),
        );
    }

    private function stringAggDefinition(): AggregateDefinition
    {
        $source = $this->stringAgg;
        if ($source === null || $source === '') {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": stringAgg requires a non-empty column name.',
                $this->column,
            ));
        }

        if ($this->limit !== null && $this->limit < 0) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": limit must be >= 0.',
                $this->column,
            ));
        }

        $orderBy = $this->orderBy ?? $source;

        if ($this->distinct && $orderBy !== $source) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": stringAgg with distinct: true requires '
                .'orderBy to match the source column (PG only accepts ORDER BY columns that '
                .'appear in the DISTINCT set; the package enforces this across backends).',
                $this->column,
            ));
        }

        return new AggregateDefinition(
            column: $this->column,
            function: AggregateFunction::StringAgg,
            source: $source,
            inclusive: ! $this->exclusive,
            filter: $this->resolveFilter(),
            separator: $this->separator,
            limit: $this->limit,
            orderBy: $orderBy,
            distinct: $this->distinct,
        );
    }

    private function jsonAggDefinition(): AggregateDefinition
    {
        if ($this->limit !== null && $this->limit < 0) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": limit must be >= 0.',
                $this->column,
            ));
        }

        $source = $this->jsonAgg;

        if (is_string($source)) {
            if ($source === '') {
                throw new AggregateConfigurationException(sprintf(
                    'NestedSetAggregate for column "%s": jsonAgg source column must not be empty.',
                    $this->column,
                ));
            }

            return new AggregateDefinition(
                column: $this->column,
                function: AggregateFunction::JsonAgg,
                source: $source,
                inclusive: ! $this->exclusive,
                filter: $this->resolveFilter(),
                limit: $this->limit,
                orderBy: $this->orderBy ?? $source,
            );
        }

        if (! is_array($source)) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": jsonAgg must be a string, list of columns, or assoc array.',
                $this->column,
            ));
        }

        $sources = $this->normaliseJsonAggSource($source);

        return new AggregateDefinition(
            column: $this->column,
            function: AggregateFunction::JsonAgg,
            source: null,
            inclusive: ! $this->exclusive,
            filter: $this->resolveFilter(),
            limit: $this->limit,
            orderBy: $this->orderBy,
            sources: $sources,
        );
    }

    private function jsonObjectAggDefinition(): AggregateDefinition
    {
        $spec = $this->jsonObjectAgg;
        if ($spec === null || ! isset($spec['key'], $spec['value'])) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": jsonObjectAgg requires `[\'key\' => …, \'value\' => …]`.',
                $this->column,
            ));
        }

        $key = $spec['key'];
        $value = $spec['value'];

        if ($key === '' || $value === '') {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": jsonObjectAgg key and value must be non-empty strings.',
                $this->column,
            ));
        }

        if ($this->limit !== null && $this->limit < 0) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": limit must be >= 0.',
                $this->column,
            ));
        }

        return new AggregateDefinition(
            column: $this->column,
            function: AggregateFunction::JsonObjectAgg,
            source: null,
            inclusive: ! $this->exclusive,
            filter: $this->resolveFilter(),
            limit: $this->limit,
            orderBy: $this->orderBy ?? $key,
            allowNullKeys: $this->allowNullKeys,
            keyColumn: $key,
            valueColumn: $value,
        );
    }

    /**
     * @param  list<string>|array<string,string>  $source
     * @return array<string,string>
     */
    private function normaliseJsonAggSource(array $source): array
    {
        if ($source === []) {
            throw new AggregateConfigurationException(sprintf(
                'NestedSetAggregate for column "%s": jsonAgg source array must not be empty.',
                $this->column,
            ));
        }

        $isList = array_is_list($source);
        $result = [];

        foreach ($source as $jsonKey => $column) {
            if ($column === '') {
                throw new AggregateConfigurationException(sprintf(
                    'NestedSetAggregate for column "%s": jsonAgg source columns must be non-empty strings.',
                    $this->column,
                ));
            }

            $key = $isList ? $column : (string) $jsonKey;

            if ($key === '') {
                throw new AggregateConfigurationException(sprintf(
                    'NestedSetAggregate for column "%s": jsonAgg JSON keys must not be empty strings.',
                    $this->column,
                ));
            }

            if (array_key_exists($key, $result)) {
                throw new AggregateConfigurationException(sprintf(
                    'NestedSetAggregate for column "%s": duplicate jsonAgg JSON key "%s".',
                    $this->column,
                    $key,
                ));
            }

            $result[$key] = $column;
        }

        return $result;
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
            if ($this->filterRawWatches === [] && ! $this->filterRawNoColumnDependencies) {
                throw new AggregateConfigurationException(sprintf(
                    'NestedSetAggregate for column "%s": `filterRaw` is set but `filterRawWatches` is empty. '
                    .'List every column the SQL references so delta maintenance triggers a recompute when one '
                    .'changes; otherwise the aggregate will silently drift. For a genuinely column-free '
                    .'predicate, set `filterRawNoColumnDependencies: true` to opt out explicitly.',
                    $this->column,
                ));
            }

            return FilterPredicate::raw($this->filterRaw, $this->filterRawWatches);
        }

        return null;
    }
}
