<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

/**
 * Thrown when a segment builder returns an empty string.
 *
 * Empty segments would silently collapse `'/a//b/'` to `'/a/b/'`,
 * masking the underlying bug; rejection forces the source attribute or
 * builder to be fixed before the row is written.
 */
final class EmptyPathSegment extends InvalidPathSegment {}
