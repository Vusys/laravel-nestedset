<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use LogicException;
use Vusys\NestedSet\Attributes\NestedSetScope;

/**
 * Thrown when a write operation would cross tree boundaries on a model
 * that declares a scope (via {@see NestedSetScope}
 * or `getScopeAttributes()`).
 *
 * Examples that trigger this:
 *  - `$itemInMenu1->appendToNode($itemInMenu2)` — different scope values.
 *  - `MenuItem::fixTree()` — no anchor, so the scope column is ambiguous.
 *
 * Extends LogicException because the cross-scope move is a programmer
 * error, not a runtime/data problem.
 */
final class ScopeViolationException extends LogicException {}
