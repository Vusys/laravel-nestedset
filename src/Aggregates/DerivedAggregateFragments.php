<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

use Vusys\NestedSet\Aggregates\Strategy\DeltaMaintenance;
use Vusys\NestedSet\Exceptions\AggregateConfigurationException;

/**
 * SQL fragment builders for the *derived-from-companions* aggregate
 * families ({@see AggregateFunction::WeightedAvg},
 * {@see AggregateFunction::BoolOr}, {@see AggregateFunction::BoolAnd},
 * {@see AggregateFunction::GeometricMean},
 * {@see AggregateFunction::HarmonicMean}).
 *
 * These aggregates have no native single-function SQL form portable
 * across the four supported backends — instead they compile to a
 * derived expression over per-row inputs:
 *
 *  - **WeightedAvg**: `Σ(weight · value) / Σ(weight)` (NULL when no
 *    weight participates).
 *  - **BoolOr / BoolAnd**: a count-and-sum-of-truthies pair gated by
 *    a CASE that returns NULL on an empty subtree.
 *  - **GeometricMean**: `EXP(Σ LN(source) / n)` where only positive
 *    source values contribute. NULL when no positive row exists.
 *  - **HarmonicMean**: `n / Σ(1 / source)` where only non-zero source
 *    values contribute. NULL when the subtree is empty.
 *
 * The same fragments serve both *fresh* aggregate emission (used by
 * `withFreshAggregates` and the in-query subtree projection) and the
 * delta-time SET clauses written by
 * {@see DeltaMaintenance} —
 * keeping the SQL and PHP sides arithmetically equivalent for an
 * arbitrary row set.
 */
final class DerivedAggregateFragments
{
    /**
     * Returns true when $function names one of the derived-from-companions
     * families this helper covers. Callers use it to route around the
     * native SUM/COUNT/AVG/MIN/MAX emission.
     */
    public static function handles(AggregateFunction $function): bool
    {
        return match ($function) {
            AggregateFunction::WeightedAvg,
            AggregateFunction::BoolOr,
            AggregateFunction::BoolAnd,
            AggregateFunction::GeometricMean,
            AggregateFunction::HarmonicMean => true,
            default => false,
        };
    }

    /**
     * Build the derived SQL expression that yields the user-facing
     * value of $definition over the subtree rows referenced by
     * `{$qualifier}*` (e.g. `inner_a.` for the inner subquery alias,
     * `i.` for joined-shape derives). When $filterSql is non-null,
     * every inner SUM/COUNT is wrapped in `CASE WHEN $filterSql THEN
     * x ELSE 0 END` so the filter participates consistently.
     */
    public static function build(
        AggregateDefinition $definition,
        string $qualifier,
        ?string $filterSql = null,
    ): string {
        $source = $definition->source;
        if ($source === null) {
            throw new AggregateConfigurationException(sprintf(
                'DerivedAggregateFragments: %s requires a source column on "%s".',
                strtoupper($definition->function->value),
                $definition->column,
            ));
        }

        $sourceRef = $qualifier.$source;

        return match ($definition->function) {
            AggregateFunction::WeightedAvg => self::weightedAvgSql($definition, $qualifier, $sourceRef, $filterSql),
            AggregateFunction::BoolOr,
            AggregateFunction::BoolAnd => self::boolSql($definition, $sourceRef, $filterSql),
            AggregateFunction::GeometricMean => self::geometricMeanSql($sourceRef, $filterSql),
            AggregateFunction::HarmonicMean => self::harmonicMeanSql($sourceRef, $filterSql),
            default => throw new AggregateConfigurationException(sprintf(
                'DerivedAggregateFragments::build(): %s is not a derived-from-companions aggregate.',
                $definition->function->value,
            )),
        };
    }

