<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use RuntimeException;

/**
 * Thrown by `TreeDiff::apply()` when the identity resolver returns
 * `null` for the destination parent of a `Moved` change. The row
 * exists in `before` but its target parent wasn't found in the live
 * database — concurrent deletion is the usual cause.
 *
 * Extends RuntimeException because the failure reflects an environment
 * mismatch at apply time, not a programmer error at the call site.
 */
final class MissingParentException extends RuntimeException {}
