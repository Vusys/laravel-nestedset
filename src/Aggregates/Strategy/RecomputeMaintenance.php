<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Strategy;

use Illuminate\Database\Connection;
use Vusys\NestedSet\Aggregates\AggregateDefinition;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\AggregateSqlEmitter;
use Vusys\NestedSet\Aggregates\CompanionSourceTransform;
use Vusys\NestedSet\Aggregates\FilterPredicate;
use Vusys\NestedSet\Aggregates\FilterPredicateKind;
use Vusys\NestedSet\Aggregates\FilterValueQuoter;
use Vusys\NestedSet\Aggregates\SqliteBitwiseAggregates;
use Vusys\NestedSet\Aggregates\VarianceSqlFragments;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;
use Vusys\NestedSet\NodeBounds;

/**
 * Phase F: recomputes MIN / MAX for each ancestor whose stored
 * extremum may have been invalidated by the change.
 *
 * Strategy: two-step SELECT-then-UPDATE rather than a single
 * correlated-subquery UPDATE. The latter is the cleanest expression
 * (AGGREGATES.md §5.2) but trips MySQL's "you can't update a table
 * and select from it in a subquery" rule. SELECT + UPDATE works on
 * every supported backend; the SELECT is one round-trip that returns
 * every affected ancestor's recomputed value per declared column.
 *
 * Cheap-skip: callers pass `$filterEquals` — pairs of
 * `(aggregate_column, previous_value)` ORed together. Ancestors whose
 * stored extremum demonstrably did not match the changing/deleted
 * value are not selected and therefore not touched. This is what
 * makes "delete a non-extremum value → no recompute" possible.
 */
final class RecomputeMaintenance
{
    /**
     * @param  list<array{column: string, function: AggregateFunction, source: string, inclusive: bool, filter?: FilterPredicate|null, sample?: bool, sourceTransform?: CompanionSourceTransform, definition?: AggregateDefinition|null}>  $columns
     * @param  array<string, mixed>  $scope
     * @param  array<string, int|float|string>  $filterEquals
     *                                                         column => previous_value pairs ORed into the WHERE.
     *                                                         Empty → no extra filter (every ancestor recomputes).
     * @param  'always'|'auto'|'never'  $locking
     *                                            Controls whether the recompute SELECT is issued with
     *                                            FOR UPDATE. 'always' and 'auto' both lock here; 'never'
     *                                            skips.
     * @param  NodeBounds|null  $excludeBounds
     *                                          When set, the inner MIN/MAX subquery excludes rows whose
     *                                          lft/rgt fall inside these bounds. Used by the Path A
     *                                          before-move hook so the recompute reflects the
     *                                          post-move-but-pre-SQL state: A1 (the moving node and
     *                                          its descendants) is removed from the subtree scan even
     *                                          though it's still physically in the table.
     */
    public static function apply(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        NodeBounds $bounds,
        array $columns,
        array $scope = [],
        array $filterEquals = [],
        string $locking = 'auto',
        ?NodeBounds $excludeBounds = null,
        ?string $softDeletedColumn = null,
        string $idCol = 'id',
    ): int {
        if ($columns === []) {
            return 0;
        }

        // Bitwise rollups rely on BIT_OR / BIT_AND / BIT_XOR aggregate
        // functions. SQLite has no natives; install the UDAs lazily so
        // recomputes triggered before the ConnectionEstablished
        // listener fires still resolve them.
        foreach ($columns as $column) {
            if (in_array($column['function'], [
                AggregateFunction::BitOr,
                AggregateFunction::BitAnd,
                AggregateFunction::BitXor,
            ], true)) {
                SqliteBitwiseAggregates::ensureInstalled($connection);

                break;
            }
        }

        $candidates = self::candidatesForRecompute(
            connection: $connection,
            table: $table,
            lftCol: $lftCol,
            rgtCol: $rgtCol,
            bounds: $bounds,
            columns: $columns,
            scope: $scope,
            filterEquals: $filterEquals,
            locking: $locking,
            excludeBounds: $excludeBounds,
            softDeletedColumn: $softDeletedColumn,
            idCol: $idCol,
        );

        if ($candidates === []) {
            return 0;
        }

        return self::writeRecomputedValues(
            connection: $connection,
            table: $table,
            columns: $columns,
            candidates: $candidates,
            idCol: $idCol,
        );
    }

