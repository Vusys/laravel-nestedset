<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use RuntimeException;

/**
 * Base class for materialised-path segment-validation failures.
 *
 * Thrown when a row's computed segment violates a per-column rule —
 * containing the configured separator (when `rejectSeparatorInSegment`
 * is on), exceeding maxLength after assembly, or colliding with a
 * sibling under the same parent (when `uniquePerParent` is on). The
 * column name and the model class are part of every concrete subtype's
 * message so multi-path failures are diagnosable at a glance.
 *
 * Extends RuntimeException because the offending value is data — a
 * malformed `name` or a too-long `display_name` — not a programmer
 * mistake in the declaration itself.
 */
class InvalidPathSegment extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $column = '',
        public readonly string $modelClass = '',
    ) {
        parent::__construct($message);
    }
}
