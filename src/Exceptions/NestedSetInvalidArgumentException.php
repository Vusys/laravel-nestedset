<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use InvalidArgumentException;

/**
 * Package-thrown {@see InvalidArgumentException} that also carries the
 * {@see NestedSetException} marker, so `catch (NestedSetException)` traps
 * it while `catch (InvalidArgumentException)` still works.
 */
final class NestedSetInvalidArgumentException extends InvalidArgumentException implements NestedSetException {}
