<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use LogicException;

/**
 * Thrown when `cloneSubtreeTo()` is asked to clone a subtree into
 * itself or one of its own descendants (including the source node
 * itself — the degenerate case of `$source->cloneSubtreeTo($source)`).
 *
 * The destination would land inside the source's own bounds, so the
 * gap-open step would shift the source's rgt and the row-copy step
 * would either re-read shifted bounds or produce a self-referential
 * cycle. Caught upfront so the caller sees the intent error before
 * any DB writes happen.
 *
 * Extends LogicException because cloning a subtree into itself is a
 * programmer error.
 */
final class InvalidCloneTargetException extends LogicException {}
