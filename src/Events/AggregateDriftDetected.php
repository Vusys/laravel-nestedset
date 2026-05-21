<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Vusys\NestedSet\Concerns\HasNestedSetAggregates;

/**
 * Fires from {@see HasNestedSetAggregates::aggregateErrors()} (and
 * the convenience {@see HasNestedSetAggregates::aggregatesAreBroken()}
 * it backs) when the per-column drift counts contain at least one
 * non-zero entry. Does NOT fire on a clean tree — it's an alerting
 * signal, not a heartbeat.
 *
 * Use case: periodic monitoring job runs `aggregatesAreBroken()`;
 * this event fires only when something is actually wrong, so the
 * listener can page/alert without a per-check noise floor.
 *
 * Queue-safe.
 */
final readonly class AggregateDriftDetected
{
    /**
     * @param  array<string, int>  $perColumn  per-user-facing-column drift counts (only non-zero entries)
     */
    public function __construct(
        public string $modelClass,
        public int|string|null $anchorId,
        public array $perColumn,
        public int $totalDrift,
    ) {}
}
