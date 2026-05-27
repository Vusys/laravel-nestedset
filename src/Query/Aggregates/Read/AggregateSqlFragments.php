<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query\Aggregates\Read;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceTransform;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicate;
use Vusys\NestedSet\Aggregates\Filters\FilterPredicateKind;
use Vusys\NestedSet\Aggregates\Filters\FilterValueQuoter;
use Vusys\NestedSet\Aggregates\Sql\AggregateSqlEmitter;
use Vusys\NestedSet\Aggregates\Sql\DerivedAggregateFragments;
use Vusys\NestedSet\Aggregates\Sql\SqliteBitwiseAggregates;
use Vusys\NestedSet\Aggregates\Sql\VarianceSqlFragments;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * Per-aggregate-kind SQL expression builders.
 *
 * Given a {@see AggregateDefinition} and a column qualifier (`"d."`,
 * empty, etc.), returns the SQL fragment the outer caller wraps into a
 * SELECT, a derived JOIN, or a LATERAL body. Centralises the kind-by-kind
 * branch ladders — Sum/Count/Avg/Min/Max use uniform `f(qualifier·src)`
 * expressions; Variance/Stddev defer to {@see VarianceSqlFragments};
 * WeightedAvg / Bool / Geometric / Harmonic defer to
 * {@see DerivedAggregateFragments}; quantiles and collection aggregates
 * defer to {@see AggregateSqlEmitter}.
 *
 * Also owns the joined-context handling for raw-filter aggregates (the
 * renamed-derived FROM clause + inline expressions) and the leaf
 * fast-path wrapping (`CASE WHEN rgt = lft + 1 THEN <inline> ELSE
 * <join> END`).
 *
 * Driver detection helpers ({@see isMariaDb()}, {@see isMySql()}) live
 * here too because callers picking a SQL shape need them right next to
 * the shape selection.
 */
