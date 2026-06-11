<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates\Listeners;

use Vusys\NestedSet\Aggregates\AggregateFunction;
use Vusys\NestedSet\Exceptions\NestedSetLogicException;

/**
 * Mutable running aggregate of contributions for one ancestor's subtree
 * during a listener-side DFS pass.
 *
 * The DFS over a `lft`-sorted node list keeps one accumulator per open
 * stack frame. As each node closes, its accumulator carries the full
 * subtree's contribution roll-up; the closed frame is merged into its
 * parent's via {@see merge()}, and the closed node's stored aggregate
 * value is read via {@see finalize()}.
 *
 * One accumulator carries the state for every supported operation —
 * Sum, Count, Min, Max, Avg, Variance, Stddev, GeometricMean,
 * HarmonicMean. The few extra floats per accumulator are cheap (a few
 * tens of bytes) and the alternative (per-operation subclasses) costs
 * more in dispatch overhead than it saves in memory.
 */
final class ListenerAccumulator
{
    private float $sum = 0.0;

    private int $count = 0;

    private int|float|null $min = null;

    private int|float|null $max = null;

    private float $sumSq = 0.0;

    private float $sumLog = 0.0;

    private int $countPos = 0;

    private float $sumRecip = 0.0;

    private int $countNonZero = 0;

    public function __construct(private readonly AggregateFunction $operation) {}

    /**
     * Folds one row's contribution into every internal running statistic.
     * Null contributions (filter-excluded, domain-out, or listener-returned)
     * are skipped to match SUM(NULL)/COUNT(NULL) semantics on the SQL side.
     */
    public function addContribution(int|float|null $c): void
    {
        if ($c === null) {
            return;
        }

        $this->sum += $c;
        $this->count++;
        $this->min = $this->min === null ? $c : min($this->min, $c);
        $this->max = $this->max === null ? $c : max($this->max, $c);
        $this->sumSq += $c * $c;

        if ($c > 0) {
            $this->sumLog += log((float) $c);
            $this->countPos++;
        }

        if ($c != 0) {
            $this->sumRecip += 1.0 / $c;
            $this->countNonZero++;
        }
    }

    /**
     * Folds another accumulator (representing a closed subtree's full
     * roll-up) into this one. Every running statistic combines as a
     * commutative monoid — `sum`, `count`, and the transformed sums add;
     * `min` / `max` reduce; the per-domain counts add too — so subtree
     * order is irrelevant and the DFS pop order is safe.
     */
    public function merge(self $other): void
    {
        $this->sum += $other->sum;
        $this->count += $other->count;

        if ($other->min !== null) {
            $this->min = $this->min === null ? $other->min : min($this->min, $other->min);
        }
        if ($other->max !== null) {
            $this->max = $this->max === null ? $other->max : max($this->max, $other->max);
        }

        $this->sumSq += $other->sumSq;
        $this->sumLog += $other->sumLog;
        $this->countPos += $other->countPos;
        $this->sumRecip += $other->sumRecip;
        $this->countNonZero += $other->countNonZero;
    }

    /**
     * Produces the display value for this accumulator's operation, or
     * `null` when the subtree contributed no rows for that operation's
     * domain (empty subtree for sum/count returns 0; everything else
     * returns null on empty).
     */
    public function finalize(): int|float|null
    {
        return match ($this->operation) {
            AggregateFunction::Sum => $this->sum,
            AggregateFunction::Count => $this->count,
            AggregateFunction::Min => $this->min,
            AggregateFunction::Max => $this->max,
            AggregateFunction::Avg => $this->count === 0
                ? null
                : $this->sum / $this->count,
            AggregateFunction::Variance => $this->count === 0
                ? null
                : max(0.0, ($this->count * $this->sumSq - $this->sum * $this->sum) / ($this->count * $this->count)),
            AggregateFunction::Stddev => $this->count === 0
                ? null
                : sqrt(max(0.0, ($this->count * $this->sumSq - $this->sum * $this->sum) / ($this->count * $this->count))),
            AggregateFunction::GeometricMean => $this->countPos === 0
                ? null
                : exp($this->sumLog / $this->countPos),
            AggregateFunction::HarmonicMean => ($this->countNonZero === 0 || $this->sumRecip === 0.0)
                ? null
                : $this->countNonZero / $this->sumRecip,
            default => throw new NestedSetLogicException(sprintf(
                'ListenerAccumulator: operation %s is not maintainable in PHP. '
                .'ListenerAggregateDefinition / applyListenerOperation reject this case earlier.',
                $this->operation->value,
            )),
        };
    }
}
