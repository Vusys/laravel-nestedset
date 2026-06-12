<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use LogicException;

/**
 * Thrown when a placement (`appendToNode` / `prependToNode` /
 * `insertBeforeNode` / `insertAfterNode`) targets a soft-deleted node.
 *
 * Soft-deleted nodes keep their `lft`/`rgt` slot so the subtree can be
 * restored to its original position. Placing a *live* node relative to a
 * trashed anchor would either parent it under a hidden node (append /
 * prepend) or position it against an invisible reference (before / after),
 * producing a live-descendant-of-trashed inconsistency that restore can
 * never reconcile (the live node carries no matching `deleted_at` stamp).
 *
 * Restore the target (or `forceDelete()` it) before placing relative to
 * it. Extends LogicException because acting against a trashed anchor is a
 * programmer error, not a runtime condition to recover from.
 */
final class TrashedTargetException extends LogicException implements NestedSetException {}
