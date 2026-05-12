<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Aggregates;

use Vusys\NestedSet\TreeFixResult;

/**
 * Structured return value of `fixAggregates()` — folded into
 * {@see TreeFixResult} when called as part of a
 * `fixTree()` cycle.
 *
 * `totalRowsUpdated` is the total UPDATE row count summed across every
 * recomputed aggregate column; `perColumn` carries the breakdown for
 * callers (e.g. diagnostics dashboards) that want to see which
 * aggregates actually drifted.
 */
final readonly class AggregateFixResult
{
    /** @param array<string, int> $perColumn */
    public function __construct(
        public int $totalRowsUpdated,
        public array $perColumn,
    ) {}

    /**
     * True when any aggregate column had at least one row updated by
     * the recompute — i.e. drift was detected and repaired.
     */
    public function hasDrift(): bool
    {
        return $this->totalRowsUpdated > 0;
    }
}
