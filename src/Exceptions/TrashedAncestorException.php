<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use LogicException;

/**
 * Thrown when `restore()` targets a node whose parent is still
 * soft-deleted.
 *
 * The restore cascade brings back the anchor and its same-stamp
 * descendants but never walks *up* the tree, so restoring a node while
 * an ancestor stays trashed would leave a live child parented under a
 * hidden one — the exact "live child under a trashed parent" state the
 * insert/factory path already refuses (see {@see TrashedTargetException}
 * and `docs/reference/factories.md`). Rejecting the partial restore keeps
 * that invariant total in both directions.
 *
 * Restore the parent (or `forceDelete()` it) before restoring the child.
 * Extends LogicException because restoring under a trashed ancestor is a
 * programmer error, not a runtime condition to recover from.
 */
final class TrashedAncestorException extends LogicException implements NestedSetException {}
