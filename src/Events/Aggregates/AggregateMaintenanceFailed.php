<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events\Aggregates;

use Throwable;

/**
 * Fires when an exception escapes one of the trait's aggregate-
 * maintenance hooks (delta capture, delta apply, MIN/MAX recompute,
 * on-create push, on-delete pull, on-restore re-add). The exception
 * still propagates — this event is purely for observability (Sentry,
 * Bugsnag, etc.). The wrapping transaction has already rolled back
 * the structural mutation by the time the event fires; the exception
 * IS the failure, the event just announces it.
 *
 * NOT queue-safe by default: `Throwable` instances don't serialise
 * cleanly across most queue drivers. If you need a queued listener,
 * capture the relevant fields synchronously (`$event->stage`,
 * `$event->modelClass`, etc.) and forward those instead of the whole
 * event object.
 */
final readonly class AggregateMaintenanceFailed
{
    public function __construct(
        public string $modelClass,
        public int|string|null $anchorId,
        /**
         * One of: 'capture', 'apply', 'recompute', 'on_create',
         * 'on_delete', 'on_restore'. Identifies which lifecycle hook
         * the exception came out of.
         */
        public string $stage,
        public Throwable $exception,
    ) {}
}
