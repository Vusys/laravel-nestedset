<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use LogicException;
use Vusys\NestedSet\Attributes\NestedSetAggregate;

/**
 * Thrown when an aggregate declaration is malformed or inconsistent.
 *
 * Examples that trigger this:
 *  - {@see NestedSetAggregate} declaring more than one of
 *    sum/count/avg/min/max in the same attribute instance.
 *  - {@see NestedSetAggregate} declaring none of them.
 *  - Two declarations targeting the same `column` on the same model.
 *  - `Aggregate::sum('tickets')->into('')` — empty target column.
 *
 * Extends LogicException because misdeclaration is a programmer error,
 * not a runtime/data problem; the registry resolves declarations once
 * at boot time so failures here surface immediately, not in production.
 */
final class AggregateConfigurationException extends LogicException {}
