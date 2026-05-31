<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use RuntimeException;

/**
 * Thrown when two siblings under the same parent produce the same
 * materialised-path segment, while the column is configured with
 * `uniquePerParent: true` (default).
 *
 * The segment-collision check uses byte-exact `strcmp` — callers who
 * want case-insensitive or locale-aware comparison lowercase / fold
 * inside the segment builder itself.
 */
final class DuplicatePathSegment extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $column = '',
        public readonly string $modelClass = '',
        public readonly string $segment = '',
        public readonly int|string|null $parentId = null,
    ) {
        parent::__construct($message);
    }
}
