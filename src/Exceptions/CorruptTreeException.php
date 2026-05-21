<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use RuntimeException;

/**
 * Thrown by the tree exporters when the loaded node set contains a
 * parent_id cycle — a node reachable from itself via the parent chain.
 *
 * Cycles can't be expressed in a well-formed nested set, so this only
 * surfaces when the underlying table is corrupt (manual SQL, restored
 * orphans, half-finished migrations). The exporters detect it eagerly
 * rather than recursing forever during the format walk.
 *
 * Extends RuntimeException because it reflects bad data, not a
 * programmer mistake at the call site.
 */
final class CorruptTreeException extends RuntimeException {}
