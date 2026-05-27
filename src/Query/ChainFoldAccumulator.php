<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Query;

use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Aggregates\Definitions\AggregateDefinition;
use Vusys\NestedSet\Aggregates\Definitions\CompanionSourceTransform;
use Vusys\NestedSet\Query\Aggregates\Maintenance\AggregateDiffer;

/**
 * Per-definition accumulator for the chain-fold fast path used by
 * {@see AggregateDiffer::selectStoredAndComputedViaChainFold()}.
 *
 * One instance per definition. The outer chain walk feeds each row's
 * (source, optional weight) values through {@see self::apply()}, which
 * returns the inclusive aggregate values *before* and *after* the row —
 * the caller picks one based on whether the declaration is inclusive
 * or exclusive.
 *
 * Internalises the per-function state and per-row update logic so each
 * supported kind is one branch in one place. The match on
 * {@see AggregateFunction} is appropriate polymorphism over a closed
 * enum — no virtual-dispatch overhead compared to a sealed subclass
 * hierarchy, and the per-kind state stays scoped to the branch that
 * uses it.
 */
final class ChainFoldAccumulator
{
    private const array COMPANION_DERIVED = [
        AggregateFunction::Avg,
        AggregateFunction::Variance,
        AggregateFunction::Stddev,
    ];

    private float $sum = 0.0;

    private float $sumSq = 0.0;

    private int $count = 0;

    private float $sumWx = 0.0;

    private float $sumW = 0.0;

    private int $boolSum = 0;

    private int $boolCount = 0;

    private float $sumLog = 0.0;

    private int $countPos = 0;

    private float $sumRecip = 0.0;

    private int $countNonNull = 0;

    private int|float|null $stepInclusive;

    public function __construct(private readonly AggregateDefinition $definition)
    {
        $this->stepInclusive = $this->stepEmpty($definition);
    }

    /**
     * Apply one row to the accumulator. Returns the inclusive aggregate
     * values for the subtree *before* and *after* this row — caller
     * picks one based on the declaration's `inclusive` flag.
     *
     * @return array{previous: int|float|bool|null, current: int|float|bool|null}
     */
    public function apply(mixed $sourceValue, mixed $weightValue): array
    {
        $function = $this->definition->function;

        if (in_array($function, self::COMPANION_DERIVED, true)) {
            $previous = $this->deriveCompanionDisplay();

            $contributes = is_numeric($sourceValue);
            $sourceFloat = $contributes ? (float) $sourceValue : 0.0;
            $this->sum += $sourceFloat;
            $this->sumSq += $sourceFloat * $sourceFloat;
            $this->count += $contributes ? 1 : 0;

            return ['previous' => $previous, 'current' => $this->deriveCompanionDisplay()];
        }

        if ($function === AggregateFunction::WeightedAvg) {
            $previous = $this->weightedAvgDisplay();

            $weightNumeric = is_numeric($weightValue) ? (float) $weightValue : 0.0;
            $valueNumeric = is_numeric($sourceValue) ? (float) $sourceValue : 0.0;
            $this->sumWx += $weightNumeric * $valueNumeric;
            $this->sumW += $weightNumeric;

            return ['previous' => $previous, 'current' => $this->weightedAvgDisplay()];
        }

        if ($function === AggregateFunction::BoolOr || $function === AggregateFunction::BoolAnd) {
            $previous = $this->boolDisplay();

            $this->boolSum += $this->asBoolInt($sourceValue);
            $this->boolCount += $sourceValue !== null ? 1 : 0;

            return ['previous' => $previous, 'current' => $this->boolDisplay()];
        }

        if ($function === AggregateFunction::GeometricMean) {
            $previous = $this->geometricMeanDisplay();

            $numericSource = is_numeric($sourceValue) ? (float) $sourceValue : null;
            if ($numericSource !== null && $numericSource > 0) {
                $this->sumLog += log($numericSource);
                $this->countPos += 1;
            }

            return ['previous' => $previous, 'current' => $this->geometricMeanDisplay()];
        }

        if ($function === AggregateFunction::HarmonicMean) {
            $previous = $this->harmonicMeanDisplay();

            // Zero-valued rows skip the reciprocal — they must also
            // skip the count, or the n / Σ(1/x) formula uses too
            // large an N and the harmonic mean comes out too small.
            $numericSource = is_numeric($sourceValue) ? (float) $sourceValue : null;
            if ($numericSource !== null && $numericSource !== 0.0) {
                $this->sumRecip += 1.0 / $numericSource;
                $this->countNonNull += 1;
            }

            return ['previous' => $previous, 'current' => $this->harmonicMeanDisplay()];
        }

        // Default: int|float|null chain-fold (Sum, Count, Min, Max, Bit*).
        // Apply the source transform first so the fast path matches what
        // the slow SQL path would compute — without this a chain-shaped
        // tree's transformed companion folds the wrong value and
        // fixAggregates() would overwrite the correct stored companion.
        $needsWeight = $this->definition->sourceTransform->requiresWeight()
            && $this->definition->weight !== null
            && $this->definition->weight !== '';
        $weightForTransform = $needsWeight && is_numeric($weightValue) ? (float) $weightValue : null;
        $foldValue = $this->definition->sourceTransform === CompanionSourceTransform::Identity
            ? $sourceValue
            : $this->definition->sourceTransform->applyPhp($sourceValue, $weightForTransform);

        $previous = $this->stepInclusive;
        $this->stepInclusive = $this->stepCombine($this->definition, $foldValue, $previous);

        return ['previous' => $previous, 'current' => $this->stepInclusive];
    }

