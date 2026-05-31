<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use LogicException;

/**
 * Thrown by `TreeDiff::apply()` when the set of `Moved` changes
 * contains a cycle (e.g. A becomes a child of B while B becomes a
 * child of A).
 *
 * The diff itself never catches cycles — it's just data. `apply()`
 * runs a cycle check before issuing any statement so a corrupt move
 * plan never lands.
 *
 * Extends LogicException because the contradictory move plan is a
 * programmer error in whatever produced the `after` snapshot.
 */
final class CyclicMoveException extends LogicException {}
