<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by `TreeDiff::between()` when either side contains two rows
 * that resolve to the same identity value under the chosen `$on` key.
 *
 * The diff treats identity as a function from row to key — a collision
 * would silently overwrite one of the rows when building the lookup
 * maps, so the algorithm refuses to proceed.
 *
 * Extends InvalidArgumentException because the bad input is the
 * caller's responsibility: either pick a different `$on`, or
 * deduplicate the source before calling.
 */
final class DuplicateNodeIdentityException extends InvalidArgumentException implements NestedSetException {}