    /**
     * @param  list<array{column: string, function: AggregateFunction, source: string, inclusive: bool, filter?: FilterPredicate|null, sample?: bool, sourceTransform?: CompanionSourceTransform, definition?: AggregateDefinition|null}>  $columns
     * @param  array<string, mixed>  $scope
     * @param  array<string, int|float|string>  $filterEquals
     * @param  'always'|'auto'|'never'  $locking
     * @return list<array<string, mixed>>
     */
    private static function candidatesForRecompute(
        Connection $connection,
        string $table,
        string $lftCol,
        string $rgtCol,
        NodeBounds $bounds,
        array $columns,
        array $scope,
        array $filterEquals,
        string $locking,
        ?NodeBounds $excludeBounds = null,
        ?string $softDeletedColumn = null,
        string $idCol = 'id',
    ): array {
        $selects = ["outer_a.{$idCol} AS id"];

        $exclusionClause = $excludeBounds instanceof NodeBounds
            ? " AND NOT (inner_a.{$lftCol} >= {$excludeBounds->lft} AND inner_a.{$rgtCol} <= {$excludeBounds->rgt})"
            : '';

        // Snapshot semantics: ignore soft-deleted rows on both sides
        // of the recompute. Outer: trashed ancestors stay frozen.
        // Inner: trashed descendants don't contribute to the sum /
        // min / max / count over the subtree.
        $softInner = $softDeletedColumn !== null
            ? " AND inner_a.{$softDeletedColumn} IS NULL"
            : '';
        $softOuter = $softDeletedColumn !== null
            ? " AND outer_a.{$softDeletedColumn} IS NULL"
            : '';

        foreach ($columns as $i => $spec) {
            $alias = self::recomputeAlias($i);
            $boundsClause = $spec['inclusive']
                ? "inner_a.{$lftCol} >= outer_a.{$lftCol} AND inner_a.{$rgtCol} <= outer_a.{$rgtCol}"
                : "inner_a.{$lftCol} > outer_a.{$lftCol} AND inner_a.{$rgtCol} < outer_a.{$rgtCol}";

            $scopeJoin = '';
            foreach (array_keys($scope) as $col) {
                $scopeJoin .= " AND inner_a.{$col} = outer_a.{$col}";
            }

            $filterPredicate = $spec['filter'] ?? null;
            $aggExpr = self::innerAggregateExpression($connection, $spec, $filterPredicate);

            $selects[] = "(SELECT {$aggExpr} FROM {$table} AS inner_a "
                ."WHERE {$boundsClause}{$scopeJoin}{$exclusionClause}{$softInner}) AS {$alias}";
        }

        $where = "outer_a.{$lftCol} <= ? AND outer_a.{$rgtCol} >= ?";
        $bindings = [$bounds->lft, $bounds->rgt];

        if ($excludeBounds instanceof NodeBounds) {
            // Outer-side counterpart of the inner exclusion. For the
            // move-before-hook this skips self + moving-subtree rows so
            // they aren't recomputed against an empty inner result set
            // (which would set their stored extremum to NULL).
            $where .= " AND NOT (outer_a.{$lftCol} >= ? AND outer_a.{$rgtCol} <= ?)";
            $bindings[] = $excludeBounds->lft;
            $bindings[] = $excludeBounds->rgt;
        }

        foreach ($scope as $col => $value) {
            $where .= " AND outer_a.{$col} = ?";
            $bindings[] = $value;
        }

        $where .= $softOuter;

        if ($filterEquals !== []) {
            $parts = [];
            foreach ($filterEquals as $col => $value) {
                $parts[] = "outer_a.{$col} = ?";
                $bindings[] = $value;
            }
            $where .= ' AND ('.implode(' OR ', $parts).')';
        }

        $sql = 'SELECT '.implode(', ', $selects)
            ." FROM {$table} AS outer_a WHERE {$where}";

        if ($locking !== 'never') {
            $sql .= self::forUpdateClause($connection);
        }

        $rows = $connection->select($sql, $bindings);

        $result = [];
        foreach ($rows as $row) {
            $result[] = (array) $row;
        }

        return $result;
    }

