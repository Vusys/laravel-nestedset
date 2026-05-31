<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by `fromJsonTree()` (and `TreeDiff::between()`'s JSON shim)
 * when the payload is structurally invalid — bad JSON, mixed nested
 * + flat shapes, cycles in the flat-form `parent_id` graph, or
 * unknown / missing columns in strict mode.
 *
 * Error messages carry a JSON pointer (e.g. `[0].children[2].name`)
 * so a user staring at a 10k-line payload knows where to look.
 *
 * Extends InvalidArgumentException because the malformed payload is
 * the caller's responsibility.
 */
final class InvalidJsonTreeException extends InvalidArgumentException {}
