<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use RuntimeException;

/**
 * Package-thrown {@see RuntimeException} that also carries the
 * {@see NestedSetException} marker, so `catch (NestedSetException)` traps
 * it while `catch (RuntimeException)` still works.
 */
final class NestedSetRuntimeException extends RuntimeException implements NestedSetException {}
