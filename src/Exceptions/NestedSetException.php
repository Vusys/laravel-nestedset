<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use Throwable;

/**
 * Marker interface implemented by every exception this package throws.
 *
 * Lets callers catch any package-originated failure with a single
 * `catch (NestedSetException $e)` regardless of which SPL base class
 * (LogicException, RuntimeException, InvalidArgumentException, …) the
 * concrete exception extends.
 */
interface NestedSetException extends Throwable {}
