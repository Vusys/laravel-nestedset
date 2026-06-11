<?php

declare(strict_types=1);

namespace Vusys\NestedSet\Exceptions;

use LogicException;

/**
 * Thrown by the dev-mode determinism guard when a segment builder
 * returns two different values for the same node within a single
 * save() call. Indicates the builder reaches for non-attribute state
 * — `request()`, `auth()`, locale, `now()`, etc. — which makes the
 * stored path unstable across re-renders, restorations, and
 * `fixMaterialisedPaths()` walks.
 *
 * Gated on `config('app.debug')`; never throws in production.
 *
 * Extends LogicException because non-deterministic builders are
 * programmer errors that surface as data corruption, not as caller
 * input we should tolerate at runtime.
 */
final class NonDeterministicPathSegmentException extends LogicException implements NestedSetException
{
    public function __construct(
        string $message,
        public readonly string $column = '',
        public readonly string $modelClass = '',
    ) {
        parent::__construct($message);
    }
}
