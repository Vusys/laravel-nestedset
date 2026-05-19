<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use LogicException;

/**
 * Thrown when a new (unsaved) NodeTrait model is saved without being
 * placed in the tree first via `appendToNode()`, `prependToNode()`,
 * `insertBeforeNode()`, `insertAfterNode()`, or `makeRoot()`.
 *
 * Reachable via:
 *  - `Category::create([...])` with no placement call.
 *  - `$clone = $node->replicate(); $clone->save();` (replicate clears
 *    the bounds, so the clone is unplaced until placed explicitly).
 *
 * Persisting an unplaced node would write `lft = rgt = 0`, producing an
 * `invalid_bounds` corruption. The guard fires in NodeTrait's `saving`
 * listener so the corrupt row never lands.
 *
 * Extends LogicException because forgetting to place a new node is a
 * programmer error.
 */
final class UnplacedNodeException extends LogicException {}