    /**
     * SQLite has no row-level locking — `FOR UPDATE` is a no-op there
     * and produces a syntax error. Detect SQLite and skip; on the
     * other three backends, append the standard SQL clause.
     */
    private static function forUpdateClause(Connection $connection): string
    {
        if ($connection->getDriverName() === 'sqlite') {
            return '';
        }

        return ' FOR UPDATE';
    }

    /**
     * @param  list<array{column: string, function: AggregateFunction, source: string, inclusive: bool, filter?: FilterPredicate|null, sample?: bool, sourceTransform?: CompanionSourceTransform, definition?: AggregateDefinition|null}>  $columns
     * @param  list<array<string, mixed>>  $candidates
     */
    private static function writeRecomputedValues(
        Connection $connection,
        string $table,
        array $columns,
        array $candidates,
        string $idCol = 'id',
    ): int {
        $touched = 0;

        foreach ($candidates as $row) {
            $updates = [];
            foreach ($columns as $i => $spec) {
                $alias = self::recomputeAlias($i);
                $updates[$spec['column']] = $row[$alias] ?? null;
            }

            $id = $row['id'] ?? null;
            if ($id === null) {
                continue;
            }

            $touched += $connection->table($table)
                ->where($idCol, '=', $id)
                ->update($updates);
        }

        return $touched;
    }

    private static function recomputeAlias(int $index): string
    {
        return 'recompute_'.$index;
    }