final class AggregateSqlFragments
{
    public static function aggregateExpression(AggregateDefinition $definition, string $qualifier, ?Connection $connection = null): string
    {
        if ($definition->filter instanceof FilterPredicate) {
            if (! $connection instanceof Connection) {
                throw new AggregateConfigurationException(
                    'Filtered aggregate expressions require a connection for safe value quoting.',
                );
            }

            return self::filteredAggregateExpression($connection, $definition, $qualifier, $definition->filter);
        }

        return match ($definition->function) {
            AggregateFunction::Sum => sprintf(
                'COALESCE(SUM(%s), 0)',
                self::sumBody($definition, $qualifier),
            ),
            AggregateFunction::Count => $definition->source === null
                ? 'COUNT(*)'
                : sprintf('COUNT(%s%s)', $qualifier, $definition->source),
            AggregateFunction::Avg => sprintf(
                'AVG(%s%s)',
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Min => sprintf(
                'MIN(%s%s)',
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Max => sprintf(
                'MAX(%s%s)',
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Variance => VarianceSqlFragments::variance(
                sumExpr: sprintf('SUM(%s%s)', $qualifier, self::requireSource($definition)),
                sumSqExpr: sprintf('SUM(%s%s * %s%s)', $qualifier, self::requireSource($definition), $qualifier, $definition->source),
                countExpr: sprintf('COUNT(%s%s)', $qualifier, self::requireSource($definition)),
                sample: $definition->sample,
            ),
            AggregateFunction::Stddev => VarianceSqlFragments::stddev(
                sumExpr: sprintf('SUM(%s%s)', $qualifier, self::requireSource($definition)),
                sumSqExpr: sprintf('SUM(%s%s * %s%s)', $qualifier, self::requireSource($definition), $qualifier, $definition->source),
                countExpr: sprintf('COUNT(%s%s)', $qualifier, self::requireSource($definition)),
                sample: $definition->sample,
            ),
            AggregateFunction::BitOr,
            AggregateFunction::BitAnd,
            AggregateFunction::BitXor => sprintf(
                '%s(%s%s)',
                self::bitwiseFunctionName($definition->function),
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::WeightedAvg,
            AggregateFunction::BoolOr,
            AggregateFunction::BoolAnd,
            AggregateFunction::GeometricMean,
            AggregateFunction::HarmonicMean => DerivedAggregateFragments::build($definition, $qualifier),
            AggregateFunction::Median,
            AggregateFunction::Percentile => AggregateSqlEmitter::emitQuantileNativeExpression($definition, $qualifier),
            AggregateFunction::DistinctCount,
            AggregateFunction::StringAgg,
            AggregateFunction::JsonAgg,
            AggregateFunction::JsonObjectAgg => AggregateSqlEmitter::emit(
                self::requireConnection($connection, $definition),
                $definition,
                $qualifier,
            ),
        };
    }

    /**
     * SQL aggregate-function name for a bitwise rollup. Uniform across
     * every supported backend — MySQL, MariaDB, and PG ≥ 14 have all
     * three natively; SQLite gets them via the UDAs registered in
     * {@see SqliteBitwiseAggregates::ensureInstalled()}.
     */
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

    private static function requireConnection(?Connection $connection, AggregateDefinition $definition): Connection
    {
        if (! $connection instanceof Connection) {
            throw new AggregateConfigurationException(sprintf(
                'Aggregate "%s" (%s) requires a connection — its SQL is backend-specific.',
                $definition->column,
                $definition->function->value,
            ));
        }

        return $connection;
    }

    private static function filteredAggregateExpression(
        Connection $connection,
        AggregateDefinition $definition,
        string $qualifier,
        FilterPredicate $filter,
    ): string {
        $pred = self::filterPredicateSql($connection, $filter, $qualifier);

        return match ($definition->function) {
            AggregateFunction::Sum => sprintf(
                'COALESCE(SUM(CASE WHEN %s THEN %s ELSE 0 END), 0)',
                $pred,
                self::sumBody($definition, $qualifier),
            ),
            AggregateFunction::Count => $definition->source === null
                ? sprintf('COUNT(CASE WHEN %s THEN 1 ELSE NULL END)', $pred)
                : sprintf(
                    'COUNT(CASE WHEN %s THEN %s%s ELSE NULL END)',
                    $pred,
                    $qualifier,
                    $definition->source,
                ),
            AggregateFunction::Avg => sprintf(
                'AVG(CASE WHEN %s THEN %s%s ELSE NULL END)',
                $pred,
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Min => sprintf(
                'MIN(CASE WHEN %s THEN %s%s ELSE NULL END)',
                $pred,
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Max => sprintf(
                'MAX(CASE WHEN %s THEN %s%s ELSE NULL END)',
                $pred,
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Variance => self::filteredVarianceFragment($definition, $qualifier, $pred, stddev: false),
            AggregateFunction::Stddev => self::filteredVarianceFragment($definition, $qualifier, $pred, stddev: true),
            AggregateFunction::BitOr,
            AggregateFunction::BitAnd,
            AggregateFunction::BitXor => sprintf(
                '%s(CASE WHEN %s THEN %s%s ELSE NULL END)',
                self::bitwiseFunctionName($definition->function),
                $pred,
                $qualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::WeightedAvg,
            AggregateFunction::BoolOr,
            AggregateFunction::BoolAnd,
            AggregateFunction::GeometricMean,
            AggregateFunction::HarmonicMean => DerivedAggregateFragments::build($definition, $qualifier, $pred),
            AggregateFunction::Median,
            AggregateFunction::Percentile => AggregateSqlEmitter::emitQuantileNativeExpression($definition, $qualifier, $pred),
            AggregateFunction::DistinctCount,
            AggregateFunction::StringAgg,
            AggregateFunction::JsonAgg,
            AggregateFunction::JsonObjectAgg => AggregateSqlEmitter::emit(
                $connection,
                $definition,
                $qualifier,
                $pred,
            ),
        };
    }

    /**
     * Build a filtered variance / stddev fragment from a CASE-wrapped
     * source. Each companion subexpression evaluates to NULL on
     * filtered-out rows so SUM ignores them and COUNT excludes them —
     * exactly the behaviour the unfiltered case relies on, just with
     * the CASE wrapper in front.
     */
    private static function filteredVarianceFragment(
        AggregateDefinition $definition,
        string $qualifier,
        string $pred,
        bool $stddev,
    ): string {
        $source = self::requireSource($definition);
        $sourceRef = $qualifier.$source;

        $sumExpr = sprintf('SUM(CASE WHEN %s THEN %s ELSE NULL END)', $pred, $sourceRef);
        $sumSqExpr = sprintf('SUM(CASE WHEN %s THEN %s * %s ELSE NULL END)', $pred, $sourceRef, $sourceRef);
        $countExpr = sprintf('COUNT(CASE WHEN %s THEN %s ELSE NULL END)', $pred, $sourceRef);

        return $stddev
            ? VarianceSqlFragments::stddev($sumExpr, $sumSqExpr, $countExpr, $definition->sample)
            : VarianceSqlFragments::variance($sumExpr, $sumSqExpr, $countExpr, $definition->sample);
    }

    public static function filterPredicateSql(Connection $connection, FilterPredicate $filter, string $qualifier): string
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

    /**
     * Inline raw-filter aggregate expression. Used when the caller
     * arranges for the outer-table alias to expose its columns under
     * renamed identifiers (see {@see renamedOuterColumn()}) so bare
     * column references inside `$rawFilter` resolve unambiguously to
     * the inner `$innerQualifier` table at SQL-parse time.
     *
     * The inner source column is qualified with `$innerQualifier` so
     * the CASE WHEN body always picks the inner row. Equivalent in
     * runtime cost to the unfiltered inline aggregate — both ride the
     * same STRAIGHT_JOIN'd covering range scan.
     */
    private static function inlineRawFilterExpression(
        AggregateDefinition $definition,
        string $rawFilter,
        string $innerQualifier,
        ?Connection $connection = null,
    ): string {
        return match ($definition->function) {
            AggregateFunction::Sum => sprintf(
                'COALESCE(SUM(CASE WHEN %s THEN %s ELSE 0 END), 0)',
                $rawFilter,
                self::sumBody($definition, $innerQualifier),
            ),
            AggregateFunction::Count => $definition->source === null
                ? sprintf('COUNT(CASE WHEN %s THEN 1 ELSE NULL END)', $rawFilter)
                : sprintf(
                    'COUNT(CASE WHEN %s THEN %s%s ELSE NULL END)',
                    $rawFilter,
                    $innerQualifier,
                    $definition->source,
                ),
            AggregateFunction::Avg => sprintf(
                'AVG(CASE WHEN %s THEN %s%s ELSE NULL END)',
                $rawFilter,
                $innerQualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Min => sprintf(
                'MIN(CASE WHEN %s THEN %s%s ELSE NULL END)',
                $rawFilter,
                $innerQualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Max => sprintf(
                'MAX(CASE WHEN %s THEN %s%s ELSE NULL END)',
                $rawFilter,
                $innerQualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::Variance => self::filteredVarianceFragment($definition, $innerQualifier, $rawFilter, stddev: false),
            AggregateFunction::Stddev => self::filteredVarianceFragment($definition, $innerQualifier, $rawFilter, stddev: true),
            AggregateFunction::BitOr,
            AggregateFunction::BitAnd,
            AggregateFunction::BitXor => sprintf(
                '%s(CASE WHEN %s THEN %s%s ELSE NULL END)',
                self::bitwiseFunctionName($definition->function),
                $rawFilter,
                $innerQualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::WeightedAvg,
            AggregateFunction::BoolOr,
            AggregateFunction::BoolAnd,
            AggregateFunction::GeometricMean,
            AggregateFunction::HarmonicMean => DerivedAggregateFragments::build($definition, $innerQualifier, $rawFilter),
            AggregateFunction::Median,
            AggregateFunction::Percentile => throw new \LogicException(
                'Median/Percentile cannot appear in inlineRawFilterExpression() — they are pre-extracted as correlated subqueries.',
            ),
            AggregateFunction::DistinctCount,
            AggregateFunction::StringAgg,
            AggregateFunction::JsonAgg,
            AggregateFunction::JsonObjectAgg => AggregateSqlEmitter::emit(
                self::requireConnection($connection, $definition),
                $definition,
                $innerQualifier,
                $rawFilter,
            ),
        };
    }

    /**
     * Token used to alias the outer table's columns inside the
     * renamed-derived FROM clause that the joined-shape callers use
     * when raw filters are present. Bare column references in user
     * raw SQL (e.g. `active = 1`) need a non-colliding outer scope so
     * they resolve to the inner `i` table; renaming `id → __nss_o_id`,
     * `lft → __nss_o_lft` etc. inside `(SELECT … FROM branches) AS o`
     * gives the SQL parser the unambiguous scope it needs.
     *
     * Pick a prefix obscure enough that user models almost certainly
     * don't have columns with this name. (If they do, they can
     * declare the raw filter via the registry's escape hatch.)
     */
    private static function renamedOuterColumn(string $col): string
    {
        return '__nss_o_'.$col;
    }

    /**
     * True if any of the given definitions carries a Raw filter — when
     * yes, the joined-shape SQL must use the renamed-outer derived so
     * bare column refs in the raw SQL bind correctly to the inner
     * table. When no, the simpler shape (no rename) is used.
     *
     * @param  iterable<AggregateDefinition>  $definitions
     */
    public static function hasRawFilter(iterable $definitions): bool
    {
        foreach ($definitions as $definition) {
            if ($definition->filter instanceof FilterPredicate
                && $definition->filter->getKind() === FilterPredicateKind::Raw) {
                return true;
            }
        }

        return false;
    }

    /**
     * Renders the outer-table FROM clause fragment for joined-shape
     * callers. When `$rawFilterPresent`, wraps the outer table in a
     * derived that renames `id`, `lft`, `rgt`, and every scope column
     * to a `__nss_o_*` form so bare column refs in raw SQL
     * unambiguously resolve to the inner table at SQL-parse time.
     * Otherwise returns the simpler `branches AS o` form.
     *
     * Returns the FROM fragment plus the fully-qualified outer-column
     * references the caller should use in JOIN / WHERE / GROUP BY.
     *
     * @param  list<string>  $scopeCols
     * @return array{from: string, outerLft: string, outerRgt: string, outerId: string, outerScope: array<string, string>, outerSoftDeleted: string|null}
     */
    public static function outerFromFragment(
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scopeCols,
        bool $rawFilterPresent,
        string $outerAlias,
        string $idCol = 'id',
        ?string $softDeletedColumn = null,
    ): array {
        if (! $rawFilterPresent) {
            $scopeRefs = [];
            foreach ($scopeCols as $col) {
                $scopeRefs[$col] = "{$outerAlias}.{$col}";
            }

            return [
                'from' => "{$table} AS {$outerAlias}",
                'outerLft' => "{$outerAlias}.{$lftCol}",
                'outerRgt' => "{$outerAlias}.{$rgtCol}",
                'outerId' => "{$outerAlias}.{$idCol}",
                'outerScope' => $scopeRefs,
                'outerSoftDeleted' => $softDeletedColumn !== null
                    ? "{$outerAlias}.{$softDeletedColumn}"
                    : null,
            ];
        }

        $projections = [
            "{$idCol} AS ".self::renamedOuterColumn($idCol),
            "{$lftCol} AS ".self::renamedOuterColumn($lftCol),
            "{$rgtCol} AS ".self::renamedOuterColumn($rgtCol),
        ];
        $outerScope = [];
        foreach ($scopeCols as $col) {
            $projections[] = "{$col} AS ".self::renamedOuterColumn($col);
            $outerScope[$col] = "{$outerAlias}.".self::renamedOuterColumn($col);
        }
        $outerSoftDeleted = null;
        if ($softDeletedColumn !== null) {
            $projections[] = "{$softDeletedColumn} AS ".self::renamedOuterColumn($softDeletedColumn);
            $outerSoftDeleted = "{$outerAlias}.".self::renamedOuterColumn($softDeletedColumn);
        }

        return [
            'from' => '(SELECT '.implode(', ', $projections)." FROM {$table}) AS {$outerAlias}",
            'outerLft' => "{$outerAlias}.".self::renamedOuterColumn($lftCol),
            'outerRgt' => "{$outerAlias}.".self::renamedOuterColumn($rgtCol),
            'outerId' => "{$outerAlias}.".self::renamedOuterColumn($idCol),
            'outerScope' => $outerScope,
            'outerSoftDeleted' => $outerSoftDeleted,
        ];
    }

    /**
     * Wraps a Raw-filtered aggregate as a correlated subquery whose FROM
     * is the only `$table` scope visible to the user's raw SQL —
     * guarantees that bare column references in the predicate resolve
     * to the inner row regardless of how many copies of the table are
     * present in the caller's joined context. Used as the fallback
     * when the caller can't accommodate the renamed-outer derived
     * shape (LATERAL, scalar, single-FROM correlated).
     *
     * @param  list<string>  $scopeCols
     */
    private static function correlatedRawFilterExpression(
        AggregateDefinition $definition,
        string $outerAlias,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scopeCols,
        ?Connection $connection = null,
    ): string {
        $filter = $definition->filter;
        if (! $filter instanceof FilterPredicate || $filter->getKind() !== FilterPredicateKind::Raw) {
            throw new AggregateConfigurationException(
                'correlatedRawFilterExpression(): expected a Raw FilterPredicate.',
            );
        }

        $rawSql = $filter->getRawSql() ?? throw new AggregateConfigurationException(
            'FilterPredicate of kind Raw has a null rawSql — this should never happen.',
        );

        $innerAlias = 'nss_rf';

        $innerQualifier = $innerAlias.'.';
        $aggInner = match ($definition->function) {
            AggregateFunction::Sum => sprintf(
                'SUM(CASE WHEN %s THEN %s ELSE 0 END)',
                $rawSql,
                self::sumBody($definition, $innerQualifier),
            ),
            AggregateFunction::Count => $definition->source === null
                ? sprintf('COUNT(CASE WHEN %s THEN 1 ELSE NULL END)', $rawSql)
                : sprintf(
                    'COUNT(CASE WHEN %s THEN %s.%s ELSE NULL END)',
                    $rawSql,
                    $innerAlias,
                    $definition->source,
                ),
            AggregateFunction::Avg => sprintf(
                'AVG(CASE WHEN %s THEN %s.%s ELSE NULL END)',
                $rawSql,
                $innerAlias,
                self::requireSource($definition),
            ),
            AggregateFunction::Min => sprintf(
                'MIN(CASE WHEN %s THEN %s.%s ELSE NULL END)',
                $rawSql,
                $innerAlias,
                self::requireSource($definition),
            ),
            AggregateFunction::Max => sprintf(
                'MAX(CASE WHEN %s THEN %s.%s ELSE NULL END)',
                $rawSql,
                $innerAlias,
                self::requireSource($definition),
            ),
            AggregateFunction::Variance => self::filteredVarianceFragment($definition, $innerAlias.'.', $rawSql, stddev: false),
            AggregateFunction::Stddev => self::filteredVarianceFragment($definition, $innerAlias.'.', $rawSql, stddev: true),
            AggregateFunction::BitOr,
            AggregateFunction::BitAnd,
            AggregateFunction::BitXor => sprintf(
                '%s(CASE WHEN %s THEN %s.%s ELSE NULL END)',
                self::bitwiseFunctionName($definition->function),
                $rawSql,
                $innerAlias,
                self::requireSource($definition),
            ),
            AggregateFunction::WeightedAvg,
            AggregateFunction::BoolOr,
            AggregateFunction::BoolAnd,
            AggregateFunction::GeometricMean,
            AggregateFunction::HarmonicMean => DerivedAggregateFragments::build($definition, $innerAlias.'.', $rawSql),
            AggregateFunction::Median,
            AggregateFunction::Percentile => throw new \LogicException(
                'Median/Percentile cannot appear in correlatedRawFilterExpression() — they are pre-extracted as correlated subqueries.',
            ),
            AggregateFunction::DistinctCount,
            AggregateFunction::StringAgg,
            AggregateFunction::JsonAgg,
            AggregateFunction::JsonObjectAgg => AggregateSqlEmitter::emit(
                self::requireConnection($connection, $definition),
                $definition,
                $innerAlias.'.',
                $rawSql,
            ),
        };

        // Single-column predicate on lft so MySQL's planner can use a
        // covering range scan on the (lft, rgt, parent_id, cover…)
        // index. The `i.lft <= o.rgt` form is equivalent to
        // `i.rgt <= o.rgt` under the nested-set invariant (any node
        // whose lft is in [o.lft, o.rgt] is a descendant-or-self of
        // o); the two-column shape `i.rgt <= o.rgt` forces MySQL into
        // a full index scan + filter that turns this subquery from
        // a covering ~5µs/row into a non-covering ~200µs/row.
        $bounds = $definition->inclusive
            ? "{$innerAlias}.{$lftCol} >= {$outerAlias}.{$lftCol} AND {$innerAlias}.{$lftCol} <= {$outerAlias}.{$rgtCol}"
            : "{$innerAlias}.{$lftCol} > {$outerAlias}.{$lftCol} AND {$innerAlias}.{$lftCol} < {$outerAlias}.{$rgtCol}";

        $scopeClause = '';
        foreach ($scopeCols as $col) {
            $scopeClause .= " AND {$innerAlias}.{$col} = {$outerAlias}.{$col}";
        }

        $expr = "(SELECT {$aggInner} FROM {$table} AS {$innerAlias} WHERE {$bounds}{$scopeClause})";

        // SUM / COUNT / DistinctCount must produce 0 for an empty subtree
        // (NOT NULL storage convention); the remaining kinds stay NULL.
        if (! $definition->function->nullableOnEmpty()) {
            return "COALESCE({$expr}, 0)";
        }

        return $expr;
    }

    /**
     * Aggregate expression for a joined context — a SELECT scope where
     * the outer and inner aliases both reference `$table`. When
     * `$rawFilterContext` is true, the caller has wrapped the outer
     * table in a {@see outerFromFragment()} renamed-derived so bare
     * refs in raw filters resolve unambiguously to the inner table;
     * raw filters emit inline (~30× faster on MySQL than the
     * correlated fallback). When false, raw filters use the
     * correlated subquery fallback for backends/contexts that can't
     * accommodate the rename.
     *
     * @param  list<string>  $scopeCols
     */
    public static function aggregateExpressionInJoinedContext(
        AggregateDefinition $definition,
        string $innerQualifier,
        string $outerAlias,
        string $table,
        string $lftCol,
        string $rgtCol,
        array $scopeCols,
        bool $rawFilterContext = false,
        ?Connection $connection = null,
    ): string {
        if ($definition->filter instanceof FilterPredicate
            && $definition->filter->getKind() === FilterPredicateKind::Raw) {
            if ($rawFilterContext) {
                return self::inlineRawFilterExpression(
                    $definition,
                    $definition->filter->getRawSql() ?? throw new AggregateConfigurationException(
                        'FilterPredicate of kind Raw has a null rawSql — this should never happen.',
                    ),
                    $innerQualifier,
                    $connection,
                );
            }

            return self::correlatedRawFilterExpression(
                $definition,
                $outerAlias,
                $table,
                $lftCol,
                $rgtCol,
                $scopeCols,
                $connection,
            );
        }

        return self::aggregateExpression($definition, $innerQualifier, $connection);
    }

    /**
     * Leaf-row inline expression for a {@see AggregateFunction::WeightedAvg}.
     * For a single-row subtree the weighted average degenerates to
     * `(weight * value) / weight = value`, but the closed form below
     * preserves NULL when the weight is missing or zero — matching
     * the join-derived path's `Σ(w · x) / NULLIF(Σ(w), 0)` semantics
     * exactly.
     */
    private static function leafInlineWeightedAvg(AggregateDefinition $definition, string $tableQualifier): string
    {
        $source = self::requireSource($definition);
        if ($definition->weight === null || $definition->weight === '') {
            throw new AggregateConfigurationException(sprintf(
                'WeightedAvg "%s" is missing its weight column.',
                $definition->column,
            ));
        }
        $weightRef = $tableQualifier.$definition->weight;
        $sourceRef = $tableQualifier.$source;

        return "(1.0 * ({$weightRef} * {$sourceRef})) / NULLIF({$weightRef}, 0)";
    }

    /**
     * Leaf-row inline expression for {@see AggregateFunction::BoolOr}
     * and {@see AggregateFunction::BoolAnd}. A single-row subtree's
     * boolean rollup is the row's value itself, except when that value
     * is NULL (which the join path treats as an empty contribution and
     * returns NULL for).
     */
    private static function leafInlineBool(AggregateDefinition $definition, string $tableQualifier): string
    {
        $sourceRef = $tableQualifier.self::requireSource($definition);

        return "CASE WHEN {$sourceRef} IS NULL THEN NULL WHEN {$sourceRef} THEN TRUE ELSE FALSE END";
    }

    /**
     * Leaf-row inline for GeometricMean. A single positive value's
     * geometric mean is itself; non-positive returns NULL (no positive
     * contributors in the singleton subtree).
     */
    private static function leafInlineGeometricMean(AggregateDefinition $definition, string $tableQualifier): string
    {
        $sourceRef = $tableQualifier.self::requireSource($definition);

        return "CASE WHEN {$sourceRef} > 0 THEN {$sourceRef} ELSE NULL END";
    }

    /**
     * Leaf-row inline for HarmonicMean. A single non-zero value's
     * harmonic mean is itself; zero returns NULL.
     */
    private static function leafInlineHarmonicMean(AggregateDefinition $definition, string $tableQualifier): string
    {
        $sourceRef = $tableQualifier.self::requireSource($definition);

        return "CASE WHEN {$sourceRef} <> 0 THEN {$sourceRef} ELSE NULL END";
    }

    /**
     * Render the inner expression that the outer `SUM(...)` aggregates
     * over for $definition, applying any companion
     * {@see CompanionSourceTransform} (e.g. the `weight * value`
     * product for a `Sum(w·x)` weighted-avg companion, the
     * `CASE WHEN c THEN 1 ELSE 0 END` cast for a bool-as-int
     * companion of `BoolOr` / `BoolAnd`). Plain (Identity) Sums
     * short-circuit to a bare column reference so the existing SQL
     * stays byte-for-byte identical for AVG and standalone Sum
     * aggregates.
     */
    private static function sumBody(AggregateDefinition $definition, string $qualifier): string
    {
        $sourceRef = $qualifier.self::requireSource($definition);
        if ($definition->sourceTransform === CompanionSourceTransform::Identity) {
            return $sourceRef;
        }

        $weightRef = $definition->weight !== null && $definition->weight !== ''
            ? $qualifier.$definition->weight
            : null;

        return $definition->sourceTransform->applySqlFragment($sourceRef, $weightRef);
    }

    private static function requireSource(AggregateDefinition $definition): string
    {
        if ($definition->source === null) {
            throw new AggregateConfigurationException(sprintf(
                'AggregateDefinition for column "%s" (%s) requires a source column.',
                $definition->column,
                $definition->function->value,
            ));
        }

        return $definition->source;
    }

    /**
     * Inline expression that produces the aggregate value for a leaf
     * row (`rgt = lft + 1`) without going through the LATERAL / derived
     * join. For inclusive aggregates the subtree is exactly the leaf
     * itself, so SUM/AVG/MIN/MAX collapse to the source column and
     * COUNT(*) is 1; for exclusive aggregates the subtree is empty so
     * SUM/COUNT are 0 and AVG/MIN/MAX are NULL.
     *
     * Used by the leaf fast-path in {@see FreshAggregateProjector::applyFreshSelects()},
     * wrapped in a `CASE WHEN rgt = lft + 1 THEN <inline> ELSE
     * <join-derived> END` so each row pays JOIN cost only when it has
     * descendants.
     */
    private static function leafInlineExpression(
        AggregateDefinition $definition,
        string $tableQualifier,
        ?string $softDeletedColumn = null,
        ?Connection $connection = null,
    ): string {
        if (! $definition->inclusive) {
            return match ($definition->function) {
                AggregateFunction::Sum,
                AggregateFunction::Count,
                AggregateFunction::DistinctCount => '0',
                AggregateFunction::Avg,
                AggregateFunction::Min,
                AggregateFunction::Max,
                AggregateFunction::Variance,
                AggregateFunction::Stddev,
                AggregateFunction::WeightedAvg,
                AggregateFunction::BoolOr,
                AggregateFunction::BoolAnd,
                AggregateFunction::GeometricMean,
                AggregateFunction::HarmonicMean,
                AggregateFunction::BitOr,
                AggregateFunction::BitAnd,
                AggregateFunction::BitXor,
                AggregateFunction::StringAgg,
                AggregateFunction::JsonAgg,
                AggregateFunction::JsonObjectAgg,
                AggregateFunction::Median,
                AggregateFunction::Percentile => 'NULL',
            };
        }

        if ($definition->filter instanceof FilterPredicate) {
            if (! $connection instanceof Connection) {
                throw new AggregateConfigurationException(
                    'Filtered aggregate expressions require a connection for safe value quoting.',
                );
            }
            $inline = self::filteredLeafInlineExpression($connection, $definition, $tableQualifier, $definition->filter);
        } else {
            $inline = match ($definition->function) {
                AggregateFunction::Sum => sprintf(
                    'COALESCE(%s, 0)',
                    self::sumBody($definition, $tableQualifier),
                ),
                AggregateFunction::Count => $definition->source === null
                    ? '1'
                    : sprintf(
                        'CASE WHEN %s%s IS NULL THEN 0 ELSE 1 END',
                        $tableQualifier,
                        $definition->source,
                    ),
                AggregateFunction::Avg,
                AggregateFunction::Min,
                AggregateFunction::Max,
                AggregateFunction::BitOr,
                AggregateFunction::BitAnd,
                AggregateFunction::BitXor => sprintf(
                    '%s%s',
                    $tableQualifier,
                    self::requireSource($definition),
                ),
                // Single-row subtree: population variance/stddev of {x} = 0,
                // sample variants are undefined (NULL) because n−1 = 0.
                // Source NULL → no contribution → NULL across all four.
                AggregateFunction::Variance, AggregateFunction::Stddev => $definition->sample
                    ? 'NULL'
                    : sprintf(
                        'CASE WHEN %s%s IS NULL THEN NULL ELSE 0 END',
                        $tableQualifier,
                        self::requireSource($definition),
                    ),
                AggregateFunction::WeightedAvg => self::leafInlineWeightedAvg($definition, $tableQualifier),
                AggregateFunction::BoolOr,
                AggregateFunction::BoolAnd => self::leafInlineBool($definition, $tableQualifier),
                AggregateFunction::GeometricMean => self::leafInlineGeometricMean($definition, $tableQualifier),
                AggregateFunction::HarmonicMean => self::leafInlineHarmonicMean($definition, $tableQualifier),
                AggregateFunction::Median,
                AggregateFunction::Percentile => sprintf(
                    '%s%s',
                    $tableQualifier,
                    self::requireSource($definition),
                ),
                AggregateFunction::DistinctCount,
                AggregateFunction::StringAgg,
                AggregateFunction::JsonAgg,
                AggregateFunction::JsonObjectAgg => AggregateSqlEmitter::leafInline(
                    self::requireConnection($connection, $definition),
                    $definition,
                    $tableQualifier,
                ),
            };
        }

        if ($softDeletedColumn === null) {
            return $inline;
        }

        // A trashed leaf contributes nothing to its own inclusive aggregate
        // — the soft-delete-filtered subquery would return 0 (SUM/COUNT) or
        // NULL (MIN/MAX/AVG) for it. Match that semantics here so the leaf
        // fast-path stays consistent with the join path on `withTrashed()`
        // queries.
        $emptyResult = match ($definition->function) {
            AggregateFunction::Sum,
            AggregateFunction::Count,
            AggregateFunction::DistinctCount => '0',
            AggregateFunction::Avg,
            AggregateFunction::Min,
            AggregateFunction::Max,
            AggregateFunction::Variance,
            AggregateFunction::Stddev,
            AggregateFunction::WeightedAvg,
            AggregateFunction::BoolOr,
            AggregateFunction::BoolAnd,
            AggregateFunction::GeometricMean,
            AggregateFunction::HarmonicMean,
            AggregateFunction::BitOr,
            AggregateFunction::BitAnd,
            AggregateFunction::BitXor,
            AggregateFunction::StringAgg,
            AggregateFunction::JsonAgg,
            AggregateFunction::JsonObjectAgg,
            AggregateFunction::Median,
            AggregateFunction::Percentile => 'NULL',
        };

        return sprintf(
            '(CASE WHEN %s%s IS NULL THEN %s ELSE %s END)',
            $tableQualifier,
            $softDeletedColumn,
            $inline,
            $emptyResult,
        );
    }

    private static function filteredLeafInlineExpression(
        Connection $connection,
        AggregateDefinition $definition,
        string $tableQualifier,
        FilterPredicate $filter,
    ): string {
        $pred = self::filterPredicateSql($connection, $filter, $tableQualifier);

        return match ($definition->function) {
            AggregateFunction::Sum => sprintf(
                'COALESCE(CASE WHEN %s THEN %s ELSE 0 END, 0)',
                $pred,
                self::sumBody($definition, $tableQualifier),
            ),
            AggregateFunction::Count => $definition->source === null
                ? sprintf('CASE WHEN %s THEN 1 ELSE 0 END', $pred)
                : sprintf(
                    'CASE WHEN %s AND %s%s IS NOT NULL THEN 1 ELSE 0 END',
                    $pred,
                    $tableQualifier,
                    $definition->source,
                ),
            AggregateFunction::Avg,
            AggregateFunction::Min,
            AggregateFunction::Max,
            AggregateFunction::BitOr,
            AggregateFunction::BitAnd,
            AggregateFunction::BitXor => sprintf(
                'CASE WHEN %s THEN %s%s ELSE NULL END',
                $pred,
                $tableQualifier,
                self::requireSource($definition),
            ),
            // See {@see leafInlineExpression()} for the unfiltered counterpart.
            // The filter wraps the leaf inline: when the predicate is false,
            // the leaf contributes nothing → NULL across all four functions.
            AggregateFunction::Variance, AggregateFunction::Stddev => sprintf(
                'CASE WHEN %s AND %s%s IS NOT NULL THEN %s ELSE NULL END',
                $pred,
                $tableQualifier,
                self::requireSource($definition),
                $definition->sample ? 'NULL' : '0',
            ),
            AggregateFunction::WeightedAvg => sprintf(
                'CASE WHEN %s THEN %s ELSE NULL END',
                $pred,
                self::leafInlineWeightedAvg($definition, $tableQualifier),
            ),
            AggregateFunction::BoolOr,
            AggregateFunction::BoolAnd => sprintf(
                'CASE WHEN %s THEN %s ELSE NULL END',
                $pred,
                self::leafInlineBool($definition, $tableQualifier),
            ),
            AggregateFunction::GeometricMean => sprintf(
                'CASE WHEN (%s) AND %s > 0 THEN %s ELSE NULL END',
                $pred,
                $tableQualifier.self::requireSource($definition),
                $tableQualifier.self::requireSource($definition),
            ),
            AggregateFunction::HarmonicMean => sprintf(
                'CASE WHEN (%s) AND %s <> 0 THEN %s ELSE NULL END',
                $pred,
                $tableQualifier.self::requireSource($definition),
                $tableQualifier.self::requireSource($definition),
            ),
            AggregateFunction::Median,
            AggregateFunction::Percentile => sprintf(
                'CASE WHEN (%s) THEN %s%s ELSE NULL END',
                $pred,
                $tableQualifier,
                self::requireSource($definition),
            ),
            AggregateFunction::DistinctCount,
            AggregateFunction::StringAgg,
            AggregateFunction::JsonAgg,
            AggregateFunction::JsonObjectAgg => AggregateSqlEmitter::leafInline(
                $connection,
                $definition,
                $tableQualifier,
                $pred,
            ),
        };
    }

    /**
     * Wraps a join-derived aggregate expression in the leaf fast-path
     * CASE: returns the leaf-inline value when `rgt = lft + 1`, the
     * join expression otherwise. On every supported backend the inner
     * branch is only evaluated when the outer CASE picks it; on
     * LATERAL/derived backends the JOIN may still fire (because the
     * planner evaluates the JOIN before the SELECT) so callers that
     * can short-circuit the JOIN itself should do so via the inner
     * WHERE clause as well — this CASE is the SELECT-side half of
     * the fast-path.
     */
    public static function wrapLeafFastPath(
        AggregateDefinition $definition,
        string $tableQualifier,
        string $lftCol,
        string $rgtCol,
        string $joinExpr,
        ?string $softDeletedColumn = null,
        ?Connection $connection = null,
    ): string {
        $inline = self::leafInlineExpression($definition, $tableQualifier, $softDeletedColumn, $connection);

        return sprintf(
            'CASE WHEN %s%s = %s%s + 1 THEN %s ELSE %s END',
            $tableQualifier,
            $rgtCol,
            $tableQualifier,
            $lftCol,
            $inline,
            $joinExpr,
        );
    }

    /**
     * Laravel reports both MariaDB and MySQL under the `mysql` driver
     * name; we need to distinguish them because their planners pick
     * different execution strategies for the same SQL. PDO's
     * `ATTR_SERVER_VERSION` returns the server's `@@version` string
     * verbatim — MariaDB's includes "MariaDB", MySQL's does not.
     */
    public static function isMariaDb(ConnectionInterface $connection): bool
    {
        if (! $connection instanceof Connection) {
            return false;
        }

        if ($connection->getDriverName() !== 'mysql') {
            return false;
        }

        try {
            $version = $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable) {
            return false;
        }

        return is_string($version) && stripos($version, 'mariadb') !== false;
    }

    /**
     * Returns true on real MySQL (Oracle MySQL), false on MariaDB or
     * any non-mysql driver. Distinguishes MySQL from MariaDB so we can
     * apply MySQL-specific planner hints (e.g. STRAIGHT_JOIN inside
     * the fixAggregates derived table) without affecting MariaDB,
     * which has its own SET STATEMENT path for the same query.
     */
    public static function isMySql(ConnectionInterface $connection): bool
    {
        return $connection instanceof Connection
            && $connection->getDriverName() === 'mysql'
            && ! self::isMariaDb($connection);
    }
}
