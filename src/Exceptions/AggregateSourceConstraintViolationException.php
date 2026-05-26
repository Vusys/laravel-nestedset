<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use RuntimeException;

/**
 * Thrown when a model is saved with a source-column value that violates
 * the positivity / non-zero constraint of a geometric or harmonic mean
 * aggregate declared on the model.
 *
 * Default behaviour (loud-error mode): saves that write a non-positive
 * source value to a `geometricMean` aggregate or a zero source value to
 * a `harmonicMean` aggregate are rejected at `save()` time so the
 * companion `__sum_log` / `__sum_recip` column never receives undefined
 * input.
 *
 * To opt into silent-skip semantics (non-valid rows contribute 0 to the
 * companion sum), call `->allowNonPositive()` on the aggregate
 * declaration in the method-override form, or pass
 * `allowNonPositive: true` in the `#[NestedSetAggregate]` attribute.
 */
final class AggregateSourceConstraintViolationException extends RuntimeException {}
