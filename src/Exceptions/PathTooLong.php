<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use RuntimeException;

/**
 * Thrown when a computed materialised-path string exceeds the column's
 * configured `maxLength`. Caught at assembly time so the row is never
 * written; the underlying VARCHAR / TEXT length is the user's
 * migration decision and is documented separately.
 */
final class PathTooLong extends RuntimeException implements NestedSetException
{
    public function __construct(
        string $message,
        public readonly string $column = '',
        public readonly string $modelClass = '',
        public readonly int $length = 0,
        public readonly int $maxLength = 0,
    ) {
        parent::__construct($message);
    }
}
