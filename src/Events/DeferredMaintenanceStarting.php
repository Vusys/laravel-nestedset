<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Vusys\NestedSet\Concerns\HasNestedSetAggregates;

/**
 * Fires at the outermost entry of
 * {@see HasNestedSetAggregates::withDeferredAggregateMaintenance()},
 * before the user's closure runs.
 *
 * Pairs with the existing {@see DeferredAggregateMaintenanceCompleted}.
 * Applications that need to suspend their own cache invalidation /
 * search-index updates / audit logging for the duration of a batch
 * can use the opening boundary as the "suspend" signal and the
 * closing one as "resume + drain."
 *
 * Nested calls share the depth counter — this event only fires when
 * the outermost wrapper opens, mirroring the completion event.
 *
 * Queue-safe.
 */
final readonly class DeferredMaintenanceStarting
{
    public function __construct(
        public string $modelClass,
        public int|string|null $anchorId,
    ) {}
}
