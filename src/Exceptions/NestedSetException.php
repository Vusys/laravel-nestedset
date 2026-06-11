<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

/**
 * Marker implemented by every exception the package throws.
 *
 * Lets callers write a single `catch (NestedSetException $e)` to trap any
 * failure originating in this library — the named exceptions in this
 * namespace and the internal invariant/validation throws (via the
 * {@see NestedSetLogicException} / {@see NestedSetInvalidArgumentException}
 * / {@see NestedSetRuntimeException} generics) alike. Each concrete class
 * still extends the matching SPL base (`LogicException` / `RuntimeException`
 * / `InvalidArgumentException`), so existing SPL-typed catches keep working.
 */
interface NestedSetException extends \Throwable {}