    /**
     * Builds the inner SUM/COUNT/AVG/MIN/MAX/VARIANCE/STDDEV expression
     * for the recompute subquery — wrapping the source column reference
     * in a `CASE WHEN <filter> THEN … ELSE …` when a filter is present.
     *
     * Honours the column spec's `sourceTransform` (today: `Identity` or
     * `Square`) so the SumSq companion of Variance / Stddev sums the
     * squared source values; the `sample` flag picks between the
     * `n²` and `n(n−1)` denominators in the variance formula.
     *
     * The collection-aggregate kinds (DistinctCount / StringAgg /
     * JsonAgg / JsonObjectAgg) route through {@see AggregateSqlEmitter}
     * for backend-specific SQL — the spec carries an `AggregateDefinition`
     * reference for those.
     *
     * @param  array{column: string, function: AggregateFunction, source: string, inclusive: bool, filter?: FilterPredicate|null, sample?: bool, sourceTransform?: CompanionSourceTransform, definition?: AggregateDefinition|null}  $spec
     */
    private static function innerAggregateExpression(Connection $connection, array $spec, ?FilterPredicate $filter): string
    {
        $source = $spec['source'];
        $sourceRef = "inner_a.{$source}";
        // Companions with a non-Identity transform (square for
        // variance/stddev, weight*value for weightedAvg, bool-as-int
        // for boolOr/boolAnd) compile the SUM around a derived
        // expression instead of a plain column ref. The transform
        // comes from either `$spec['sourceTransform']` (existing
        // variance call sites) or the definition's `sourceTransform`
        // (companion paths that carry a full definition); falls back
        // to Identity for non-companion specs.
        $definition = $spec['definition'] ?? null;
        $sourceTransform = $spec['sourceTransform']
            ?? ($definition instanceof AggregateDefinition
                ? $definition->sourceTransform
                : CompanionSourceTransform::Identity);
        $weightRef = $definition instanceof AggregateDefinition
            && $definition->weight !== null
            && $definition->weight !== ''
            ? 'inner_a.'.$definition->weight
            : null;
        $sourceExpression = $sourceTransform->applySqlFragment($sourceRef, $weightRef);
        $sample = $spec['sample'] ?? false;

        if ($filter instanceof FilterPredicate) {
            $pred = self::filterPredicateSql($connection, $filter, 'inner_a.');

            return match ($spec['function']) {
                AggregateFunction::Sum => sprintf(
                    'COALESCE(SUM(CASE WHEN %s THEN %s ELSE 0 END), 0)',
                    $pred,
                    $sourceExpression,
                ),
                AggregateFunction::Count => sprintf(
                    // COUNT(NULL) and COUNT(expr-returning-NULL) both yield 0
                    // — wrap in a CASE that returns 1 / NULL to match
                    // COUNT(*)-semantics-with-filter as well as
                    // COUNT(col)-with-filter (NULL source already produces
                    // NULL via the CASE branch). Use $sourceExpression so
                    // non-Identity Count companions (Ln for GeometricMean,
                    // Recip for HarmonicMean) only count rows whose transform
                    // produces a non-NULL contribution.
                    'COUNT(CASE WHEN %s THEN %s ELSE NULL END)',
                    $pred,
                    $spec['source'] === '' ? '1' : $sourceExpression,
                ),
                AggregateFunction::Avg => sprintf(
                    'AVG(CASE WHEN %s THEN %s ELSE NULL END)',
                    $pred,
                    $sourceRef,
                ),
                AggregateFunction::Min => sprintf(
                    'MIN(CASE WHEN %s THEN %s ELSE NULL END)',
                    $pred,
                    $sourceRef,
                ),
                AggregateFunction::Max => sprintf(
                    'MAX(CASE WHEN %s THEN %s ELSE NULL END)',
                    $pred,
                    $sourceRef,
                ),
                AggregateFunction::Variance => self::filteredVarianceFragment($sourceRef, $pred, $sample, stddev: false),
                AggregateFunction::Stddev => self::filteredVarianceFragment($sourceRef, $pred, $sample, stddev: true),
                AggregateFunction::BitOr,
                AggregateFunction::BitAnd,
                AggregateFunction::BitXor => sprintf(
                    '%s(CASE WHEN %s THEN %s ELSE NULL END)',
                    self::bitwiseFunctionName($spec['function']),
                    $pred,
                    $sourceRef,
                ),
                AggregateFunction::WeightedAvg,
                AggregateFunction::BoolOr,
                AggregateFunction::BoolAnd,
                AggregateFunction::GeometricMean,
                AggregateFunction::HarmonicMean,
                AggregateFunction::Median,
                AggregateFunction::Percentile => throw new AggregateConfigurationException(sprintf(
                    'RecomputeMaintenance: %s display columns are derived from companion sums + counts '
                    .'in DeltaMaintenance and should never reach this inner-expression builder.',
                    strtoupper($spec['function']->value),
                )),
                AggregateFunction::DistinctCount,
                AggregateFunction::StringAgg,
                AggregateFunction::JsonAgg,
                AggregateFunction::JsonObjectAgg => AggregateSqlEmitter::emit(
                    $connection,
                    self::requireDefinitionFromSpec($spec),
                    'inner_a.',
                    $pred,
                ),
            };
        }

        return match ($spec['function']) {
            AggregateFunction::Sum => "COALESCE(SUM({$sourceExpression}), 0)",
            AggregateFunction::Count => $spec['source'] === ''
                ? 'COUNT(*)'
                : "COUNT({$sourceExpression})",
            AggregateFunction::Avg => "AVG({$sourceRef})",
            AggregateFunction::Min => "MIN({$sourceRef})",
            AggregateFunction::Max => "MAX({$sourceRef})",
            AggregateFunction::Variance => VarianceSqlFragments::variance(
                sumExpr: "SUM({$sourceRef})",
                sumSqExpr: "SUM({$sourceRef} * {$sourceRef})",
                countExpr: "COUNT({$sourceRef})",
                sample: $sample,
            ),
            AggregateFunction::Stddev => VarianceSqlFragments::stddev(
                sumExpr: "SUM({$sourceRef})",
                sumSqExpr: "SUM({$sourceRef} * {$sourceRef})",
                countExpr: "COUNT({$sourceRef})",
                sample: $sample,
            ),
            AggregateFunction::BitOr,
            AggregateFunction::BitAnd,
            AggregateFunction::BitXor => sprintf(
                '%s(%s)',
                self::bitwiseFunctionName($spec['function']),
                $sourceRef,
            ),
            AggregateFunction::WeightedAvg,
            AggregateFunction::BoolOr,
            AggregateFunction::BoolAnd,
            AggregateFunction::GeometricMean,
            AggregateFunction::HarmonicMean,
            AggregateFunction::Median,
            AggregateFunction::Percentile => throw new AggregateConfigurationException(sprintf(
                'RecomputeMaintenance: %s display columns are derived from companion sums + counts '
                .'in DeltaMaintenance and should never reach this inner-expression builder.',
                strtoupper($spec['function']->value),
            )),
            AggregateFunction::DistinctCount,
            AggregateFunction::StringAgg,
            AggregateFunction::JsonAgg,
            AggregateFunction::JsonObjectAgg => AggregateSqlEmitter::emit(
                $connection,
                self::requireDefinitionFromSpec($spec),
                'inner_a.',
            ),
        };
    }