    private function deriveCompanionDisplay(): ?float
    {
        if ($this->count === 0) {
            return null;
        }

        return match ($this->definition->function) {
            AggregateFunction::Avg => $this->sum / $this->count,
            AggregateFunction::Variance => $this->computeVariance($this->sum, $this->sumSq, $this->count, $this->definition->sample),
            AggregateFunction::Stddev => $this->computeStddev($this->sum, $this->sumSq, $this->count, $this->definition->sample),
            default => throw new \LogicException(
                'deriveCompanionDisplay called with non-companion-derived function '.$this->definition->function->value,
            ),
        };
    }

    private function weightedAvgDisplay(): ?float
    {
        return $this->sumW !== 0.0 ? $this->sumWx / $this->sumW : null;
    }

    private function boolDisplay(): ?bool
    {
        if ($this->boolCount === 0) {
            return null;
        }

        return $this->definition->function === AggregateFunction::BoolAnd
            ? $this->boolSum === $this->boolCount
            : $this->boolSum > 0;
    }

    private function geometricMeanDisplay(): ?float
    {
        return $this->countPos > 0 ? exp($this->sumLog / $this->countPos) : null;
    }

    private function harmonicMeanDisplay(): ?float
    {
        return ($this->countNonNull > 0 && $this->sumRecip !== 0.0)
            ? $this->countNonNull / $this->sumRecip
            : null;
    }

    /**
     * Initial accumulator value for the default chain-fold branch —
     * the inclusive aggregate of an empty subtree (i.e. what an
     * exclusive aggregate reports on a leaf).
     */
    private function stepEmpty(AggregateDefinition $definition): ?int
    {
        return match ($definition->function) {
            AggregateFunction::Sum,
            AggregateFunction::Count,
            AggregateFunction::DistinctCount => 0,
            default => null,
        };
    }

