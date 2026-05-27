<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\Aggregates;

use Vusys\NestedSet\Concerns\HasNestedSetAggregates;

/**
 * Fires once at the outermost exit of
 * {@see HasNestedSetAggregates::withDeferredAggregateMaintenance()},
 * after the closing `fixAggregates($anchor)` pass completes.
 *
 * Boundary marker for "a batch of saves wrapped in deferred
 * maintenance has finished". Lets observers attribute the cost of
 * the closing repair to the user's wrapped operation (CSV import,
 * scripted re-parent, etc.) instead of seeing it as a stray
 * fix-aggregates call.
 *
 * Nested calls share one counter — the event only fires when the
 * outermost wrapper exits successfully.
 */
final readonly class DeferredAggregateMaintenanceCompleted
{
    public function __construct(
        public string $modelClass,
        public int|string|null $anchorId,
        public int $rowsFixed,
        /** Wall-clock spent inside the user's closure. */
        public float $closureDurationMs,
        /** Wall-clock spent in the closing fixAggregates pass. */
        public float $repairDurationMs,
    ) {}
}
