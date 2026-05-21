<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Events;

use Vusys\NestedSet\Exceptions\ScopeViolationException;

/**
 * Fires immediately before a {@see ScopeViolationException} is
 * thrown. The exception still propagates — this event is purely
 * for observability, in the same spirit as
 * {@see AggregateMaintenanceFailed}.
 *
 * Useful for audit / security signals: cross-scope writes on
 * multi-tenant trees are almost always a bug in caller code or
 * a permission boundary mistake. Distinguishing them from
 * generic exceptions in monitoring is valuable.
 *
 * Queue-safe.
 */
final readonly class ScopeViolationDetected
{
    public function __construct(
        public string $modelClass,
        /** One of 'mutation', 'repair', 'bulk_insert', 'queue_dispatch'. Describes the operation that detected the violation. */
        public string $stage,
        public string $message,
    ) {}
}