    private static function weightedAvgSql(
        AggregateDefinition $definition,
        string $qualifier,
        string $sourceRef,
        ?string $filterSql,
    ): string {
        if ($definition->weight === null || $definition->weight === '') {
            throw new AggregateConfigurationException(sprintf(
                'DerivedAggregateFragments: WeightedAvg "%s" is missing its weight column.',
                $definition->column,
            ));
        }

        $weightRef = $qualifier.$definition->weight;
        $product = "({$weightRef} * {$sourceRef})";

        if ($filterSql !== null) {
            $sumWx = "SUM(CASE WHEN {$filterSql} THEN {$product} ELSE 0 END)";
            $sumW = "SUM(CASE WHEN {$filterSql} THEN {$weightRef} ELSE 0 END)";
        } else {
            $sumWx = "SUM({$product})";
            $sumW = "SUM({$weightRef})";
        }

        return "(1.0 * ({$sumWx})) / NULLIF(({$sumW}), 0)";
    }

    private static function boolSql(
        AggregateDefinition $definition,
        string $sourceRef,
        ?string $filterSql,
    ): string {
        $asInt = "(CASE WHEN {$sourceRef} THEN 1 ELSE 0 END)";

        if ($filterSql !== null) {
            $sumExpr = "SUM(CASE WHEN {$filterSql} THEN {$asInt} ELSE 0 END)";
            // Also gate on source non-null so the filtered count matches
            // the unfiltered `COUNT($sourceRef)` semantics — rows whose
            // boolean source is NULL should not contribute to the count,
            // regardless of the filter outcome.
            $countExpr = "COUNT(CASE WHEN {$filterSql} AND {$sourceRef} IS NOT NULL THEN 1 ELSE NULL END)";
        } else {
            $sumExpr = "SUM({$asInt})";
            $countExpr = sprintf('COUNT(%s)', $sourceRef);
        }

        $check = $definition->function === AggregateFunction::BoolAnd
            ? "({$sumExpr}) = ({$countExpr})"
            : "({$sumExpr}) > 0";

        return "CASE WHEN ({$countExpr}) = 0 THEN NULL "
            ."WHEN {$check} THEN TRUE ELSE FALSE END";
    }

    /**
     * Geometric mean: `EXP(Σ LN(src) / n)` where only positive rows
     * contribute. The guard `CASE WHEN src > 0 THEN src ELSE NULL END`
     * makes `LN` return NULL for non-positive rows so `SUM` skips them
     * on all four backends (on PostgreSQL `LN` of a negative number
     * raises an error rather than returning NULL — the guard prevents
     * that). NULL when no positive row exists.
     */
    private static function geometricMeanSql(string $sourceRef, ?string $filterSql): string
    {
        $positiveGuard = $filterSql !== null
            ? "CASE WHEN ({$filterSql}) AND {$sourceRef} > 0 THEN {$sourceRef} ELSE NULL END"
            : "CASE WHEN {$sourceRef} > 0 THEN {$sourceRef} ELSE NULL END";

        $sumLog = "SUM(LN({$positiveGuard}))";
        $countPos = "NULLIF(COUNT({$positiveGuard}), 0)";

        return "EXP({$sumLog} / {$countPos})";
    }

    /**
     * Harmonic mean: `n / Σ(1 / src)` where only non-zero rows
     * contribute. `NULLIF(src, 0)` makes the reciprocal NULL for zero
     * rows so `SUM` skips them. NULL when the subtree is empty.
     */
    private static function harmonicMeanSql(string $sourceRef, ?string $filterSql): string
    {
        if ($filterSql !== null) {
            $recipExpr = "CASE WHEN ({$filterSql}) AND {$sourceRef} <> 0 THEN (1.0 / {$sourceRef}) ELSE NULL END";
            $countExpr = "COUNT(CASE WHEN ({$filterSql}) THEN 1 ELSE NULL END)";
        } else {
            $recipExpr = "(1.0 / NULLIF({$sourceRef}, 0))";
            $countExpr = "COUNT({$sourceRef})";
        }

        return "NULLIF({$countExpr}, 0) / NULLIF(SUM({$recipExpr}), 0)";
    }
}
