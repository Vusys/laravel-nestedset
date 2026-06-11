<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use LogicException;

/**
 * Package-thrown {@see LogicException} (programmer error / invariant
 * violation) that also carries the {@see NestedSetException} marker, so
 * `catch (NestedSetException)` traps it while `catch (LogicException)`
 * still works.
 */
final class NestedSetLogicException extends LogicException implements NestedSetException {}