    /**
     * One step of the default chain-fold branch. Takes the row's
     * (already-transformed) source value and the previous inclusive,
     * returns the current inclusive.
     */
    private function stepCombine(
        AggregateDefinition $definition,
        mixed $sourceValue,
        int|float|null $previousInclusive,
    ): int|float|null {
        switch ($definition->function) {
            case AggregateFunction::Sum:
                $sourceNumeric = is_numeric($sourceValue) ? (float) $sourceValue : 0.0;
                $prev = $previousInclusive ?? 0;
                $sum = $sourceNumeric + (float) $prev;

                return $this->isWhole($sum) ? (int) $sum : $sum;

            case AggregateFunction::Count:
                $contribution = $definition->source === null
                    ? 1
                    : ($sourceValue !== null ? 1 : 0);

                return ((int) ($previousInclusive ?? 0)) + $contribution;

            case AggregateFunction::Min:
                if (! is_numeric($sourceValue)) {
                    return $previousInclusive;
                }
                $sourceNumeric = (float) $sourceValue;
                if ($previousInclusive === null) {
                    return $this->isWhole($sourceNumeric) ? (int) $sourceNumeric : $sourceNumeric;
                }
                $minValue = min($sourceNumeric, (float) $previousInclusive);

                return $this->isWhole($minValue) ? (int) $minValue : $minValue;

            case AggregateFunction::Max:
                if (! is_numeric($sourceValue)) {
                    return $previousInclusive;
                }
                $sourceNumeric = (float) $sourceValue;
                if ($previousInclusive === null) {
                    return $this->isWhole($sourceNumeric) ? (int) $sourceNumeric : $sourceNumeric;
                }
                $maxValue = max($sourceNumeric, (float) $previousInclusive);

                return $this->isWhole($maxValue) ? (int) $maxValue : $maxValue;

            case AggregateFunction::BitOr:
                if (! is_numeric($sourceValue)) {
                    return $previousInclusive;
                }
                $sourceInt = (int) $sourceValue;
                if ($previousInclusive === null) {
                    return $sourceInt;
                }

                return ((int) $previousInclusive) | $sourceInt;

            case AggregateFunction::BitAnd:
                if (! is_numeric($sourceValue)) {
                    return $previousInclusive;
                }
                $sourceInt = (int) $sourceValue;
                if ($previousInclusive === null) {
                    return $sourceInt;
                }

                return ((int) $previousInclusive) & $sourceInt;

            case AggregateFunction::BitXor:
                if (! is_numeric($sourceValue)) {
                    return $previousInclusive;
                }
                $sourceInt = (int) $sourceValue;
                if ($previousInclusive === null) {
                    return $sourceInt;
                }

                return ((int) $previousInclusive) ^ $sourceInt;

            case AggregateFunction::Avg:
            case AggregateFunction::Variance:
            case AggregateFunction::Stddev:
            case AggregateFunction::WeightedAvg:
            case AggregateFunction::BoolOr:
            case AggregateFunction::BoolAnd:
            case AggregateFunction::GeometricMean:
            case AggregateFunction::HarmonicMean:
                throw new \LogicException(sprintf(
                    '%s must be handled inline in the chain-fold accumulator.',
                    strtoupper($definition->function->value),
                ));

            case AggregateFunction::DistinctCount:
            case AggregateFunction::StringAgg:
            case AggregateFunction::JsonAgg:
            case AggregateFunction::JsonObjectAgg:
            case AggregateFunction::Median:
            case AggregateFunction::Percentile:
                throw new \LogicException(sprintf(
                    'ChainFoldAccumulator does not handle %s — recompute-only kinds skip the chain fold.',
                    $definition->function->value,
                ));
        }
    }

    private function computeVariance(float $sum, float $sumSq, int $count, bool $sample): ?float
    {
        if ($sample && $count < 2) {
            return null;
        }
        $denominator = $sample ? $count * ($count - 1) : $count * $count;
        if ($denominator === 0) {
            return null;
        }
        $numerator = $count * $sumSq - $sum * $sum;

        return $numerator / $denominator;
    }

    private function computeStddev(float $sum, float $sumSq, int $count, bool $sample): ?float
    {
        $variance = $this->computeVariance($sum, $sumSq, $count, $sample);
        if ($variance === null) {
            return null;
        }

        // Floating-point cancellation around large clustered values
        // can leave the textbook formula returning a tiny negative
        // variance. Clamp to 0 so sqrt() doesn't blow up — matches
        // the CASE-zero clamp the SQL fragment uses.
        return $variance <= 0.0 ? 0.0 : sqrt($variance);
    }

    /**
     * Cast a raw column value to the 0/1 contribution the bool-as-int
     * companion would store. Mirrors the SQL fragment
     * `CASE WHEN c THEN 1 ELSE 0 END`.
     */
    private function asBoolInt(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value) || is_float($value)) {
            return $value !== 0 && $value !== 0.0 ? 1 : 0;
        }
        if (is_string($value)) {
            // PG returns 't'/'f' for boolean columns; MySQL/MariaDB
            // use '0'/'1'; SQLite (under Laravel's bool cast) the
            // same. Treat explicit false markers as 0 and anything
            // else numeric or truthy-textual as 1 — defensive against
            // driver differences when the column has not been cast.
            $trimmed = strtolower(trim($value));
            if (in_array($trimmed, ['', '0', 'f', 'false'], true)) {
                return 0;
            }

            return 1;
        }

        return 0;
    }

    /**
     * True when a float value has no fractional part — lets the chain
     * fold return integer-valued results as `int` to match the SQL
     * path (where SUM/MIN/MAX over an integer column come back as int).
     */
    private function isWhole(float $value): bool
    {
        return $value === floor($value) && abs($value) < PHP_INT_MAX;
    }
}
