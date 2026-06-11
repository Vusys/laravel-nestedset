<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use RuntimeException;

/**
 * Thrown by `fromJsonTree()` when `includeKeys = true` and one of the
 * payload's primary-key values already exists in the destination
 * table. The underlying unique-constraint violation is caught and
 * re-thrown with the offending key surfaced on the exception so
 * callers can decide whether to retry without it, remap, or skip.
 *
 * Extends RuntimeException because the collision is a runtime data
 * condition rather than a programmer error at the call site.
 */
final class JsonImportKeyCollisionException extends RuntimeException implements NestedSetException
{
    public function __construct(
        public readonly int|string $offendingKey,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