    private static function bitwiseFunctionName(AggregateFunction $function): string
    {
        return match ($function) {
            AggregateFunction::BitOr => 'BIT_OR',
            AggregateFunction::BitAnd => 'BIT_AND',
            AggregateFunction::BitXor => 'BIT_XOR',
            default => throw new AggregateConfigurationException(
                'bitwiseFunctionName(): not a bitwise aggregate function: '.$function->value,
            ),
        };
    }

    /**
     * @param  array{column: string, function: AggregateFunction, source: string, inclusive: bool, filter?: FilterPredicate|null, sample?: bool, sourceTransform?: CompanionSourceTransform, definition?: AggregateDefinition|null}  $spec
     */
    private static function requireDefinitionFromSpec(array $spec): AggregateDefinition
    {
        $definition = $spec['definition'] ?? null;
        if ($definition === null) {
            throw new AggregateConfigurationException(sprintf(
                'RecomputeMaintenance: spec for %s aggregate "%s" is missing the AggregateDefinition reference '
                .'required for backend-specific SQL emission.',
                $spec['function']->value,
                $spec['column'],
            ));
        }

        return $definition;
    }

    /**
     * Filtered companion fragment for variance / stddev recompute. Each
     * Sum / Count subexpression is wrapped in a CASE that returns NULL
     * on non-matching rows so the inner aggregates ignore them — same
     * shape the unfiltered fragments produce naturally.
     */
    private static function filteredVarianceFragment(
        string $sourceRef,
        string $pred,
        bool $sample,
        bool $stddev,
    ): string {
        $sumExpr = sprintf('SUM(CASE WHEN %s THEN %s ELSE NULL END)', $pred, $sourceRef);
        $sumSqExpr = sprintf('SUM(CASE WHEN %s THEN %s * %s ELSE NULL END)', $pred, $sourceRef, $sourceRef);
        $countExpr = sprintf('COUNT(CASE WHEN %s THEN %s ELSE NULL END)', $pred, $sourceRef);

        return $stddev
            ? VarianceSqlFragments::stddev($sumExpr, $sumSqExpr, $countExpr, $sample)
            : VarianceSqlFragments::variance($sumExpr, $sumSqExpr, $countExpr, $sample);
    }

    private static function filterPredicateSql(Connection $connection, FilterPredicate $filter, string $qualifier): string
    {
        return match ($filter->getKind()) {
            FilterPredicateKind::Equality => implode(' AND ', array_map(
                static function (string $col, mixed $value) use ($connection, $qualifier): string {
                    if ($value === null) {
                        return "{$qualifier}{$col} IS NULL";
                    }

                    return "{$qualifier}{$col} = ".FilterValueQuoter::quote($connection, $value);
                },
                array_keys($filter->getConditions()),
                array_values($filter->getConditions()),
            )),
            FilterPredicateKind::NotNull => sprintf(
                '%s%s IS NOT NULL',
                $qualifier,
                (string) $filter->getNotNullColumn(),
            ),
            FilterPredicateKind::Raw => $filter->getRawSql() ?? throw new AggregateConfigurationException(
                'FilterPredicate of kind Raw has a null rawSql — this should never happen.',
            ),
        };
    }
}
