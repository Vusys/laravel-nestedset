<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by `TreeDiff::between()` when an `Added` row references a
 * parent identity that is absent from both snapshots.
 *
 * A row that survives diffing but has no parent in either side means
 * the `after` snapshot is internally inconsistent — `apply()` couldn't
 * place the row even if it tried.
 *
 * Extends InvalidArgumentException because the malformed snapshot is
 * the caller's responsibility, not a runtime/data condition.
 */
final class DanglingParentException extends InvalidArgumentException implements NestedSetException {}
